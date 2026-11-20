// Is this page an APPLICATION FORM, judged from evidence beyond the fields it happens to have? Pure and deterministic: no DOM,
// no browser, no clock. Used only when generic discovery is on (BROWSER_WORKER_GENERIC_DISCOVERY=1), and only as the LAST step of
// page classification: every barrier (blocked, CAPTCHA, sign-in) has already been checked and wins over this.
//
// The existing classifier recognises a form only on a strong signal (a resume field, or a name and an email on a page that talks
// about applying). That is right for an unknown page, and wrong for an application made of dropdowns, conditional questions,
// a demographic step or custom widgets. What this adds is CONTEXT and CONTENT:
//
//   context  how the worker came to be on this page. A URL a person supplied, a destination reached through an identified Apply
//            link, one an earlier inspection resolved, or a provider's application link, all say "this is meant to be the
//            application". A URL found on a job board listing says nothing.
//   content  an explicit application submit button, application wording, a voluntary self-identification section.
//
// An arbitrary page that merely contains form controls is never enough. Nor is context on its own when the page looks like
// a search, a sign-up, a contact form, a cookie banner or any other ordinary form: exclusions apply to every context.
import { SENSITIVE_SECTION } from './discovery/lexicon.js';

export const Context = Object.freeze({ None: 'none', UserSupplied: 'user_supplied', ApplyLink: 'apply_link', Resolved: 'resolved', Provider: 'provider' });
const STRONG_CONTEXT = new Set([Context.UserSupplied, Context.ApplyLink, Context.Resolved, Context.Provider]);

/** How the worker reached this page: a followed Apply link, or (for the first page) where the claim says the URL came from. */
export function contextFor({ hop, startOrigin }) {
    if (hop > 0) return Context.ApplyLink;

    return { user: Context.UserSupplied, resolved: Context.Resolved, provider: Context.Provider }[startOrigin] ?? Context.None;
}
export const isStrongContext = (context) => STRONG_CONTEXT.has(context);

// Same wording the existing classifier uses for "this page talks about applying".
export const APPLICATION_WORDS = /\b(apply|application|applicant|candidate|resume|cv|curriculum)\b/;

// ---- evidence -------------------------------------------------------------------------------------------------------------------
/** A button that submits an APPLICATION: not a bare "Submit", and never "Apply filters". */
const APPLICATION_SUBMIT = /^(submit( my| your)?( job)? application|send( my| your)? application|complete( my| your)? application|apply( now| today)?|apply (for|to) this (job|role|position))$/i;

// ---- exclusions: forms that are plainly something else -----------------------------------------------------------------------
const SEARCH_BUTTON = /^(search( jobs| roles| careers)?|find( jobs| roles)?|filter|apply filters|show results|go)$/i;
const SIGN_IN = /\b(sign ?in|log ?in|login)\b/i;
const REGISTRATION = /create (an |your |a )?account|sign ?up\b|\bregister\b|registration|join (now|us|for free|the community)|talent (community|network|pool)|job alerts?|create (an )?alert/i;
const OTHER_FORMS = /subscribe|newsletter|contact (us|form)|send (us )?(a )?message|your message|enquir|inquir|feedback|get (a )?quote|request (a )?(demo|quote|callback|call)|book (a|an|your) (call|demo|meeting|appointment)|waitlist|webinar|rsvp|donat|checkout|payment|place order/i;
const CONSENT = /accept (all|cookies)|reject (all|non-?essential)|\bcookies?\b|manage (cookie )?preferences|save preferences|privacy (settings|preferences)/i;
const BARRIER_TEXT = /verify (that )?you are (a )?human|are you a (robot|human)|captcha|prove you'?re not a robot|press (and|&) hold|access denied|checking your browser|unusual traffic/i;

const clean = (s) => (s ?? '').replace(/\s+/g, ' ').trim();
const meaningful = (label) => {
    const t = clean(label);

    return t.length >= 2 && !/^(select|choose|search|\.{2,3}|…)$/i.test(t);
};

