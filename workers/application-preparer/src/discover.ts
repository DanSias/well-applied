// Read-only discovery of the form: fields, controls and blockers. Runs one
// self-contained function in the page. It tags each control with a
// data-wa-id attribute (the only DOM mutation) so later steps can address
// it without a free-form selector.
import type { Page } from 'playwright';
import type {
    CaptchaDetail,
    CaptchaState,
    DiscoveredField,
} from './contracts.ts';

export interface DiscoveredControl {
    text: string;
    type: string;
    role: 'submit' | 'navigation' | 'other';
    hidden: boolean;
}

export interface Discovery {
    fields: DiscoveredField[];
    /** For radio groups: the data-wa-opt index is the index into field.options. */
    controls: DiscoveredControl[];
    captcha: { state: CaptchaState; details: CaptchaDetail[] };
    passwordField: boolean;
    warnings: string[];
}

const MAX_OPTIONS = 500;

export async function discoverForm(page: Page): Promise<Discovery> {
    return page.evaluate((maxOptions) => {
        const clean = (s: string | null | undefined) =>
            (s ?? '').replace(/\s+/g, ' ').trim();
        const stripMarks = (s: string) => clean(s.replace(/[✱*]/g, ' '));
        const warnings: string[] = [];

        // Strict: also honours visibility:hidden and opacity:0 (the default
        // checkVisibility() does not), and rejects zero-size boxes.
        const visible = (el: Element) => {
            const anyEl = el as HTMLElement;
            if (typeof anyEl.checkVisibility === 'function')
                return anyEl.checkVisibility({
                    checkOpacity: true,
                    checkVisibilityCSS: true,
                });
            return el.getClientRects().length > 0;
        };

        const textWithout = (root: Element) => {
            const clone = root.cloneNode(true) as Element;
            clone
                .querySelectorAll(
                    'input, select, textarea, option, script, style',
                )
                .forEach((n) => n.remove());
            return clean(clone.textContent);
        };

        type Resolved = {
            label: string | null;
            source: string;
            marked: boolean;
        };
        const resolveLabel = (el: Element): Resolved => {
            const html = el as HTMLInputElement;
            if (html.id) {
                const forLabel = document.querySelector(
                    `label[for="${CSS.escape(html.id)}"]`,
                );
                if (forLabel) {
                    const raw = textWithout(forLabel);
                    return {
                        label: stripMarks(raw),
                        source: 'dom_label_for',
                        marked: /[✱*]/.test(raw),
                    };
                }
            }
            const wrap = el.closest('label');
            if (wrap) {
                const q = wrap.querySelector('.application-label');
                const raw = q ? clean(q.textContent) : textWithout(wrap);
                if (raw)
                    return {
                        label: stripMarks(raw),
                        source: 'dom_wrapping_label',
                        marked: /[✱*]/.test(raw),
                    };
            }
            const aria = el.getAttribute('aria-label');
            if (aria)
                return {
                    label: clean(aria),
                    source: 'dom_aria_label',
                    marked: false,
                };
            const by = el.getAttribute('aria-labelledby');
            if (by) {
                const text = by
                    .split(/\s+/)
                    .map((id) =>
                        clean(document.getElementById(id)?.textContent),
                    )
                    .filter(Boolean)
                    .join(' ');
                if (text)
                    return {
                        label: stripMarks(text),
                        source: 'dom_aria_labelledby',
                        marked: /[✱*]/.test(text),
                    };
            }
            const ph = html.getAttribute?.('placeholder');
            if (ph)
                return {
                    label: clean(ph),
                    source: 'dom_placeholder',
                    marked: false,
                };
            return { label: null, source: 'unresolved', marked: false };
        };

        // The question text for a radio group (the option's own label is just "Yes"/"No").
        const groupLabel = (radio: Element): Resolved => {
            const optionLabel = radio.closest('label');
            for (
                let a: Element | null = (optionLabel ?? radio).parentElement;
                a && a !== document.body;
                a = a.parentElement
            ) {
                const group = a.matches(
                    '[role="radiogroup"], [role="group"], fieldset',
                )
                    ? a
                    : null;
                if (group) {
                    const by = group.getAttribute('aria-labelledby');
                    if (by) {
                        const text = by
                            .split(/\s+/)
                            .map((id) =>
                                clean(document.getElementById(id)?.textContent),
                            )
                            .join(' ')
                            .trim();
                        if (text)
                            return {
                                label: stripMarks(text),
                                source: 'dom_aria_labelledby',
                                marked: /[✱*]/.test(text),
                            };
                    }
                    const aria = group.getAttribute('aria-label');
                    if (aria)
                        return {
                            label: clean(aria),
                            source: 'dom_aria_label',
                            marked: false,
                        };
                    const legend = group.querySelector(':scope > legend');
                    if (legend)
                        return {
                            label: stripMarks(clean(legend.textContent)),
                            source: 'dom_wrapping_label',
                            marked: /[✱*]/.test(legend.textContent ?? ''),
                        };
                }
                const q = a.querySelector(
                    ':scope > .application-label, :scope > label > .application-label',
                );
                if (q)
                    return {
                        label: stripMarks(clean(q.textContent)),
                        source: 'dom_wrapping_label',
                        marked: /[✱*]/.test(q.textContent ?? ''),
                    };
            }
            return { label: null, source: 'unresolved', marked: false };
        };

        // What a combobox-style control (react-select and the like) is displaying. A selected value
        // renders in its own element; an unselected one renders a placeholder instead. Only a
        // rendered selected value counts, and only for controls that declare combobox semantics.
        const readSelection = (
            el: HTMLInputElement,
        ): NonNullable<DiscoveredField['selection']> | null => {
            const haspopup = el.getAttribute('aria-haspopup');
            const combo =
                el.getAttribute('role') === 'combobox' ||
                (haspopup !== null && haspopup !== 'false') ||
                el.hasAttribute('aria-autocomplete');
            if (!combo) return null;
            let level: Element | null = el.parentElement;
            for (let depth = 0; level && depth < 4; depth++) {
                const single = level.querySelector(
                    '[class*="single-value" i], [class*="singlevalue" i]',
                );
                const placeholder = level.querySelector(
                    '[class*="placeholder" i]',
                );
                if (single || placeholder) {
                    const hiddenValue = [
                        ...level.querySelectorAll('input[type="hidden"]'),
                    ].some((h) => (h as HTMLInputElement).value !== '');
                    const text = single ? clean(single.textContent) : '';
                    const looksLikePlaceholder =
                        /^(select|choose|pick|search|type)\b/i.test(text);
                    if (
                        single &&
                        visible(single) &&
                        text !== '' &&
                        !looksLikePlaceholder
                    )
                        return {
                            state: 'selected',
                            text: text.slice(0, 80),
                            hiddenValue,
                        };
                    return {
                        state:
                            placeholder || looksLikePlaceholder
                                ? 'placeholder'
                                : 'none',
                        text: null,
                        hiddenValue,
                    };
                }
                level = level.parentElement;
            }
            return { state: 'none', text: null, hiddenValue: false };
        };

        // A file control's own label is often only the action on its button ("Attach"). The question
        // heading lives in the widget's container. Used only when exactly one file input shares that
        // container and exactly one distinct heading precedes it; otherwise the original label stands.
        const GENERIC_FILE_LABEL =
            /^(attach|upload|choose|browse|select|add)( a)?( file| files| document)?s?$|^(drop ?box|google drive|enter manually|attach file)$/i;
        const fileHeading = (el: Element): Resolved | null => {
            let a: Element | null = el.parentElement;
            for (
                let depth = 0;
                a && a !== document.body && depth < 4;
                depth++
            ) {
                if (a.tagName === 'FORM') break;
                if (a.querySelectorAll('input[type="file"]').length !== 1) {
                    return null; // another upload shares this container: do not guess which heading is whose
                }
                const own = (el as HTMLInputElement).id;
                const found = new Map<string, Resolved>();
                const add = (raw: string, source: string) => {
                    const text = stripMarks(raw);
                    if (
                        text !== '' &&
                        text.length <= 80 &&
                        !GENERIC_FILE_LABEL.test(text)
                    )
                        found.set(text.toLowerCase(), {
                            label: text,
                            source,
                            marked: /[✱*]/.test(raw),
                        });
                };
                const by = a.getAttribute('aria-labelledby');
                if (a.matches('[role="group"], fieldset') && by)
                    add(
                        by
                            .split(/\s+/)
                            .map((i) =>
                                clean(document.getElementById(i)?.textContent),
                            )
                            .join(' '),
                        'dom_aria_labelledby',
                    );
                if (found.size === 0) {
                    for (const h of a.querySelectorAll(
                        'legend, label, [role="heading"], h1, h2, h3, h4, h5, h6, [class*="label" i]',
                    )) {
                        if (h.contains(el)) continue;
                        if (
                            !(
                                h.compareDocumentPosition(el) &
                                Node.DOCUMENT_POSITION_FOLLOWING
                            )
                        )
                            continue;
                        if (
                            h.tagName === 'LABEL' &&
                            (h as HTMLLabelElement).htmlFor === own
                        )
                            continue;
                        add(textWithout(h), 'inferred_proximity');
                    }
                }
                if (found.size === 1) return [...found.values()][0]!;
                if (found.size > 1) return null;
                a = a.parentElement;
            }
            return null;
        };

        const elements = [
            ...document.querySelectorAll('input, select, textarea'),
        ] as Array<HTMLInputElement & HTMLSelectElement & HTMLTextAreaElement>;
        const fields: DiscoveredField[] = [];
        const radioGroups = new Map<string, DiscoveredField>();
        let position = 0;

        // Checkbox grouping: a shared non-empty name, else a common fieldset / role=group.
        // Never guessed from option text.
        const checkboxNames = new Map<string, number>();
        for (const cb of document.querySelectorAll(
            'input[type="checkbox"][name]',
        )) {
            const n = (cb as HTMLInputElement).name;
            if (n) checkboxNames.set(n, (checkboxNames.get(n) ?? 0) + 1);
        }
        const containerIds = new Map<Element, number>();
        const checkboxGroupKey = (el: HTMLInputElement): string | null => {
            if (el.name && (checkboxNames.get(el.name) ?? 0) >= 2)
                return `name:${el.name}`;
            const cont = el.closest(
                'fieldset, [role="group"], [role="radiogroup"]',
            );
            if (
                cont &&
                cont.querySelectorAll('input[type="checkbox"]').length >= 2
            ) {
                if (!containerIds.has(cont))
                    containerIds.set(cont, containerIds.size);
                return `container:${containerIds.get(cont)}`;
            }
            return null;
        };

        // Stable ids: an element that already carries a data-wa-id from an earlier
        // discovery keeps it, so ids never depend on position. New elements get
        // fresh ids from a counter that only grows, so the id of a removed element
        // is never handed to a different one. A duplicate (e.g. a cloned node that
        // copied the attribute) gets a new id instead of sharing one.
        const root = document.documentElement;
        let nextId = Number(root.getAttribute('data-wa-next') ?? '0');
        const usedIds = new Set<string>();
        const assignId = (el: Element): string => {
            const existing = el.getAttribute('data-wa-id');
            if (existing && !usedIds.has(existing)) {
                usedIds.add(existing);
                return existing;
            }
            let id: string;
            do id = `f${nextId++}`;
            while (usedIds.has(id));
            usedIds.add(id);
            el.setAttribute('data-wa-id', id);
            return id;
        };

        for (const el of elements) {
            const tag = el.tagName.toLowerCase();
            const type =
                tag === 'input' ? (el.type || 'text').toLowerCase() : null;
            if (
                type &&
                ['hidden', 'submit', 'button', 'image', 'reset'].includes(type)
            )
                continue;
            if (el.disabled) continue;
            if (type === 'radio' || type === 'checkbox') {
                // Custom-styled controls are often visually hidden inside a visible label.
                if (!visible(el) && !visible(el.closest('label') ?? el))
                    continue;
            } else if (type !== 'file' && !visible(el)) continue; // file inputs are routinely hidden by the widget

            if (type === 'radio') {
                const groupKey = el.name || `anon-${position}`;
                let field = radioGroups.get(groupKey);
                const optionText = el.closest('label')
                    ? textWithout(el.closest('label')!)
                    : clean(el.value);
                if (!field) {
                    const g = groupLabel(el);
                    field = {
                        id: assignId(el),
                        group: null,
                        checked: null,
                        selected: [],
                        autocomplete: null,
                        key: '',
                        occurrence: 0,
                        ambiguous: false,
                        position: position++,
                        kind: 'radio',
                        tag,
                        inputType: 'radio',
                        name: el.name || null,
                        domId: null,
                        label: g.label,
                        labelSource: g.source as DiscoveredField['labelSource'],
                        required:
                            el.required ||
                            el.getAttribute('aria-required') === 'true' ||
                            g.marked,
                        options: [],
                        sensitive: false,
                    };
                    radioGroups.set(groupKey, field);
                    fields.push(field);
                }
                usedIds.add(field.id);
                el.setAttribute('data-wa-id', field.id);
                el.setAttribute('data-wa-opt', String(field.options!.length));
                field.options!.push(optionText);
                if (el.checked) field.selected!.push(optionText);
                if (el.required) field.required = true;
                continue;
            }

            let resolved = resolveLabel(el);
            if (
                type === 'file' &&
                (resolved.label === null ||
                    GENERIC_FILE_LABEL.test(resolved.label))
            ) {
                const heading = fileHeading(el);
                if (heading) resolved = heading;
            }
            if (resolved.label === null && type !== 'file') {
                // No label tied to the control: fall back to the question container nearby. This is
                // weaker evidence and is recorded as such (inferred_proximity), never as a real label.
                const near = groupLabel(el);
                if (near.label)
                    resolved = {
                        label: near.label,
                        source: 'inferred_proximity',
                        marked: near.marked,
                    };
            }
            const id = assignId(el);
            let kind: DiscoveredField['kind'] = 'text';
            let options: string[] | null = null;
            if (tag === 'select') {
                kind = 'select';
                const all = [...el.options]
                    .filter((o) => o.value !== '')
                    .map((o) => clean(o.textContent));
                if (all.length > maxOptions)
                    warnings.push(
                        `field ${id}: ${all.length} options, only the first ${maxOptions} recorded`,
                    );
                options = all.slice(0, maxOptions);
            } else if (tag === 'textarea') kind = 'textarea';
            else if (type === 'checkbox') kind = 'checkbox';
            else if (type === 'file') kind = 'file';

            let group: DiscoveredField['group'] = null;
            if (type === 'checkbox') {
                const key = checkboxGroupKey(el);
                if (key !== null) {
                    const g = groupLabel(el);
                    group = {
                        key,
                        label: g.label,
                        labelSource: g.source as DiscoveredField['labelSource'],
                        marked: g.marked,
                    };
                }
            }
            const selected =
                tag === 'select'
                    ? [...el.selectedOptions]
                          .filter((o) => o.value !== '')
                          .map((o) => clean(o.textContent))
                    : null;

            fields.push({
                id,
                group,
                checked: type === 'checkbox' ? el.checked : null,
                selected,
                autocomplete:
                    el.getAttribute('autocomplete')?.toLowerCase().trim() ||
                    null,
                key: '',
                occurrence: 0,
                ambiguous: false,
                position: position++,
                kind,
                tag,
                inputType: type,
                name: el.getAttribute('name'),
                domId: el.id || null,
                label: resolved.label,
                labelSource: resolved.source as DiscoveredField['labelSource'],
                required:
                    el.required ||
                    el.getAttribute('aria-required') === 'true' ||
                    resolved.marked,
                options,
                sensitive: false,
                selection:
                    tag === 'input' && type !== 'file' && type !== 'checkbox'
                        ? readSelection(el)
                        : null,
            });
        }

        root.setAttribute('data-wa-next', String(nextId));

        // Logical identity: what distinguishes a field from the others, independent
        // of where it sits or which DOM node currently renders it. Identical keys are
        // numbered in document order and flagged ambiguous, so a caller never has to
        // guess which of several look-alikes it was tracking.
        const counts = new Map<string, number>();
        for (const f of fields) {
            f.key = [
                f.kind,
                f.inputType ?? '',
                f.name ?? '',
                f.domId ?? '',
                (f.label ?? '')
                    .replace(/[✱*]/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase(),
            ].join('|');
            f.occurrence = counts.get(f.key) ?? 0;
            counts.set(f.key, f.occurrence + 1);
        }
        for (const f of fields) f.ambiguous = (counts.get(f.key) ?? 0) > 1;

        const controls = [
            ...document.querySelectorAll(
                'button, input[type="submit"], input[type="button"], [role="button"], a.postings-btn',
            ),
        ].map((b) => {
            const el = b as HTMLButtonElement;
            const text = clean(
                b.tagName === 'INPUT'
                    ? (b as HTMLInputElement).value
                    : b.textContent,
            );
            const declared = (el.getAttribute('type') || '').toLowerCase();
            const inForm = !!el.closest('form');
            const type =
                declared ||
                (b.tagName === 'BUTTON' && inForm ? 'submit' : 'button');
            const navigation =
                /^(next|continue|save (and|&) continue|proceed)\b/i.test(text);
            const submit =
                !navigation &&
                (type === 'submit' || /\b(submit|apply)\b/i.test(text));
            return {
                text,
                type,
                role: (navigation
                    ? 'navigation'
                    : submit
                      ? 'submit'
                      : 'other') as 'submit' | 'navigation' | 'other',
                // Reported even when hidden: a hidden type=submit is exactly what a page
                // script clicks to submit. The worker never activates any control.
                hidden: !visible(b),
            };
        });

        // CAPTCHA: three states, never a bare "present". Markup that is
        // hidden, zero-sized, off-screen, aria-hidden or declared invisible
        // is inactive (a normal page initialising hCaptcha/reCAPTCHA). Only
        // an actually displayed challenge or checkbox needs a human.
        const strictVisible = visible;
        const describeCaptcha = (el: Element) => {
            const r = el.getBoundingClientRect();
            const w = Math.round(r.width);
            const h = Math.round(r.height);
            const top = r.top + window.scrollY;
            const left = r.left + window.scrollX;
            const title = clean(el.getAttribute('title'));
            const src = el.getAttribute('src') ?? '';
            let srcHost = '';
            let srcPath = '';
            let sizeParam: string | null = null;
            try {
                const u = new URL(src, location.href);
                srcHost = u.host;
                srcPath = u.pathname;
                sizeParam = u.searchParams.get('size');
            } catch {
                srcHost = '';
            }
            const classes = el.className?.toString?.() ?? '';
            const haystack = `${title} ${src} ${classes}`;
            const isFrame = el.tagName === 'IFRAME';
            const inBadge = !!el.closest('.grecaptcha-badge');

            // reCAPTCHA frames, by what Google's own URL says they are:
            //  - anchor with size=invisible (or sitting in the badge container): the passive badge icon;
            //  - anchor with size=normal/compact/absent: the "I'm not a robot" checkbox (interactive);
            //  - bframe: the challenge itself.
            const recaptchaAnchor =
                isFrame &&
                /\/recaptcha\/(api2|enterprise)\/anchor$/.test(srcPath);
            const recaptchaChallenge =
                isFrame &&
                /\/recaptcha\/(api2|enterprise)\/bframe$/.test(srcPath);
            const passiveBadgeFrame =
                recaptchaAnchor &&
                (sizeParam === 'invisible' || (inBadge && sizeParam === null));

            const role: CaptchaDetail['role'] =
                /grecaptcha-badge/.test(classes) || passiveBadgeFrame
                    ? 'badge'
                    : // Checkbox first: hCaptcha's checkbox title also says "security challenge".
                      isFrame &&
                        (recaptchaAnchor ||
                            /checkbox|widget containing/i.test(title))
                      ? 'checkbox_widget'
                      : isFrame &&
                          (recaptchaChallenge ||
                              /main content|bframe|challenge/i.test(haystack))
                        ? 'challenge_frame'
                        : isFrame
                          ? 'frame_other'
                          : 'container';
            const declaredInvisible =
                el.getAttribute('data-size') === 'invisible' ||
                !!el.closest('[data-size="invisible"]') ||
                /invisible/i.test(classes);
            const signals: string[] = [];
            if (!strictVisible(el)) signals.push('not_rendered_visible');
            if (w < 1 || h < 1) signals.push('zero_size');
            if (left + w <= 0 || top + h <= 0) signals.push('offscreen');
            if (el.closest('[aria-hidden="true"]')) signals.push('aria_hidden');
            if (declaredInvisible) signals.push('declared_invisible');
            if (role === 'badge') {
                // A passive badge is a small icon. A "badge" that is big and displayed is not
                // assumed harmless: it is reported as uncertain instead.
                if (
                    strictVisible(el) &&
                    passiveBadgeFrame &&
                    (w > 320 || h > 100)
                )
                    signals.push('oversized_badge');
                else signals.push('passive_badge');
            }
            const inactive =
                (role === 'badge' && !signals.includes('oversized_badge')) ||
                signals.some((x) =>
                    [
                        'not_rendered_visible',
                        'zero_size',
                        'offscreen',
                        'aria_hidden',
                        'declared_invisible',
                    ].includes(x),
                );
            let verdict: 'inactive' | 'challenge' | 'uncertain';
            if (signals.includes('oversized_badge')) verdict = 'uncertain';
            else if (inactive) verdict = 'inactive';
            else if (role === 'challenge_frame')
                verdict = w >= 200 && h >= 200 ? 'challenge' : 'uncertain';
            else if (role === 'checkbox_widget')
                verdict = w >= 100 && h >= 40 ? 'challenge' : 'uncertain';
            else if (role === 'container')
                // A displayed container is judged by the frames inside it; with none, we cannot tell.
                verdict = el.querySelector('iframe')
                    ? 'inactive'
                    : w >= 100 && h >= 40
                      ? 'uncertain'
                      : 'inactive';
            else verdict = 'uncertain';
            return {
                tag: el.tagName.toLowerCase(),
                role,
                title: title.slice(0, 80),
                srcHost,
                srcPath: srcPath.slice(0, 80),
                sizeParam,
                inBadge,
                width: w,
                height: h,
                signals,
                verdict,
            };
        };
        const captchaDetails = [
            ...document.querySelectorAll(
                'iframe, .g-recaptcha, .h-captcha, [data-sitekey], [data-hcaptcha-widget-id], .grecaptcha-badge',
            ),
        ]
            .filter(
                (el) =>
                    el.tagName !== 'IFRAME' ||
                    /captcha/i.test(
                        `${el.getAttribute('src') ?? ''} ${el.getAttribute('title') ?? ''}`,
                    ),
            )
            .map(describeCaptcha);
        const captchaState:
            | 'none'
            | 'inactive_markup'
            | 'challenge_visible'
            | 'uncertain' = captchaDetails.some(
            (d) => d.verdict === 'challenge',
        )
            ? 'challenge_visible'
            : captchaDetails.some((d) => d.verdict === 'uncertain')
              ? 'uncertain'
              : captchaDetails.length > 0
                ? 'inactive_markup'
                : 'none';
        const passwordField = [
            ...document.querySelectorAll('input[type="password"]'),
        ].some((p) => visible(p));

        return {
            fields,
            controls,
            captcha: { state: captchaState, details: captchaDetails },
            passwordField,
            warnings,
        };
    }, MAX_OPTIONS);
}
