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
    };
}

const BLOCK_PATTERNS =
    /access denied|you don'?t have permission to access|request blocked|you have been blocked|has been blocked|attention required|just a moment|checking your browser|unusual traffic|automated (queries|access)|pardon (our|the) interruption|please enable javascript and cookies/;
const CAPTCHA_PATTERNS = /verify (that )?you are (a )?human|are you a (robot|human)|captcha|prove you'?re not a robot|press (and|&) hold/;
const LOGIN_TEXT = /\b(sign in|log in|login)\b/;
const NEWSLETTER = /subscribe|newsletter|sign up for|get (the )?(latest|updates)|notify me|contact us|send (us )?a message|your message|enquiry|inquiry/;
const APPLICATION_WORDS = /\b(apply|application|applicant|candidate|resume|cv|curriculum)\b/;

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
export function classifyPage(signals, { status = null } = {}) {
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

    reasons.push(controls.length > 0 ? 'controls_without_strong_signal' : 'no_form_controls');

    return { kind: 'listing', reasons, stopReason: null, ats, onGreenhouseHost };
}
