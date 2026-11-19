#!/usr/bin/env python3
# Runs inside the test container that owns the network namespace. The real nginx (relay/nginx.conf, unmodified) runs in a
# second container sharing this namespace. A fake "Well Applied" listens on 192.168.1.114:8000 and records everything it
# is sent; the relay must pass exactly two kinds of request and nothing else.
import socket, subprocess, sys, threading, time

seen = []          # raw requests the fake upstream received
lock = threading.Lock()

def upstream():
    s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(('192.168.1.114', 8000)); s.listen(16)
    while True:
        c, _ = s.accept()
        threading.Thread(target=handle, args=(c,), daemon=True).start()

def handle(c):
    try:
        data = b''
        c.settimeout(2)
        while b'\r\n\r\n' not in data:
            chunk = c.recv(4096)
            if not chunk: break
            data += chunk
        with lock: seen.append(data)
        c.sendall(b'HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 11\r\nConnection: close\r\n\r\n{"ok":true}')
    except Exception:
        pass
    finally:
        c.close()

threading.Thread(target=upstream, daemon=True).start()
time.sleep(0.3)

def send(raw):
    """Send raw bytes to the relay, return the status code (or 0)."""
    s = socket.create_connection(('127.0.0.1', 8081), timeout=5)
    s.sendall(raw)
    s.settimeout(5)
    buf = b''
    try:
        while True:
            d = s.recv(4096)
            if not d: break
            buf += d
            if b'\r\n\r\n' in buf and len(buf) > 40: break
    except socket.timeout:
        pass
    s.close()
    return int(buf.split(b' ', 2)[1]) if buf.startswith(b'HTTP/') else 0

AUTH = 'Authorization: Bearer abcdef1234567890abcdef\r\n'
def req(method='POST', path='/api/worker/agent-runs/claim', auth=AUTH, extra='', body=b'{}', version='HTTP/1.1', host='api-relay:8081'):
    head = f'{method} {path} {version}\r\nHost: {host}\r\n{auth}{extra}Content-Type: application/json\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n'
    return head.encode() + body

passed = failed = 0
def check(desc, ok, detail=''):
    global passed, failed
    if ok: passed += 1; print(f'  ok    {desc}')
    else: failed += 1; print(f'  FAIL  {desc}  {detail}')

def upstream_count(): 
    with lock: return len(seen)

print('== allowed requests are forwarded, with the token untouched')
for path in ('/api/worker/agent-runs/claim', '/api/worker/agent-runs/42/result', '/api/worker/agent-runs/999999999999/result'):
    before = upstream_count()
    code = send(req(path=path))
    time.sleep(0.2)
    with lock: last = seen[-1] if len(seen) > before else b''
    check(f'POST {path} -> 200 and reaches Well Applied', code == 200 and last.startswith(f'POST {path} HTTP/1.1'.encode()), f'code={code}')
    check(f'  token forwarded unchanged', b'Authorization: Bearer abcdef1234567890abcdef\r\n' in last)

