#!/usr/bin/env python3
"""Stage 1 cohort proposal: the production-discovery half (about 20 cases).

READ-ONLY. Opens the Well Applied SQLite file with `mode=ro` (SQLite itself refuses every write), runs SELECTs only,
and never reads a profile, resume, application answer, credential or application history. Jobs that have an Application are
EXCLUDED: pursuing a job is personal history, not a representative sample.

What it writes is deliberately thin. For each selected record: its id, source, host, the SHAPE of its URL (digits replaced), a
SHA-256 of the exact stored URL and flags. NOT the URL, title, company or description: Stage 2 re-reads the URL from the
database by id and refuses to proceed if its hash differs.

Selection is seeded and deterministic for a given database. It is a curation aid, not a random sample.

    python3 select-from-database.py <path-to-database.sqlite> [--out stage1.proposed.json]
"""
import collections, datetime, hashlib, json, random, re, sqlite3, sys
from pathlib import Path
from urllib.parse import urlparse

SEED = 20261121
NOW = datetime.datetime(2026, 11, 21)
HERE = Path(__file__).resolve().parent

db_path = sys.argv[1]
out = Path(sys.argv[sys.argv.index('--out') + 1]) if '--out' in sys.argv else HERE / 'stage1.proposed.json'
db = sqlite3.connect(f'file:{db_path}?mode=ro', uri=True)
db.row_factory = sqlite3.Row
rng = random.Random(SEED)

norm_c = lambda s: re.sub(r'\s+', ' ', (s or '').strip().lower()).rstrip('.,')          # NormalizeCompanyName::normalize
norm_t = lambda s: re.sub(r'\s+', ' ', (s or '').strip().lower()).strip(" \t\n\r\0\x0b.,-")  # MatchCanonicalPosting::normalizeTitle

rows = [dict(r) for r in db.execute("""
    select p.id, p.discovery_source src, p.company, p.title, p.source_url url, p.location loc, p.posted_at, p.discovered_at,
           p.description_completeness dc
    from job_postings p
    where not exists (select 1 from applications a where a.job_posting_id = p.id)
""")]
# descriptions are only ever HASHED (to tell a true duplicate from a multi-location listing), never copied
desc_hash = {r[0]: hashlib.sha256(r[1].encode()).hexdigest() for r in db.execute("select id, description from job_postings")}
total = db.execute("select count(*) from job_postings").fetchone()[0]
with_app = db.execute("select count(*) from job_postings p where exists (select 1 from applications a where a.job_posting_id=p.id)").fetchone()[0]

def host(u): return (urlparse(u or '').hostname or '').lower()
def shape(u):
    p = urlparse(u or '')
    return f"{(p.hostname or '').lower()}{re.sub(r'[0-9a-f]{8,}|[0-9]+', 'N', p.path)[:60]}{'?…' if p.query else ''}"
def platform(h):
    for k, v in [('greenhouse.io', 'greenhouse'), ('ashbyhq.com', 'ashby'), ('lever.co', 'lever'), ('myworkdayjobs.com', 'workday'), ('workable.com', 'workable'), ('smartrecruiters.com', 'smartrecruiters'), ('eightfold.ai', 'eightfold')]:
        if h.endswith(k): return v
    if h == 'jobs.nvidia.com': return 'eightfold'  # NVIDIA's careers site is served by Eightfold (the stored discovery host says so)
    return None
def age(r):
    s = r['posted_at'] or r['discovered_at']
    return (NOW - datetime.datetime.fromisoformat(s[:19])).days if s else None
for r in rows:
    h = host(r['url']); r['host'] = h; r['plat'] = platform(h)
    r['agg'] = r['src'] in ('adzuna', 'jobicy', 'himalayas')
    r['age'] = age(r)

chosen = {}
def take(r, stratum, **flags):
    if r['id'] in chosen: return
    chosen[r['id']] = {'stratum': stratum, **flags, 'row': r}

