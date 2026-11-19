#!/usr/bin/env python3
# hammer.py HOSTS PORTS ROUNDS TIMEOUT [THREADS]  ->  JSON counts of outcomes, from the namespace it is run in.
# Many concurrent connection attempts at every host:port, to flush out any race in which a refused destination ever answers.
import collections, errno, json, socket, sys, threading
hosts, ports = sys.argv[1].split(','), [int(p) for p in sys.argv[2].split(',')]
rounds, timeout = int(sys.argv[3]), float(sys.argv[4])
threads = int(sys.argv[5]) if len(sys.argv) > 5 else 16
counts, lock = collections.Counter(), threading.Lock()
work = [(h, p) for _ in range(rounds) for h in hosts for p in ports]
def one(h, p):
    s = socket.socket(); s.settimeout(timeout)
    try:
        s.connect((h, p)); r = 'connected'
    except socket.timeout: r = 'timeout'
    except OSError as e: r = {errno.EHOSTUNREACH: 'rejected', errno.ENETUNREACH: 'unreachable', errno.ECONNREFUSED: 'refused'}.get(e.errno, 'error')
    finally: s.close()
    with lock: counts[f'{r}'] += 1; counts[f'{r}:{h}:{p}'] += 0
def worker():
    while True:
        with lock:
            if not work: return
            h, p = work.pop()
        one(h, p)
ts = [threading.Thread(target=worker) for _ in range(threads)]
[t.start() for t in ts]; [t.join() for t in ts]
print(json.dumps({k: v for k, v in counts.items() if ':' not in k}))
