# eval/runs/

Output of LIVE resolution runs: one directory per run, named `live-<manifest12>-<authorization12>`, holding `report.json`, `report.md`, `review-queue.json` and `checkpoint.json`.
A directory is **never reused**: a run is not resumed or repeated, and a new run needs a new authorization. Everything in it is sanitised (hosts and path shapes only; no query strings, ports,
cookies, credentials or slugs). **Nothing has been run: this directory is empty by design until the owner approves a pilot.**
