// BROWSER_WORKER_GENERIC_DISCOVERY=1 turns generic form discovery on for form pages the worker would otherwise report as
// "unsupported" (a form was reached, but there is no extractor for its ATS). Default OFF. It never changes how Greenhouse forms
// are read: those keep the existing extractor until a separate parity gate is passed and approved.
export const genericDiscoveryRequested = (env = process.env) => env.BROWSER_WORKER_GENERIC_DISCOVERY === '1';