print('== everything else is refused AND never reaches Well Applied')
cases = [
  ('GET on the claim path',                     req(method='GET', path='/api/worker/agent-runs/claim', body=b''), 405),
  ('PUT on the result path',                    req(method='PUT', path='/api/worker/agent-runs/1/result'), 405),
  ('DELETE',                                    req(method='DELETE', path='/api/worker/agent-runs/claim', body=b''), 405),
  ('no Authorization',                          req(auth=''), 401),
  ('Basic instead of Bearer',                   req(auth='Authorization: Basic YWJjZGVmMTIzNDU2\r\n'), 401),
  ('a too-short bearer token',                  req(auth='Authorization: Bearer abc\r\n'), 401),
  ('a bearer token with a space in it',         req(auth='Authorization: Bearer abcdef12 34567890\r\n'), 401),
  ('the site root',                             req(path='/'), 404),
  ('the web UI',                                req(path='/opportunities'), 404),
  ('the applications UI',                       req(path='/applications/1'), 404),
  ('the API root',                              req(path='/api/worker/agent-runs'), 404),
  ('the preparer API',                          req(path='/api/worker/preparer/agent-runs/claim'), 404),
  ('another run endpoint',                      req(path='/api/worker/agent-runs/1/screenshots/x'), 404),
  ('trailing slash',                            req(path='/api/worker/agent-runs/claim/'), 404),
  ('a query string on claim',                   req(path='/api/worker/agent-runs/claim?x=1'), 404),
  ('a query string on result',                  req(path='/api/worker/agent-runs/1/result?x=1'), 404),
  ('a repeated slash',                          req(path='//api/worker/agent-runs/claim'), 404),
  ('a repeated slash inside',                   req(path='/api/worker//agent-runs/claim'), 404),
  ('dot segments out of the path',              req(path='/api/worker/agent-runs/claim/../../../opportunities'), 404),
  ('dot segments back into an allowed path',    req(path='/api/worker/x/../agent-runs/claim'), 404),
  ('a dot segment before the id',               req(path='/api/worker/agent-runs/./1/result'), 404),
  ('percent-encoded letters',                   req(path='/api/worker/agent-runs/%63laim'), 404),
  ('an encoded slash in the result path',       req(path='/api/worker/agent-runs/1%2fresult'), 404),
  ('an encoded dot',                            req(path='/api/worker/agent-runs/%2e%2e/agent-runs/claim'), 404),
  ('a NUL after the path (rejected as malformed)', req(path='/api/worker/agent-runs/1/result%00'), (400, 404)),
  ('header injection in the path',              req(path='/api/worker/agent-runs/claim%0d%0aX-Evil:1'), 404),
  ('a 13-digit run id',                         req(path='/api/worker/agent-runs/1234567890123/result'), 404),
  ('a non-numeric run id',                      req(path='/api/worker/agent-runs/abc/result'), 404),
  ('an empty run id',                           req(path='/api/worker/agent-runs//result'), 404),
  ('a negative run id',                         req(path='/api/worker/agent-runs/-1/result'), 404),
  ('a path that merely contains the allowed one', req(path='/x/api/worker/agent-runs/claim'), 404),
  ('upper-case path',                           req(path='/API/WORKER/AGENT-RUNS/CLAIM'), 404),
  ('a different Host header still goes only to Well Applied', None, None),
]
for desc, raw, want in cases:
    if raw is None: continue
    before = upstream_count()
    code = send(raw)
    time.sleep(0.15)
    wanted = want if isinstance(want, tuple) else (want,)
    check(f'{desc} -> {"/".join(map(str, wanted))}, upstream saw nothing', code in wanted and upstream_count() == before, f'code={code} upstream_new={upstream_count()-before}')

before = upstream_count()
big = req(path='/api/worker/agent-runs/1/result', body=b'x' * (1024 * 1024 + 10))
check('a body over 1 MB -> 413, upstream saw nothing', send(big) == 413 and upstream_count() == before)

print('== a forged Host header cannot redirect the relay')
before = upstream_count()
code = send(req(host='evil.example.com'))
time.sleep(0.2)
check('Host: evil.example.com on a valid request is still answered by the one upstream', code == 200 and upstream_count() == before + 1)

print('== an absolute-form request line naming another host')
before = upstream_count()
code = send(req(path='http://evil.example.com/api/worker/agent-runs/claim'))
time.sleep(0.2)
with lock: new = seen[before:]
check('it cannot make the relay contact evil.example.com: any request reaching Well Applied is the canonical one', code in (200, 400, 404) and all(r.startswith(b'POST /api/worker/agent-runs/claim HTTP/1.1\r\n') for r in new), f'code={code} saw={[r[:60] for r in new]}')

print('== across the WHOLE run, Well Applied was only ever sent the two allowed request shapes')
import re
allowed = re.compile(rb'^POST /api/worker/agent-runs/(claim|[0-9]{1,12}/result) HTTP/1\.1\r\n')
with lock: everything = list(seen)
check(f'all {len(everything)} requests that reached Well Applied are canonical, authenticated POSTs', all(allowed.match(r) and b'Authorization: Bearer ' in r for r in everything))

print('== the relay has no way to name another upstream')
conf = open('/relay/nginx.conf').read()
check('no resolver directive', 'resolver ' not in conf)
check('exactly one upstream address', conf.count('server 192.168.1.114:8000;') == 1 and conf.count('proxy_pass') == 2)
check('proxy_pass only to the named upstream', all(l.strip().startswith('proxy_pass http://well_applied$uri;') for l in conf.splitlines() if 'proxy_pass' in l and not l.strip().startswith('#')))

print(f'\nRESULT: {passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
