#!/usr/bin/env bash
# =============================================================================
# Prüft die Website vor dem Livegang.
#
# Aufruf:  tools/pruefen.sh
#
# Geprüft wird:
#   - offene Platzhalter in HTML-Dateien
#   - PHP-Syntax
#   - vorhandene Konfigurationsdatei und ersetzter Signaturschlüssel
#   - interne Verweise auf nicht existierende Dateien
#   - versehentlich eingebundene externe Ressourcen
#   - Übereinstimmung des CSP-Hashes mit dem Inline-Skript
# =============================================================================

set -uo pipefail
cd "$(dirname "$0")/.."

ROT='\033[0;31m'; GELB='\033[0;33m'; GRUEN='\033[0;32m'; AUS='\033[0m'
fehler=0; warnungen=0

titel() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()    { printf "  ${GRUEN}OK${AUS}   %s\n" "$1"; }
warn()  { printf "  ${GELB}HINW${AUS} %s\n" "$1"; warnungen=$((warnungen+1)); }
fail()  { printf "  ${ROT}FEHL${AUS} %s\n" "$1"; fehler=$((fehler+1)); }

# ---------------------------------------------------------------------------
titel "1. Offene Platzhalter"
anzahl=$(grep -ro '\[\[' --include=*.html . 2>/dev/null | wc -l | tr -d ' ')
if [ "$anzahl" -gt 0 ]; then
    warn "$anzahl Platzhalter noch nicht ersetzt. Betroffene Dateien:"
    grep -rc '\[\[' --include=*.html . 2>/dev/null | grep -v ':0$' | sed 's/^/         /'
else
    ok "Keine Platzhalter mehr vorhanden."
fi

for muster in 'example.invalid' 'tel:+490000000000' 'BITTE-ERSETZEN'; do
    if grep -rq "$muster" --include=*.html --include=*.php . 2>/dev/null; then
        warn "Vorgabewert \"$muster\" noch vorhanden."
    fi
done

# ---------------------------------------------------------------------------
titel "2. PHP-Syntax"
if command -v php >/dev/null 2>&1; then
    php_fehler=0
    while IFS= read -r datei; do
        if ! php -l "$datei" >/dev/null 2>&1; then
            fail "Syntaxfehler in $datei"
            php -l "$datei" 2>&1 | sed 's/^/         /'
            php_fehler=1
        fi
    done < <(find api -name '*.php' -type f)
    [ "$php_fehler" -eq 0 ] && ok "Alle PHP-Dateien fehlerfrei."
else
    warn "PHP nicht verfügbar — Syntaxprüfung übersprungen."
fi

# ---------------------------------------------------------------------------
titel "3. Konfiguration"
if [ -f api/config.php ]; then
    ok "api/config.php vorhanden."
    if grep -q 'BITTE-ERSETZEN' api/config.php 2>/dev/null; then
        fail "Der Signaturschlüssel (ip_pepper) wurde nicht ersetzt."
    else
        ok "Signaturschlüssel wurde gesetzt."
    fi
    if grep -q "'debug' *=> *true" api/config.php 2>/dev/null; then
        fail "debug ist aktiv — im Produktivbetrieb auf false setzen."
    fi
    if grep -q 'example.invalid' api/config.php 2>/dev/null; then
        fail "In api/config.php stehen noch Beispiel-E-Mail-Adressen."
    fi
else
    fail "api/config.php fehlt. Anlegen mit: cp api/config.example.php api/config.php"
fi

if git check-ignore -q api/config.php 2>/dev/null; then
    ok "api/config.php wird von Git ignoriert."
else
    warn "api/config.php ist nicht von Git ausgeschlossen — .gitignore prüfen."
fi

# ---------------------------------------------------------------------------
titel "4. Interne Verweise"
tote=0
while IFS= read -r datei; do
    while IFS= read -r ziel; do
        [ -z "$ziel" ] && continue
        pfad="${ziel%%#*}"
        [ -z "$pfad" ] && continue
        [ "$pfad" = "/" ] && continue
        if [ ! -e ".${pfad}" ]; then
            fail "$datei verweist auf fehlende Datei: $pfad"
            tote=$((tote+1))
        fi
    done < <(grep -o 'href="/[^"#][^"]*"' "$datei" 2>/dev/null | sed 's/href="//; s/"$//' | sort -u)
done < <(find . -name '*.html' -not -path './.git/*')
[ "$tote" -eq 0 ] && ok "Alle internen Verweise zeigen auf vorhandene Dateien."

# ---------------------------------------------------------------------------
titel "5. Externe Ressourcen"
extern=$(grep -roE '(src|href)="https?://[^"]*"' --include=*.html . 2>/dev/null \
    | grep -vE 'vermittlerregister\.info|gesetze-im-internet\.de|versicherungsombudsmann\.de|pkv-ombudsmann\.de|bafin\.de|schema\.org|finanzwaechter\.de|www\.w3\.org' || true)
if [ -n "$extern" ]; then
    warn "Externe Einbindungen gefunden — Datenschutzerklärung und CSP prüfen:"
    printf '%s\n' "$extern" | sed 's/^/         /'
else
    ok "Keine externen Ressourcen eingebunden."
fi

# ---------------------------------------------------------------------------
titel "6. Content-Security-Policy"
if command -v python3 >/dev/null 2>&1 && [ -f .htaccess ]; then
    ist=$(python3 - <<'PY'
import base64, hashlib, re
try:
    html = open('index.html', encoding='utf-8').read()
except OSError:
    print(''); raise SystemExit
blocks = [b for b in re.findall(r'<script[^>]*>(.*?)</script>', html, re.DOTALL) if b.strip()]
if not blocks:
    print('')
else:
    print('sha256-' + base64.b64encode(hashlib.sha256(blocks[0].encode('utf-8')).digest()).decode())
PY
)
    if [ -z "$ist" ]; then
        ok "Kein Inline-Skript in index.html — kein Hash nötig."
    elif grep -q "$ist" .htaccess 2>/dev/null; then
        ok "CSP-Hash stimmt mit dem Inline-Skript überein."
    else
        fail "CSP-Hash veraltet. Erwartet: '$ist' — neu eintragen (siehe tools/csp-hash.sh)."
    fi
fi

# ---------------------------------------------------------------------------
titel "7. Pflichtseiten"
for seite in recht/impressum.html recht/datenschutz.html recht/erstinformation.html; do
    if [ -f "$seite" ]; then ok "$seite vorhanden."; else fail "$seite fehlt."; fi
done

for seite in index.html leistungen.html kontakt.html; do
    if grep -q 'href="/recht/impressum.html"' "$seite" 2>/dev/null \
    && grep -q 'href="/recht/datenschutz.html"' "$seite" 2>/dev/null; then
        ok "$seite verlinkt Impressum und Datenschutz."
    else
        fail "$seite verlinkt nicht beide Pflichtseiten."
    fi
done

# ---------------------------------------------------------------------------
titel "Ergebnis"
printf "  %d Fehler, %d Hinweise\n\n" "$fehler" "$warnungen"
if [ "$fehler" -gt 0 ]; then
    printf "${ROT}Nicht bereit für den Livegang.${AUS}\n\n"
    exit 1
fi
if [ "$warnungen" -gt 0 ]; then
    printf "${GELB}Technisch lauffähig, aber es sind noch Inhalte zu ergänzen.${AUS}\n\n"
    exit 0
fi
printf "${GRUEN}Alle Prüfungen bestanden.${AUS}\n\n"
