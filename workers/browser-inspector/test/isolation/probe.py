#!/usr/bin/env python3
# probe.py HOST PORT TIMEOUT [SOURCE]  ->  one word: connected | rejected | refused | timeout | unreachable | error:CODE
# "rejected" is EHOSTUNREACH, which is how Linux reports the isolation policy's ICMP admin-prohibited. "refused" is a TCP RST
# (the packet reached a machine with nothing listening), which is NOT the policy.
import errno, socket, sys
host, port, timeout = sys.argv[1], int(sys.argv[2]), float(sys.argv[3])
src = sys.argv[4] if len(sys.argv) > 4 else None
s = socket.socket(socket.AF_INET6 if ':' in host else socket.AF_INET, socket.SOCK_STREAM)
s.settimeout(timeout)
try:
    if src:
        s.bind((src, 0))
    s.connect((host, port))
    print('connected')
except socket.timeout:
    print('timeout')
except OSError as e:
    print({errno.EHOSTUNREACH: 'rejected', errno.ENETUNREACH: 'unreachable', errno.ECONNREFUSED: 'refused'}.get(e.errno, 'error:' + errno.errorcode.get(e.errno or 0, str(e.errno))))
finally:
    s.close()
