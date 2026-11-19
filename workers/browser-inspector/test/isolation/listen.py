#!/usr/bin/env python3
# listen.py PORT [PORT...]: accept TCP connections on 0.0.0.0 and [::] and close them at once. A stand-in service.
import socket, sys, threading
def serve(family, addr, port):
    s = socket.socket(family, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    if family == socket.AF_INET6:
        s.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
    s.bind((addr, port)); s.listen(64)
    while True:
        c, _ = s.accept(); c.close()
for p in map(int, sys.argv[1:]):
    for fam, addr in ((socket.AF_INET, '0.0.0.0'), (socket.AF_INET6, '::')):
        threading.Thread(target=serve, args=(fam, addr, p), daemon=True).start()
threading.Event().wait()
