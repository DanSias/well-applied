// Read-only discovery of the form: fields, controls and blockers. Runs one
// self-contained function in the page. It tags each control with a
// data-wa-id attribute (the only DOM mutation) so later steps can address
// it without a free-form selector.
import type { Page } from 'playwright';
import type { DiscoveredField } from './contracts.ts';

export interface DiscoveredControl {
    text: string;
    type: string;
    role: 'submit' | 'navigation' | 'other';
}

export interface Discovery {
    fields: DiscoveredField[];
    /** For radio groups: the data-wa-opt index is the index into field.options. */
    controls: DiscoveredControl[];
    captchaVisible: boolean;
    captchaInvisible: boolean;
    passwordField: boolean;
    warnings: string[];
}

const MAX_OPTIONS = 500;

export async function discoverForm(page: Page): Promise<Discovery> {
    return page.evaluate((maxOptions) => {
        const clean = (s: string | null | undefined) => (s ?? '').replace(/\s+/g, ' ').trim();
        const stripMarks = (s: string) => clean(s.replace(/[✱*]/g, ' '));
        const warnings: string[] = [];

        const visible = (el: Element) => {
            const anyEl = el as HTMLElement;
            if (typeof anyEl.checkVisibility === 'function') return anyEl.checkVisibility();
            return el.getClientRects().length > 0;
        };

        const textWithout = (root: Element) => {
            const clone = root.cloneNode(true) as Element;
            clone.querySelectorAll('input, select, textarea, option, script, style').forEach((n) => n.remove());
            return clean(clone.textContent);
        };

        type Resolved = { label: string | null; source: string; marked: boolean };
        const resolveLabel = (el: Element): Resolved => {
            const html = el as HTMLInputElement;
            if (html.id) {
                const forLabel = document.querySelector(`label[for="${CSS.escape(html.id)}"]`);
                if (forLabel) {
                    const raw = textWithout(forLabel);
                    return { label: stripMarks(raw), source: 'dom_label_for', marked: /[✱*]/.test(raw) };
                }
            }
            const wrap = el.closest('label');
            if (wrap) {
                const q = wrap.querySelector('.application-label');
                const raw = q ? clean(q.textContent) : textWithout(wrap);
                if (raw) return { label: stripMarks(raw), source: 'dom_wrapping_label', marked: /[✱*]/.test(raw) };
            }
            const aria = el.getAttribute('aria-label');
            if (aria) return { label: clean(aria), source: 'dom_aria_label', marked: false };
            const by = el.getAttribute('aria-labelledby');
            if (by) {
                const text = by.split(/\s+/).map((id) => clean(document.getElementById(id)?.textContent)).filter(Boolean).join(' ');
                if (text) return { label: stripMarks(text), source: 'dom_aria_labelledby', marked: /[✱*]/.test(text) };
            }
            const ph = html.getAttribute?.('placeholder');
            if (ph) return { label: clean(ph), source: 'dom_placeholder', marked: false };
            return { label: null, source: 'unresolved', marked: false };
        };

        // The question text for a radio group (the option's own label is just "Yes"/"No").
        const groupLabel = (radio: Element): Resolved => {
            const optionLabel = radio.closest('label');
            for (let a: Element | null = (optionLabel ?? radio).parentElement; a && a !== document.body; a = a.parentElement) {
                const group = a.matches('[role="radiogroup"], [role="group"], fieldset') ? a : null;
                if (group) {
                    const by = group.getAttribute('aria-labelledby');
                    if (by) {
                        const text = by.split(/\s+/).map((id) => clean(document.getElementById(id)?.textContent)).join(' ').trim();
                        if (text) return { label: stripMarks(text), source: 'dom_aria_labelledby', marked: /[✱*]/.test(text) };
                    }
                    const aria = group.getAttribute('aria-label');
                    if (aria) return { label: clean(aria), source: 'dom_aria_label', marked: false };
                    const legend = group.querySelector(':scope > legend');
                    if (legend) return { label: stripMarks(clean(legend.textContent)), source: 'dom_wrapping_label', marked: /[✱*]/.test(legend.textContent ?? '') };
                }
                const q = a.querySelector(':scope > .application-label, :scope > label > .application-label');
                if (q) return { label: stripMarks(clean(q.textContent)), source: 'dom_wrapping_label', marked: /[✱*]/.test(q.textContent ?? '') };
            }
            return { label: null, source: 'unresolved', marked: false };
        };

        const elements = [...document.querySelectorAll('input, select, textarea')] as Array<HTMLInputElement & HTMLSelectElement & HTMLTextAreaElement>;
        const fields: DiscoveredField[] = [];
        const radioGroups = new Map<string, DiscoveredField>();
        let position = 0;

        for (const el of elements) {
            const tag = el.tagName.toLowerCase();
            const type = tag === 'input' ? (el.type || 'text').toLowerCase() : null;
            if (type && ['hidden', 'submit', 'button', 'image', 'reset'].includes(type)) continue;
            if (el.disabled) continue;
            if (type === 'radio' || type === 'checkbox') {
                // Custom-styled controls are often visually hidden inside a visible label.
                if (!visible(el) && !visible(el.closest('label') ?? el)) continue;
            } else if (type !== 'file' && !visible(el)) continue; // file inputs are routinely hidden by the widget

            if (type === 'radio') {
                const groupKey = el.name || `anon-${position}`;
                let field = radioGroups.get(groupKey);
                const optionText = el.closest('label') ? textWithout(el.closest('label')!) : clean(el.value);
                if (!field) {
                    const g = groupLabel(el);
                    field = {
                        id: `f${fields.length}`, position: position++, kind: 'radio', tag, inputType: 'radio',
                        name: el.name || null, domId: null, label: g.label, labelSource: g.source as DiscoveredField['labelSource'],
                        required: el.required || el.getAttribute('aria-required') === 'true' || g.marked, options: [], sensitive: false,
                    };
                    radioGroups.set(groupKey, field);
                    fields.push(field);
                }
                el.setAttribute('data-wa-id', field.id);
                el.setAttribute('data-wa-opt', String(field.options!.length));
                field.options!.push(optionText);
                if (el.required) field.required = true;
                continue;
            }

            const resolved = resolveLabel(el);
            const id = `f${fields.length}`;
            el.setAttribute('data-wa-id', id);
            let kind: DiscoveredField['kind'] = 'text';
            let options: string[] | null = null;
            if (tag === 'select') {
                kind = 'select';
                const all = [...el.options].filter((o) => o.value !== '').map((o) => clean(o.textContent));
                if (all.length > maxOptions) warnings.push(`field ${id}: ${all.length} options, only the first ${maxOptions} recorded`);
                options = all.slice(0, maxOptions);
            } else if (tag === 'textarea') kind = 'textarea';
            else if (type === 'checkbox') kind = 'checkbox';
            else if (type === 'file') kind = 'file';

            fields.push({
                id, position: position++, kind, tag, inputType: type, name: el.getAttribute('name'), domId: el.id || null,
                label: resolved.label, labelSource: resolved.source as DiscoveredField['labelSource'],
                required: el.required || el.getAttribute('aria-required') === 'true' || resolved.marked,
                options, sensitive: false,
            });
        }

        const controls = [...document.querySelectorAll('button, input[type="submit"], input[type="button"], [role="button"], a.postings-btn')]
            .filter((b) => visible(b))
            .map((b) => {
                const el = b as HTMLButtonElement;
                const text = clean(b.tagName === 'INPUT' ? (b as HTMLInputElement).value : b.textContent);
                const declared = (el.getAttribute('type') || '').toLowerCase();
                const inForm = !!el.closest('form');
                const type = declared || (b.tagName === 'BUTTON' && inForm ? 'submit' : 'button');
                const navigation = /^(next|continue|save (and|&) continue|proceed)\b/i.test(text);
                const submit = !navigation && (type === 'submit' || /\b(submit|apply)\b/i.test(text));
                return { text, type, role: (navigation ? 'navigation' : submit ? 'submit' : 'other') as 'submit' | 'navigation' | 'other' };
            });

        const captchaEls = [...document.querySelectorAll('iframe[src*="recaptcha"], iframe[src*="hcaptcha"], iframe[title*="captcha" i], .g-recaptcha, .h-captcha, [data-sitekey]')];
        const bigCaptcha = captchaEls.some((c) => {
            if (!visible(c)) return false;
            const r = c.getBoundingClientRect();
            return r.width >= 100 && r.height >= 40;
        });
        const passwordField = [...document.querySelectorAll('input[type="password"]')].some((p) => visible(p));

        return { fields, controls, captchaVisible: bigCaptcha, captchaInvisible: captchaEls.length > 0 && !bigCaptcha, passwordField, warnings };
    }, MAX_OPTIONS);
}
