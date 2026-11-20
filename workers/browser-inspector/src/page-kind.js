// What kind of page is this? Deterministic, evidence-based, and conservative: it only says a page IS an application form
// on a strong signal, and a weak one (a lone text box, the word "apply", a search field) is never promoted into
// certainty. Two halves, so the rules can be tested without a browser:
//
//  - collectSignals(): runs INSIDE the page and returns plain facts — visible controls, their labels, a short sample of
//    visible text, captcha frames, ATS indicators. No decisions, nothing clicked, nothing typed.
//  - classifyPage(): a pure function from those facts (and the response status) to a kind and the reasons for it.
//
// Kinds: 'form' | 'blocked' | 'login' | 'unreachable' | 'listing'. Anything that is none of the first four is a
// 'listing' — a page that is not an application form, from which an Apply link may lead to one.

import { APPLICATION_WORDS, assessApplicationForm, exclusionsFor } from './page-context.js';

/**
 * Runs in the page (via page.evaluate). Plain browser JS: no Node or Playwright access.
 */
export function collectSignals() {
    const clean = (s) => (s ?? '').replace(/\s+/g, ' ').trim();

    function visible(el) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
    }

    function describe(el) {
        const id = el.id;
        const byFor = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`) : null;
        const wrap = el.closest('label');
        const labelledby = (el.getAttribute('aria-labelledby') || '')
            .split(/\s+/)
            .map((i) => document.getElementById(i)?.textContent)
            .filter(Boolean)
            .join(' ');
        return clean(
            [byFor?.textContent, wrap?.textContent, el.getAttribute('aria-label'), labelledby, el.getAttribute('placeholder')]
                .filter(Boolean)
                .join(' '),
        ).slice(0, 160);
    }

    const forms = Array.from(document.forms);
    const controlSelector = [
        'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="image"]):not([type="reset"])',
        'textarea',
        'select',
        '[role="combobox"]',
        '[role="textbox"]',
    ].join(',');

    const controls = Array.from(document.querySelectorAll(controlSelector))
        .filter(visible)
        .slice(0, 80)
        .map((el) => {
            const form = el.form || el.closest('form');
            const type = (el.getAttribute('type') || el.getAttribute('role') || el.tagName).toLowerCase();
            const name = el.getAttribute('name') || '';

            return {
                type,
                name,
                id: el.id || '',
                autocomplete: (el.getAttribute('autocomplete') || '').toLowerCase(),
                accept: el.getAttribute('accept') || '',
                label: describe(el),
                inSearch:
                    type === 'search' ||
                    !!el.closest('[role="search"]') ||
                    /^(q|s|query|search|keywords?)$/i.test(name),
                form: form ? forms.indexOf(form) : -1,
            };
        });

    const formFacts = forms.map((f) => ({
        submit: clean(
            Array.from(f.querySelectorAll('button, input[type="submit"]'))
                .map((b) => b.textContent || b.getAttribute('value') || '')
                .join(' '),
        ).slice(0, 120),
        text: clean(f.innerText).slice(0, 300).toLowerCase(),
    }));

    // ---- additive facts (used only by the context-aware recognition; nothing above depends on them) ----
    const takenControls = new Set(Array.from(document.querySelectorAll(controlSelector)));
    function groupLabelOf(el) {
        const g = el.closest('[role="radiogroup"], [role="group"], fieldset');
        if (!g) return { group: null, label: '' };
        if (g.tagName === 'FIELDSET') {
            const legend = g.querySelector('legend');
            return { group: g, label: legend ? clean(legend.textContent) : '' };
        }
        const by = (g.getAttribute('aria-labelledby') || '')
            .split(/\s+/)
            .map((id) => document.getElementById(id)?.textContent)
            .filter(Boolean)
            .join(' ');
        return { group: g, label: clean(by || g.getAttribute('aria-label') || '') };
    }
    const customSeen = new Set();
    const customControls = [];
    const customSelector = ['[role="radio"]', '[role="checkbox"]', '[role="listbox"]', '[role="switch"]', 'button[aria-haspopup="listbox"]', '[role="button"][aria-haspopup="listbox"]'].join(',');
    for (const el of Array.from(document.querySelectorAll(customSelector)).slice(0, 120)) {
        if (takenControls.has(el) || !visible(el)) continue;
        const role = el.getAttribute('role') || 'listbox_button';
        const { group, label: groupLabel } = groupLabelOf(el);
        // Radios and checkboxes of one group are ONE question.
        if ((role === 'radio' || role === 'checkbox') && group) {
            if (customSeen.has(group)) continue;
            customSeen.add(group);
        }
        const own = describe(el);
        const form = el.closest('form');
        customControls.push({
            type: role,
            name: '',
            id: el.id || '',
            autocomplete: '',
            accept: '',
            label: (role === 'radio' || role === 'checkbox') && group ? groupLabel || own : own || groupLabel,
            inSearch: !!el.closest('[role="search"]'),
            form: form ? forms.indexOf(form) : -1,
        });
        if (customControls.length >= 60) break;
    }
    const buttons = Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"], input[type="button"]'))
        .filter(visible)
        .slice(0, 40)
        .map((b) => ({ text: clean(b.innerText || b.getAttribute('value') || b.getAttribute('aria-label') || '').slice(0, 80), inForm: !!b.closest('form') }));
    const sections = Array.from(document.querySelectorAll('legend, h2, h3, h4, [role="heading"]'))
        .filter(visible)
        .slice(0, 40)
        .map((h) => clean(h.textContent).slice(0, 100));

    const frames = Array.from(document.querySelectorAll('iframe'));
    const challengeFrame = frames.some((f) => {
        const src = (f.getAttribute('src') || '').toLowerCase();
        if (!/recaptcha\/api2\/bframe|hcaptcha\.com\/captcha|challenges\.cloudflare\.com|turnstile|captcha-delivery|px-captcha/.test(src))
            return false;
        const r = f.getBoundingClientRect();
        return visible(f) && r.width >= 200 && r.height >= 100;
    });

    const assets = Array.from(document.querySelectorAll('script[src], link[href], iframe[src]'))
        .map((el) => (el.getAttribute('src') || el.getAttribute('href') || '').toLowerCase())
        .join(' ');

    return {
        title: document.title || '',
        host: location.hostname.toLowerCase(),
        path: location.pathname,
        text: clean(document.body?.innerText).slice(0, 5000).toLowerCase(),
        headings: Array.from(document.querySelectorAll('h1, h2'))
            .slice(0, 6)
            .map((h) => clean(h.innerText).toLowerCase()),
        controls,
        forms: formFacts,
        captchaChallengeVisible: challengeFrame,
        greenhouseAssets: /greenhouse\.io/.test(assets),
        leverAssets: /lever\.co/.test(assets),
        customControls,
        buttons,
        sections,
        searchRole: !!document.querySelector('[role="search"]'),
    };
}

const BLOCK_PATTERNS =
    /access denied|you don'?t have permission to access|request blocked|you have been blocked|has been blocked|attention required|just a moment|checking your browser|unusual traffic|automated (queries|access)|pardon (our|the) interruption|please enable javascript and cookies/;
const CAPTCHA_PATTERNS = /verify (that )?you are (a )?human|are you a (robot|human)|captcha|prove you'?re not a robot|press (and|&) hold/;
const LOGIN_TEXT = /\b(sign in|log in|login)\b/;
const NEWSLETTER = /subscribe|newsletter|sign up for|get (the )?(latest|updates)|notify me|contact us|send (us )?a message|your message|enquiry|inquiry/;

const RESUME_FIELD = /resume|\bcv\b|curriculum|cover[ _-]?letter/i;
const EMAIL_FIELD = /e-?mail/i;
const NAME_FIELD = /\b(first|last|full|given|family|legal)?[ _-]?name\b/i;

export function hostMatches(host, domain) {
    return host === domain || host.endsWith(`.${domain}`);
}

/**
 * @param {ReturnType<typeof collectSignals>} signals
 * @param {{status?: number | null}} [response]
 * @returns {{kind: 'form'|'blocked'|'login'|'unreachable'|'listing', reasons: string[], stopReason: string|null, ats: 'greenhouse'|'lever'|null, onGreenhouseHost: boolean}}
 */
export function classifyPage(signals, { status = null, context = 'none', extended = false } = {}) {
    const controls = signals.controls.filter((c) => !c.inSearch);
    const reasons = [];
    const onGreenhouseHost = hostMatches(signals.host, 'greenhouse.io');
    const onLeverHost = hostMatches(signals.host, 'lever.co');
    const ats = onGreenhouseHost || signals.greenhouseAssets ? 'greenhouse' : onLeverHost ? 'lever' : null;

    const descriptor = (c) => `${c.label} ${c.name} ${c.id} ${c.accept}`;

    // ---- strong signals: any ONE of these establishes an application form ----
    const strong = [];

    if (controls.some((c) => c.type === 'file' && RESUME_FIELD.test(descriptor(c)))) strong.push('resume_upload');

    const formsWithControls = new Map();
    for (const c of controls) formsWithControls.set(c.form, [...(formsWithControls.get(c.form) ?? []), c]);
    for (const [index, group] of formsWithControls) {
        const hasEmail = group.some((c) => c.type === 'email' || c.autocomplete === 'email' || EMAIL_FIELD.test(descriptor(c)));
        const hasName = group.some((c) => ['given-name', 'family-name', 'name'].includes(c.autocomplete) || NAME_FIELD.test(descriptor(c)));
        const facts = index >= 0 ? signals.forms[index] : null;
        const looksLikeNewsletter = NEWSLETTER.test(`${facts?.submit ?? ''} ${facts?.text ?? ''}`.toLowerCase());

        if (hasEmail && hasName && !looksLikeNewsletter && APPLICATION_WORDS.test(`${signals.title.toLowerCase()} ${signals.headings.join(' ')} ${signals.text}`)) {
            strong.push('contact_fields_on_application_page');
            break;
        }
    }

    if (controls.length > 0 && onGreenhouseHost) strong.push('greenhouse_form');
    // A Greenhouse job page (or its embedded form) is an application form by its own structure even if its fields have
    // not rendered: that is "reached a form, extracted nothing", not "no application".
    if (onGreenhouseHost && /\/jobs?\/\d+|\/embed\/job_app/.test(signals.path)) strong.push('greenhouse_job_page');
    if (controls.length > 0 && onLeverHost && /\/apply\/?$/.test(signals.path)) strong.push('lever_form');

    // With the context-aware extension on, the one WEAK existing signal (a name and an email on a page that mentions applying) no
    // longer counts when the page is plainly a sign-up, contact, search or consent form. The strong signals (a resume upload,
    // a Greenhouse or Lever form) are never touched.
    if (extended && strong.length === 1 && strong[0] === 'contact_fields_on_application_page') {
        const notApplication = exclusionsFor(signals).filter((e) => e !== 'password_field');
        if (notApplication.length > 0) {
            strong.length = 0;
            reasons.push(`not_an_application:${notApplication.join('+')}`);
        }
    }

    // ---- status ----
    if (status === 403 || status === 429) {
        if (strong.length === 0) {
            const captcha = signals.captchaChallengeVisible || CAPTCHA_PATTERNS.test(signals.text);

            return { kind: 'blocked', reasons: [`http_${status}`], stopReason: captcha ? 'captcha' : 'anti_bot', ats, onGreenhouseHost };
        }
    } else if (status !== null && status >= 400) {
        return { kind: 'unreachable', reasons: [`http_${status}`], stopReason: 'destination_unreachable', ats, onGreenhouseHost };
    }

    // A challenge the person would have to solve beats everything but a form that is already usable.
    if (signals.captchaChallengeVisible && strong.length === 0) {
        return { kind: 'blocked', reasons: ['captcha_challenge_visible'], stopReason: 'captcha', ats, onGreenhouseHost };
    }

    if (strong.length > 0) {
        return { kind: 'form', reasons: strong, stopReason: null, ats, onGreenhouseHost };
    }

    // ---- barriers, only when no form is present ----
    const haystack = `${signals.title.toLowerCase()} ${signals.headings.join(' ')} ${signals.text.slice(0, 1500)}`;

    if (BLOCK_PATTERNS.test(haystack) && controls.length === 0 && signals.text.length < 1500) {
        return { kind: 'blocked', reasons: ['block_page_text'], stopReason: CAPTCHA_PATTERNS.test(haystack) ? 'captcha' : 'anti_bot', ats, onGreenhouseHost };
    }

    if (CAPTCHA_PATTERNS.test(haystack) && controls.length === 0 && signals.text.length < 1500) {
        return { kind: 'blocked', reasons: ['captcha_page_text'], stopReason: 'captcha', ats, onGreenhouseHost };
    }

    const hasPassword = controls.some((c) => c.type === 'password');
    const others = controls.filter((c) => c.type !== 'password');

    if (hasPassword && others.length <= 2) {
        return { kind: 'login', reasons: ['password_field'], stopReason: 'login_wall', ats, onGreenhouseHost };
    }

    if (controls.length > 0 && controls.length <= 2 && LOGIN_TEXT.test(signals.headings.join(' ') + ' ' + signals.title.toLowerCase())) {
        return { kind: 'login', reasons: ['sign_in_page'], stopReason: 'login_wall', ats, onGreenhouseHost };
    }

    // ---- context-aware recognition (generic discovery only) ----
    // Reached only when nothing above matched, so a form the existing rules recognise, and every barrier (blocked, CAPTCHA,
    // sign-in), has already been decided and cannot be overridden here.
    if (extended) {
        const found = assessApplicationForm(signals, { context });

        // A password form with more fields than the existing sign-in rule covers is still a sign-in: an authentication barrier,
        // never an application.
        if (found.excluded.includes('password_field')) {
            return { kind: 'login', reasons: ['password_field', 'sign_in_form'], stopReason: 'login_wall', ats, onGreenhouseHost };
        }

        if (found.recognised && found.barrier) {
            // A CAPTCHA or block notice on a page that would otherwise be read: the barrier wins.
            return { kind: 'blocked', reasons: ['barrier_text_on_form_page'], stopReason: 'captcha', ats, onGreenhouseHost };
        }
        if (found.recognised) {
            return { kind: 'form', reasons: ['context_aware_form', `basis:${found.basis}`, ...found.evidence], stopReason: null, ats, onGreenhouseHost, inferred: found.basis };
        }
        if (found.excluded.length > 0 && found.evidence.length > 0) reasons.push(`not_an_application:${found.excluded.join('+')}`);
    }

    reasons.push(controls.length > 0 ? 'controls_without_strong_signal' : 'no_form_controls');

    return { kind: 'listing', reasons, stopReason: null, ats, onGreenhouseHost };
}
