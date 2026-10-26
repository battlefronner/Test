#!/usr/bin/env bash
# =============================================================================
# Baut das Upload-Paket fuer den Webserver.
#
# Aufruf:  tools/paket.sh
#
# Ergebnis: dist/finanzwaechter/          — genau diese Dateien kommen auf den Server
#           dist/finanzwaechter-DATUM.zip — dasselbe als ZIP zum Hochladen
#
# Das Skript bricht ab, solange etwas fehlt, das nicht oeffentlich werden darf:
# leere Pflichtfelder, Platzhalter, Redaktionshinweise oder eine Konfiguration
# mit Beispielwerten. Was nicht auf den Server gehoert (Quellen, Werkzeuge,
# Anleitung, Git), wird gar nicht erst eingepackt.
# =============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."

ROT='\033[0;31m'; GELB='\033[0;33m'; GRUEN='\033[0;32m'; FETT='\033[1m'; AUS='\033[0m'
stopp() { printf "\n${ROT}${FETT}Abbruch:${AUS} %s\n" "$1"; exit 1; }
ok()    { printf "  ${GRUEN}OK${AUS}   %s\n" "$1"; }
hinw()  { printf "  ${GELB}HINW${AUS} %s\n" "$1"; }

printf "${FETT}1. Seiten im Livegang-Modus bauen${AUS}\n"
if ! python3 build/build.py --live; then
    stopp "Pflichtfelder in build/site.json sind noch leer (Liste oben)."
fi
ok "Alle Pflichtfelder gefuellt."

printf "\n${FETT}2. Inhalte pruefen${AUS}\n"
# Nur die fertigen Seiten pruefen — die Vorlagen in build/pages/ enthalten
# Redaktionshinweise und Ersatzwerte absichtlich.
SEITEN=(./*.html recht/*.html)
grep -lq '\[\['             "${SEITEN[@]}" && stopp "Es stehen noch Platzhalter [[…]] in den Seiten."
grep -lq 'class="ph"'       "${SEITEN[@]}" && stopp "Es stehen noch markierte Platzhalter in den Seiten."
grep -lq '<!--editor-->'    "${SEITEN[@]}" && stopp "Redaktionshinweise wurden nicht entfernt."
grep -lq 'example\.invalid' "${SEITEN[@]}" && stopp "Beispieladressen (example.invalid) in den Seiten."
ok "Keine Platzhalter, keine Redaktionshinweise in ${#SEITEN[@]} Seiten."

printf "\n${FETT}3. Formular-Konfiguration${AUS}\n"
if [ -f api/config.php ]; then
    if grep -q 'example\.invalid\|BITTE-ERSETZEN' api/config.php; then
        stopp "api/config.php enthaelt noch Beispielwerte (Empfaenger, Absender oder ip_pepper)."
    fi
    ok "api/config.php vorhanden und ausgefuellt — wird mit eingepackt."
    MIT_CONFIG=1
else
    hinw "api/config.php fehlt. Ohne sie funktioniert das Kontaktformular nicht."
    hinw "Anlegen mit:  cp api/config.example.php api/config.php  — dann ausfuellen."
    MIT_CONFIG=0
fi

printf "\n${FETT}4. Paket zusammenstellen${AUS}\n"
ZIEL="dist/finanzwaechter"
rm -rf "$ZIEL"; mkdir -p "$ZIEL"

# Nur was der Server braucht — eine Positivliste, keine Ausschlussliste.
cp ./*.html .htaccess robots.txt sitemap.xml favicon.svg site.webmanifest "$ZIEL/"
cp -r recht assets "$ZIEL/"
mkdir -p "$ZIEL/api/lib" "$ZIEL/api/storage"
cp api/bootstrap.php api/kontakt.php api/token.php "$ZIEL/api/"
cp api/lib/*.php "$ZIEL/api/lib/"
cp api/storage/.htaccess "$ZIEL/api/storage/"
# Verwaltung: Oberflaeche und die Quellen, aus denen sie die Seiten baut.
# build/build.py bleibt draussen — auf dem Server baut die PHP-Fassung.
mkdir -p "$ZIEL/verwaltung" "$ZIEL/build" "$ZIEL/assets/img/team"
cp verwaltung/index.php verwaltung/*.css verwaltung/*.js "$ZIEL/verwaltung/"
cp -r build/site.json build/pages build/partials build/extra "$ZIEL/build/"
: > "$ZIEL/api/storage/index.html"
[ "$MIT_CONFIG" -eq 1 ] && cp api/config.php "$ZIEL/api/"

anzahl=$(find "$ZIEL" -type f | wc -l | tr -d ' ')
groesse=$(du -sh "$ZIEL" | cut -f1)
ok "$anzahl Dateien, $groesse."

DATUM=$(date +%Y-%m-%d)
ZIP="dist/finanzwaechter-$DATUM.zip"
rm -f "$ZIP"
( cd dist && zip -qr "finanzwaechter-$DATUM.zip" finanzwaechter )
ok "ZIP erstellt: $ZIP"

printf "\n${FETT}Fertig.${AUS} Den INHALT von ${FETT}%s/${AUS} in das Webverzeichnis Ihres\n" "$ZIEL"
printf "Hosters hochladen (meist \"htdocs\", \"public_html\" oder der Ordner der Domain).\n"
printf "Wichtig: auch die versteckte Datei ${FETT}.htaccess${AUS} — viele FTP-Programme blenden sie aus.\n"
[ "$MIT_CONFIG" -eq 1 ] && printf "${GELB}Das Paket enthaelt api/config.php mit Ihren Zugangsdaten — nicht weitergeben.${AUS}\n"
exit 0