# 1. Direct Eightfold: four jobs, at least two employers (hosts).
ef = sorted([r for r in rows if r['plat'] == 'eightfold'], key=lambda r: r['id'])
by_host = collections.defaultdict(list)
for r in ef: by_host[r['host']].append(r)
picks = [rng.choice(v) for v in by_host.values()]
rest = [r for r in ef if r not in picks]; rng.shuffle(rest)
for r in (picks + rest)[:4]: take(r, 'direct_ats_eightfold')
# 2. The one Ashby job that is not already in Stage 0.
for r in rows:
    if r['plat'] == 'ashby': take(r, 'direct_ats_ashby')
# 3. Same-source duplicate pairs: exact company+title under the canonical normalisers AND an identical description.
groups = collections.defaultdict(list)
for r in rows:
    if r['agg']: groups[(r['src'], norm_c(r['company']), norm_t(r['title']))].append(r)
true_dups = {k: v for k, v in groups.items() if len(v) == 2 and desc_hash[v[0]['id']] == desc_hash[v[1]['id']]}
for src, n in (('jobicy', 2), ('adzuna', 1)):
    pool = sorted([k for k in true_dups if k[0] == src])
    for gi, k in enumerate(rng.sample(pool, min(n, len(pool)))):
        for r in true_dups[k]: take(r, f'duplicate_pair_{src}', duplicate_group=f'dup-{src}-{gi + 1}')
# 4. Aggregator singletons, with staleness and URL shape deliberately mixed. No title/company duplicates among them.
dup_keys = {(r['src'], norm_c(r['company']), norm_t(r['title'])) for v in groups.values() if len(v) > 1 for r in v}
singles = lambda src: sorted([r for r in rows if r['src'] == src and r['id'] not in chosen and (src, norm_c(r['company']), norm_t(r['title'])) not in dup_keys], key=lambda r: r['id'])
adz = singles('adzuna')
stale = [r for r in adz if (r['age'] or 0) > 60]; fresh = [r for r in adz if (r['age'] or 0) <= 30]
details_stale = [r for r in stale if '/details/' in (r['url'] or '')]
picked = [rng.choice(fresh)]
picked.append(rng.choice(details_stale) if details_stale else rng.choice(stale))
picked.append(rng.choice([s for s in stale if s not in picked]))
for r in picked: take(r, 'aggregator_adzuna')
for r in rng.sample(singles('jobicy'), 2): take(r, 'aggregator_jobicy')
for r in rng.sample(singles('himalayas'), 2): take(r, 'aggregator_himalayas')
# 5. Negative controls that already exist in the data: no URL at all, and a manual record pointing at a search engine's job board.
for r in sorted(rows, key=lambda r: r['id']):
    if r['src'] == 'manual' and not r['url']: take(r, 'negative_no_url', negative_control=True)
    if r['src'] == 'manual' and 'indeed.' in r['host']: take(r, 'negative_third_party_manual', negative_control=True)

HAND_REVIEW_FOR = {'direct_ats_eightfold': 2, 'direct_ats_ashby': 1, 'aggregator_adzuna': 1, 'aggregator_jobicy': 1, 'aggregator_himalayas': 1}
review_budget = dict(HAND_REVIEW_FOR); cases = []
for rid in sorted(chosen):
    c = chosen[rid]; r = c['row']; st = c['stratum']
    kind = 'none' if not r['url'] else ('aggregator_listing' if r['agg'] else ('employer_ats' if r['plat'] else 'third_party_or_unknown'))
    hand = review_budget.get(st, 0) > 0
    if hand: review_budget[st] -= 1
    plan = 'hand_review' if hand else 'none'
    cases.append({
        'id': f'prod-{rid}', 'provenance': 'production_discovery',
        'record_ref': {'job_posting_id': rid, 'url_sha256': hashlib.sha256(r['url'].encode()).hexdigest() if r['url'] else None, 'host': r['host'] or None, 'url_shape': shape(r['url']) if r['url'] else None},
        'source': r['src'], 'stratum': st, 'listing_url_kind': kind,
        'application_url': ({'status': 'known_direct', 'value_from': 'record_ref', 'basis': 'the stored URL is itself on an ATS host: a direct link, but not yet verified to be the application page'} if kind == 'employer_ats'
                            else {'status': 'unresolved', 'value': None, 'basis': 'an aggregator listing URL is NOT an employer application URL; the destination has never been resolved'} if kind == 'aggregator_listing'
                            else {'status': 'not_applicable', 'value': None, 'basis': 'no usable URL' if kind == 'none' else 'a third-party job board, not an employer'}),
        'expected_platform': {'v': r['plat'], 'status': 'host_derived'} if r['plat'] else {'v': None, 'status': 'unknown_until_destination_resolved'},
        'flags': {k: v for k, v in c.items() if k in ('duplicate_group', 'negative_control')} | ({'stale_candidate': True} if (r['age'] or 0) > 60 else {}) | ({'description_preview_only': True} if r['dc'] == 'preview' else {}) | ({'age_days': r['age']} if r['age'] is not None else {}),
        'liveness': 'unverified',
        'ground_truth': {'plan': plan, 'rationale': {'hand_review': 'selected for a person-reviewed form (see README, "Hand-reviewed ground truth")', 'none': 'scored on URL resolution, platform and outcome classification only'}[plan]},
    })