const pageParts = (signals) => {
    const searchless = (signals.controls ?? []).filter((c) => !c.inSearch);
    const custom = (signals.customControls ?? []).filter((c) => !c.inSearch);

    return {
        searchless,
        custom,
        labelled: [...searchless, ...custom].filter((c) => meaningful(c.label)),
        buttons: (signals.buttons ?? []).map((b) => clean(b.text)).filter(Boolean),
        sections: (signals.sections ?? []).map(clean).filter(Boolean),
        headings: (signals.headings ?? []).map(clean),
        submits: (signals.forms ?? []).map((f) => clean(f.submit)),
        title: clean(signals.title),
    };
};

/**
 * What the page plainly IS, which no context can turn into an application: a search or filter, a sign-in, a sign-up or alert
 * subscription, a newsletter or contact or quote form, a cookie banner. Applies to every context.
 */
export function exclusionsFor(signals) {
    const { searchless, custom, labelled, buttons, sections, headings, submits, title } = pageParts(signals);
    const excluded = [];
    const chrome = [...buttons, ...submits, ...sections, ...headings, title].join(' | ');

    if (searchless.some((c) => c.type === 'password')) excluded.push('password_field');
    if (signals.searchRole || buttons.some((b) => SEARCH_BUTTON.test(b)) || (searchless.length + custom.length > 0 && labelled.length === 0)) excluded.push('search_or_filter');
    if ([...headings, ...submits, title].some((t) => SIGN_IN.test(t)) || (buttons.some((b) => /^(sign ?in|log ?in|login)$/i.test(b)) && !buttons.some((x) => APPLICATION_SUBMIT.test(x)))) excluded.push('sign_in');
    if (REGISTRATION.test(chrome)) excluded.push('registration_or_alert_signup');
    if (OTHER_FORMS.test(chrome)) excluded.push('newsletter_contact_or_other_site_form');
    if (CONSENT.test([...buttons, ...sections].join(' | '))) excluded.push('cookie_or_consent');

    return excluded;
}

/**
 * @param {object} signals page-kind.js collectSignals() output
 * @param {{context: string}} options
 * @returns {{recognised: boolean, basis: 'context_and_content'|'context'|'content'|null, evidence: string[], excluded: string[], barrier: boolean}}
 */
export function assessApplicationForm(signals, { context }) {
    const { labelled, buttons, sections, headings, title } = pageParts(signals);
    const excluded = exclusionsFor(signals);

    // ---- what would make it an application ----
    const evidence = [];
    const hasStrongContext = isStrongContext(context);
    if (hasStrongContext) evidence.push(`context:${context}`);
    const structure = labelled.length >= 3;
    if (structure) evidence.push('three_or_more_labelled_fields');
    const wording = APPLICATION_WORDS.test(`${title.toLowerCase()} ${headings.join(' ').toLowerCase()}`);
    if (wording) evidence.push('application_wording');
    const submit = buttons.some((b) => APPLICATION_SUBMIT.test(b));
    if (submit) evidence.push('application_submit_button');
    const selfId = sections.some((t) => SENSITIVE_SECTION.test(t));
    if (selfId) evidence.push('self_identification_section');

    const barrier = BARRIER_TEXT.test(`${title} ${headings.join(' ')} ${clean(signals.text).slice(0, 1500)}`);

    // Context says "this is meant to be the application"; it then needs only the fields to be there. Without context the page
    // must show independent content evidence: an application submit button or a self-identification section, AND application
    // wording, AND the fields. Controls alone, or wording alone, are never enough.
    const contentOk = structure && wording && (submit || selfId);
    const recognised = excluded.length === 0 && structure && (hasStrongContext || contentOk);
    const basis = !recognised ? null : hasStrongContext && contentOk ? 'context_and_content' : hasStrongContext ? 'context' : 'content';

    return { recognised, basis, evidence, excluded, barrier };
}
