#!/usr/bin/env bash
# Prueft, dass build/build.py und der PHP-Baukasten der Verwaltung
# (api/lib/Baukasten.php) Byte fuer Byte dieselben Seiten erzeugen.
# Aufruf: tools/baukasten-vergleich.sh
set -euo pipefail
cd "$(dirname "$0")/.."
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
fehler=0
for modus in entwurf live; do
    flag=""; [ "$modus" = live ] && flag="--live"
    # Python schreibt ins Projekt — vorher sichern, nachher zuruecklegen
    cp -a . "$TMP/py-$modus"
    ( cd "$TMP/py-$modus" && python3 build/build.py $flag >/dev/null || true )
    php tools/baukasten-vergleich.php "$TMP/php-$modus" $flag >/dev/null
    for f in $(cd "$TMP/php-$modus" && find . -type f | sort); do
        if ! cmp -s "$TMP/py-$modus/$f" "$TMP/php-$modus/$f"; then
            echo "UNTERSCHIED ($modus): $f"; fehler=1
        fi
    done
done
[ $fehler -eq 0 ] && echo "Python und PHP bauen identisch (Entwurf und Livegang)."
exit $fehler
