# TLS material for the LIVE-adapter local HTTPS fixtures (tests only)

`fixtures.pem` / `fixtures.key`: a self-signed, test-only certificate whose subject alternative names are the fixture host names (jobicy.com, himalayas.app, ...). The
private key is committed on purpose: it protects nothing, and it is trusted by NOTHING except a test browser launched with `--ignore-certificate-errors-spki-list=<spki.txt>`
against local servers on 127.0.0.1. `spki.txt` is the base64 SHA-256 of its public key. Regenerate with `san.cnf` and `openssl req -x509 -newkey ec ...`.
