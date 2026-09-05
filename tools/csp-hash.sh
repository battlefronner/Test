#!/usr/bin/env bash
# Berechnet den CSP-Hash eines Inline-Skriptblocks.
#
# Nötig, wenn der JSON-LD-Block in index.html geändert wurde: Der Hash in der
# Content-Security-Policy (.htaccess bzw. nginx.conf.example) muss dann
# aktualisiert werden, sonst blockiert der Browser den Block.
#
# Aufruf:  tools/csp-hash.sh index.html
#
# Gehasht wird exakt der Text zwischen <script ...> und </script>,
# einschließlich der umgebenden Zeilenumbrüche.

set -euo pipefail

FILE="${1:-index.html}"

if [ ! -f "$FILE" ]; then
    echo "Datei nicht gefunden: $FILE" >&2
    exit 1
fi

python3 - "$FILE" <<'PY'
import base64, hashlib, re, sys

path = sys.argv[1]
html = open(path, encoding='utf-8').read()

blocks = re.findall(r'<script[^>]*>(.*?)</script>', html, re.DOTALL)
inline = [b for b in blocks if b.strip()]

if not inline:
    print(f"Keine Inline-Skripte in {path} gefunden.")
    sys.exit(0)

print(f"Inline-Skripte in {path}:\n")
for i, content in enumerate(inline, 1):
    digest = hashlib.sha256(content.encode('utf-8')).digest()
    print(f"  [{i}] sha256-{base64.b64encode(digest).decode('ascii')}")
    print(f"      erste Zeile: {content.strip().splitlines()[0][:60]}\n")

print("Diese Werte in die script-src-Direktive eintragen (mit einfachen Anführungszeichen).")
PY
