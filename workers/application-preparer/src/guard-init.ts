// Page-context script injected into every frame before any page script runs.
// It is the ONLY file allowed to mention form submission primitives; the
// "absent capability" test scans every other source file for them.
//
// It does not click or submit anything. It neutralises submission: a
// capture-phase `submit` listener that cancels and reports, and no-op
// replacements for form.submit()/requestSubmit() that report.
export const GUARD_INIT_SCRIPT = `(() => {
  const report = (how, form) => {
    try {
      const target = form && (form.getAttribute && (form.getAttribute('action') || '')) || '';
      const fn = window.__waSubmitBlocked;
      if (typeof fn === 'function') fn(how + ' action=' + target);
    } catch (_) {}
  };
  window.addEventListener('submit', (e) => {
    e.preventDefault();
    e.stopImmediatePropagation();
    report('submit_event', e.target);
  }, true);
  try {
    HTMLFormElement.prototype.submit = function () { report('form.submit()', this); };
    HTMLFormElement.prototype.requestSubmit = function () { report('form.requestSubmit()', this); };
  } catch (_) {}
})();`;