ext = json.loads((HERE / 'external-candidates.json').read_text())
for e in ext['candidates']:
    cases.append({
        'id': e['id'], 'provenance': 'curated_external',
        'url': e['url'], 'url_note': e.get('url_note'),
        'source': 'curated_external', 'stratum': f"external_{e['platform']}", 'role': e['role'], 'listing_url_kind': 'employer_ats',
        'application_url': {'status': 'known_direct', 'value_from': 'url', 'basis': 'an ATS-hosted posting URL: a direct link, not yet verified to be the application page'},
        'expected_platform': {'v': e['platform'], 'status': 'host_derived'},
        'board': e['board'], 'title': e['title'], 'title_note': e['title_note'],
        'flags': {'stale_candidate': e['role'] == 'stale_or_unreachable_control'} | ({'vendor_demo': True} if e['role'] == 'vendor_demo_posting' else {}),
        'liveness': 'unverified',
        'ground_truth': {'plan': e['ground_truth_plan'], 'rationale': e['plan_note']},
    })

cases += [
    {'id': 'carried-chainguard-4722145006', 'provenance': 'stage0_existing', 'stage0_case': 'real-chainguard-4722145006', 'stratum': 'carried_from_stage0', 'expected_platform': {'v': 'greenhouse', 'status': 'observed'}, 'ground_truth': {'plan': 'existing', 'rationale': 'golden semantic file; its rows include known extraction errors'}, 'evidence_limitations': 'One user-entered URL; a captured live inspection from before generic discovery; the form was not exhaustively enumerated (some fields are unobserved or API-only).', 'liveness': 'not_applicable'},
    {'id': 'carried-nerdwallet-258', 'provenance': 'stage0_existing', 'stage0_case': 'real-nerdwallet-258', 'stratum': 'carried_from_stage0', 'expected_platform': {'v': 'ashby', 'status': 'observed'}, 'ground_truth': {'plan': 'existing', 'rationale': "a person's own browser observation"}, 'evidence_limitations': 'The scored result is the offline Ashby schema adapter over captured schema evidence, NOT a successful live Ashby inspection; the browser observation is not an exhaustive enumeration.', 'liveness': 'not_applicable'},
]

manifest = {
    'schema': 'eval-cohort/1', 'status': 'proposed', 'cohort_version': '0.1.0-proposed',
    'purpose': 'A PROPOSED Stage 1 cohort. It authorises nothing: no discovery, resolution, inspection or application preparation.',
    'selection': {'seed': SEED, 'procedure': 'eval/cohort/select-from-database.py (read-only) plus eval/cohort/external-candidates.json (hand-curated)',
                  'database_snapshot': {'job_postings': total, 'excluded_because_an_application_exists': with_app, 'max_job_posting_id': db.execute('select max(id) from job_postings').fetchone()[0]},
                  'note': 'Jobs that have an Application are excluded: pursued jobs are personal history, not a representative sample.'},
    'cases': cases,
}
out.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n')
print(f'wrote {out} with {len(cases)} cases')
