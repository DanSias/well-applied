// The IN-PAGE half of discovery: runs inside the browser via a read-only evaluate, gathers plain facts about every control-like
// element, and returns them as JSON (the Signals shape in signals.js). It interprets nothing and modifies nothing:
//   - no click, focus, scroll, input event or mutation of the page (it does not even tag elements: ids live in a WeakMap),
//   - no value of any control is read (only whether it is required, what it is called, what choices a list holds).
//
// MUST be self-contained: Playwright serialises this function's source, so it can use nothing from this module's scope.
export function collectSignals() {
    const clean = (s) => (s ?? '').replace(/\s+/g, ' ').trim();
    const MAX_CANDIDATES = 400;

    // ---- identity without mutation --------------------------------------------------------------------------------------
    const ids = new WeakMap();
    let nextId = 1;
    const idOf = (el) => {
        if (!ids.has(el)) ids.set(el, nextId++);

        return ids.get(el);
    };

    function selectorFor(el) {
        if (el.id) {
            const byId = `#${CSS.escape(el.id)}`;
            if (document.querySelectorAll(byId).length === 1) return byId;
        }
        const parts = [];
        let node = el;
        while (node && node.nodeType === 1 && node !== document.documentElement) {
            let idx = 1;
            let sib = node;
            while ((sib = sib.previousElementSibling)) if (sib.tagName === node.tagName) idx += 1;
            parts.unshift(`${node.tagName.toLowerCase()}:nth-of-type(${idx})`);
            const joined = parts.join(' > ');
            try {
                if (document.querySelectorAll(joined).length === 1 && document.querySelector(joined) === el) return joined;
            } catch {
                /* fall through to a longer path */
            }
            node = node.parentElement;
        }

        return parts.join(' > ');
    }

    // ---- text ---------------------------------------------------------------------------------------------------------------
    /** Visible text of an element, without form controls, scripts, and aria-hidden marks (a required "*" is reported apart). */
    function labelText(el) {
        const clone = el.cloneNode(true);
        let marker = false;
        clone.querySelectorAll('input, select, textarea, option, script, style').forEach((n) => n.remove());
        clone.querySelectorAll('[aria-hidden="true"]').forEach((n) => {
            if (/^\s*\*+\s*$/.test(n.textContent)) marker = true;
            n.remove();
        });
        const text = clean(clone.textContent);
        if (/\*\s*$/.test(text)) marker = true;

        return { text: text.replace(/\s*\*+\s*$/, '').trim(), marker };
    }

    // ---- visibility ---------------------------------------------------------------------------------------------------------
    function ancestorsOrSelf(el) {
        const out = [];
        for (let n = el; n && n.nodeType === 1; n = n.parentElement) out.push(n);

        return out;
    }
    const inHiddenSubtree = (el) => ancestorsOrSelf(el).some((a) => a.hidden === true || getComputedStyle(a).display === 'none');
    const ariaHiddenOf = (el) => ancestorsOrSelf(el).some((a) => a.getAttribute('aria-hidden') === 'true');

    function visibility(el, type) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        const hiddenTree = inHiddenSubtree(el);
        const ariaHidden = ariaHiddenOf(el);
        const laidOut = r.width > 0 && r.height > 0;
        const tiny = r.width <= 2 || r.height <= 2;
        const invisible = cs.visibility === 'hidden' || cs.opacity === '0';
        // A file input behind a styled button is visually hidden on purpose; it is still the field.
        const clipped = laidOut && (tiny || invisible || cs.clip === 'rect(0px, 0px, 0px, 0px)');
        const userVisible = !hiddenTree && !ariaHidden && laidOut && cs.visibility !== 'hidden' && (type === 'file' ? true : !tiny && cs.opacity !== '0');

        return { userVisible, hiddenTree, ariaHidden, clipped };
    }

    // ---- labels, groups, headings ---------------------------------------------------------------------------------------------
    function labelsOf(el) {
        const forLabels = el.id
            ? Array.from(document.querySelectorAll(`label[for="${CSS.escape(el.id)}"]`)).map(labelText)
            : [];
        const wrap = el.closest('label');
        const wrapping = wrap && (!wrap.htmlFor || wrap.htmlFor === el.id) && !forLabels.length ? labelText(wrap) : null;
        const labelledby = (el.getAttribute('aria-labelledby') || '')
            .split(/\s+/)
            .filter(Boolean)
            .map((id) => document.getElementById(id))
            .filter(Boolean)
            .map((ref) => ({ ...labelText(ref), inside: el.contains(ref) }));

        return {
            forLabels,
            wrapping,
            ariaLabel: el.getAttribute('aria-label'),
            labelledby,
            title: el.getAttribute('title'),
            placeholder: el.getAttribute('placeholder'),
        };
    }

    function groupOf(el) {
        for (let n = el.parentElement; n && n !== document.body; n = n.parentElement) {
            if (n.tagName === 'FIELDSET') {
                const legend = n.querySelector(':scope > legend');
                if (legend && clean(legend.textContent)) return { kind: 'fieldset', css: selectorFor(n), label: labelText(legend).text, labelSource: 'legend' };
            }
            const role = n.getAttribute('role');
            if (role === 'group' || role === 'radiogroup') {
                const by = (n.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean).map((id) => document.getElementById(id)).filter(Boolean).map((r) => labelText(r).text).join(' ');
                if (by) return { kind: role === 'radiogroup' ? 'radiogroup' : 'role_group', css: selectorFor(n), label: by, labelSource: 'labelledby' };
                if (n.getAttribute('aria-label')) return { kind: role === 'radiogroup' ? 'radiogroup' : 'role_group', css: selectorFor(n), label: clean(n.getAttribute('aria-label')), labelSource: 'aria_label' };
            }
        }

        return null;
    }

    const headings = Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6, [role="heading"]'));
    const levelOf = (h) => Number(h.getAttribute('aria-level')) || (/^H[1-6]$/.test(h.tagName) ? Number(h.tagName[1]) : 2);
    const SECTION_BOUNDS = 'section, fieldset, form, main, article, [role="group"], [role="region"]';

    /** The nearest heading above the control, within the same section. The page's own title (a level 1 heading) is not one. */
    function headingContext(el) {
        let best = null;
        for (const h of headings) {
            if (levelOf(h) < 2) continue;
            if (!(h.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) continue;
            const bound = h.closest(SECTION_BOUNDS);
            if (bound && !bound.contains(el)) continue;
            best = h;
        }

        return best ? labelText(best).text || null : null;
    }

    /** The existing extractor's rule: the closest fieldset's legend, else the nearest heading above (any level). */
    function sectionOf(el) {
        for (let n = el.parentElement; n && n !== document.body; n = n.parentElement) {
            if (n.tagName === 'FIELDSET') {
                const legend = n.querySelector(':scope > legend');
                if (legend && clean(legend.textContent)) return { text: clean(legend.textContent), isGroup: true };
            }
        }
        let best = null;
        for (const h of headings) if (h.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING) best = h;

        return { text: best ? labelText(best).text || null : null, isGroup: false };
    }

    function proximityOf(el) {
        const out = [];
        let sib = el.previousElementSibling;
        for (let hops = 0; sib && hops < 3; hops += 1, sib = sib.previousElementSibling) {
            const t = clean(sib.innerText);
            if (t && t.length < 200) out.push({ text: t, ref: idOf(sib) });
        }

        return out;
    }

    // ---- popups and options -----------------------------------------------------------------------------------------------------
    function popupOf(el) {
        const ref = (el.getAttribute('aria-controls') || el.getAttribute('aria-owns') || '').split(/\s+/).filter(Boolean).map((id) => document.getElementById(id)).find(Boolean);
        let box = ref ?? null;
        if (!box && (el.tagName === 'BUTTON' || el.getAttribute('role') === 'button')) {
            const next = el.nextElementSibling;
            box = next ? next.querySelector('[role="listbox"]') || (next.matches('[role="listbox"]') ? next : null) : null;
        }
        if (!box) return null;
        const list = box.matches('[role="listbox"]') ? box : box.querySelector('[role="listbox"]') || box;
        const options = Array.from(list.querySelectorAll('[role="option"]')).map((o) => clean(o.textContent)).filter(Boolean);

        return { hidden: inHiddenSubtree(list), options };
    }

    // ---- the candidates ------------------------------------------------------------------------------------------------------------
    const selector = ['input', 'textarea', 'select', '[role="radio"]', '[role="checkbox"]', '[role="combobox"]', '[role="listbox"]', '[role="switch"]', 'button[aria-haspopup]', '[role="button"][aria-haspopup]'].join(',');
    const candidates = [];

    for (const el of Array.from(document.querySelectorAll(selector)).slice(0, MAX_CANDIDATES)) {
        const tag = el.tagName.toLowerCase();
        const type = tag === 'input' ? (el.getAttribute('type') || 'text').toLowerCase() : null;
        const v = visibility(el, type);
        const section = sectionOf(el);
        const labels = labelsOf(el);

        candidates.push({
            n: candidates.length,
            tag,
            type,
            role: el.getAttribute('role'),
            id: el.id || null,
            name: el.getAttribute('name'),
            testid: el.getAttribute('data-testid'),
            css: selectorFor(el),
            userVisible: v.userVisible,
            inHiddenSubtree: v.hiddenTree,
            ariaHidden: v.ariaHidden,
            clipped: v.clipped,
            tabindex: el.getAttribute('tabindex'),
            isNative: 'required' in el,
            required: 'required' in el ? el.required === true : false,
            ariaRequired: el.getAttribute('aria-required'),
            multiple: el.multiple === true,
            accept: el.getAttribute('accept'),
            ariaHaspopup: el.getAttribute('aria-haspopup'),
            ariaControls: el.getAttribute('aria-controls'),
            labels,
            group: groupOf(el),
            options: tag === 'select' ? Array.from(el.options).map((o) => ({ text: clean(o.text), value: o.value, disabled: o.disabled })) : null,
            popup: el.getAttribute('role') === 'combobox' || el.getAttribute('aria-haspopup') ? popupOf(el) : null,
            ancestors: ancestorsOrSelf(el).slice(1, 7).map(idOf),
            proximity: proximityOf(el),
            heading: headingContext(el),
            section: section.text,
            sectionIsGroup: section.isGroup,
            // The visible text naming a radio or checkbox option (never its value).
            labelText: type === 'radio' || type === 'checkbox' ? labels.forLabels[0]?.text || labels.wrapping?.text || null : null,
        });
    }

    // ---- what the page holds that is not a control we can report -------------------------------------------------------------
    const present = new Set(Array.from(document.querySelectorAll('[id]')).map((e) => e.id));
    const orphanLabels = Array.from(document.querySelectorAll('label[for]'))
        .filter((l) => !present.has(l.getAttribute('for')))
        .slice(0, 20)
        .map((l) => ({ for: l.getAttribute('for'), text: labelText(l).text }));

    let steps = null;
    const current = document.querySelector('[aria-current="step"]');
    const list = current ? current.closest('ol, ul') : null;
    if (list && list.children.length > 1) {
        steps = { current: Array.from(list.children).findIndex((c) => c === current || c.contains(current)) + 1 || null, total: list.children.length, source: 'progress_list' };
    } else {
        const m = /\bstep\s+(\d+)\s+(?:of|\/)\s+(\d+)\b/i.exec(clean(document.body?.innerText));
        if (m) steps = { current: Number(m[1]), total: Number(m[2]), source: 'step_text' };
    }

    return {
        url: location.href,
        title: document.title,
        candidates,
        orphanLabels,
        steps,
        counts: { buttons: document.querySelectorAll('button, [role="button"], input[type="submit"], input[type="button"]').length, hiddenInputs: document.querySelectorAll('input[type="hidden"]').length },
    };
}
