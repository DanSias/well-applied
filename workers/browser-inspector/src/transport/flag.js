// BROWSER_WORKER_ASHBY_SCHEMA=1 lets the worker read an Ashby job's form from Ashby's own public schema (a separate, non-browser
// HTTPS client: src/transport/ashby.js) instead of opening the page. Default OFF; exactly "1" enables it. It does not touch the
// browser's GET/HEAD guard, and it applies only to verified https://jobs.ashbyhq.com job links.
export const ashbySchemaRequested = (env = process.env) => env.BROWSER_WORKER_ASHBY_SCHEMA === '1';
