# Finanzwächter — Website

Website für **Florian Wächter**, Versicherungsvertretung nach § 34d Abs. 1 GewO.
Claim: *Klartext für Versicherungen, Vorsorge & Vermögen.*

Statisches HTML mit gehärtetem PHP-Kontaktformular. Kein Build-Schritt, keine
Paketabhängigkeiten, keine externen Ressourcen zur Laufzeit. Die Dateien können
unverändert auf jeden Webspace mit PHP 8.0 oder neuer geladen werden.

## Gestaltung

Die Seite übernimmt die Corporate Identity aus dem Markenbild: Tiefschwarz mit
Gold, das Signet aus Ring, Schild und **W**, die geschwungenen Goldbögen und
die vier Säulen **Schutz · Vorsorge · Vermögen · Unternehmen**.

Bewusste Entscheidung: **einfarbige Markenwelt, kein heller Modus.** Die Marke
ist dunkel; ein umschaltbares Erscheinungsbild würde die Wiedererkennung
brechen. Alle Farben werden explizit gesetzt, damit die Seite unabhängig vom
Systemdesign des Besuchers gleich aussieht.

| Rolle | Wert |
| --- | --- |
| Grundfläche | `#0B0B0F` |
| Karten | `#121218` |
| Gold (Verlauf) | `#F7E3A1` → `#E3BF62` → `#B8892B` |
| Schrift | `#F2EFE9` (warmes Weiß, weniger Nachleuchten als reines Weiß) |
| Überschriften | Sora 300–700 |
| Fließtext | IBM Plex Sans |
| Daten und Marken-Labels | IBM Plex Mono |

Die Schriften liegen unter `assets/fonts/` und werden **selbst ausgeliefert**.
Beim Einbinden über `fonts.googleapis.com` würde bei jedem Seitenaufruf die
IP-Adresse des Besuchers an Google übertragen — dieselbe Konstellation, die
das LG München I 2022 als Datenschutzverstoß gewertet hat. Beide Familien
stehen unter der SIL Open Font License (siehe `assets/fonts/LICENSE.txt`).

## Bewegung

Alle Effekte sind ohne externe Bibliothek gebaut und respektieren
`prefers-reduced-motion`:

| Element | Umsetzung |
| --- | --- |
| Knotennetz im Kopfbereich | Canvas 2D, reagiert auf den Zeiger |
| Drehendes Signet | Canvas 2D mit **selbst gerechneter 3D-Projektion** — Rotationsmatrix, perspektivische Teilung, Tiefensortierung über die Deckkraft |
| Karten | Neigen sich zum Zeiger (CSS-Perspektive), nur bei echtem Zeigegerät |
| Hauptschaltflächen | Folgen dem Zeiger leicht, Lichtreflex beim Zeigen |
| Abschnitte | Blenden beim Scrollen ein, mit Zeitschaltung als Sicherheitsnetz |
| Kennzahlen | Zählen beim Sichtbarwerden hoch |
| Fortschrittsbalken | Goldene Linie am oberen Rand |

Das 3D-Signet ersetzt eine 3D-Bibliothek wie Three.js (rund 600 KB). Das hält
die Seite schnell und die Content-Security-Policy eng — die gesamte Seite
kommt ohne Inline-Skripte und ohne fremde Hosts aus.

---

## ⚠️ Diese Website ist noch nicht veröffentlichungsreif

Sämtliche Rechtstexte enthalten **Platzhalter in der Form `[[…]]`**, die im
Browser rot markiert dargestellt werden. Sie müssen vor dem Livegang durch die
tatsächlichen Angaben ersetzt werden.

**Die Rechtstexte sind Vorlagen, keine Rechtsberatung.** Impressum,
Datenschutzerklärung und Erstinformation nach § 15 VersVermV müssen vor der
Veröffentlichung anwaltlich geprüft werden. Ein unvollständiges Impressum
ist abmahnfähig; unrichtige Angaben nach § 15 VersVermV können
gewerberechtliche Folgen haben.

Aktuellen Stand jederzeit prüfen mit:

```bash
tools/pruefen.sh
```

---

## Inhalt

| Datei / Ordner | Zweck |
| --- | --- |
| `index.html` | Startseite |
| `leistungen.html` | Die vier Säulen |
| `ablauf.html` | Beratungsablauf, Rechte des Kunden |
| `ueber-uns.html` | Person, Grundsätze, rechtlicher Rahmen |
| `kontakt.html` | Kontaktdaten und Formular |
| `kontakt-danke.html` / `kontakt-fehler.html` | Statusseiten für den Betrieb ohne JavaScript |
| `404.html` | Fehlerseite |
| `recht/impressum.html` | Pflichtangaben nach § 5 DDG, § 18 Abs. 2 MStV |
| `recht/datenschutz.html` | Informationen nach Art. 13, 14 DSGVO |
| `recht/erstinformation.html` | Erstinformation nach § 15 VersVermV |
| `recht/beschwerde.html` | Beschwerdewege und Schlichtungsstellen |
| `recht/barrierefreiheit.html` | Erklärung zur Barrierefreiheit |
| `assets/css/style.css` | Vollständiges Gestaltungssystem |
| `assets/js/boot.js` | Markiert früh, dass JavaScript verfügbar ist |
| `assets/js/main.js` | Navigation und Kopfbereich |
| `assets/js/motion.js` | Einblendungen, Zähler, Neigung, Fortschritt |
| `assets/js/hero-scene.js` | Knotennetz und 3D-Signet |
| `assets/js/form.js` | Formularprüfung im Browser |
| `assets/fonts/` | Selbst gehostete Schriften samt Lizenz |
| `assets/img/og-finanzwaechter.jpg` | Vorschaubild für Social Media |
| `api/` | Kontaktformular-Backend |
| `tools/pruefen.sh` | Prüfung vor dem Livegang |
| `tools/csp-hash.sh` | Hash für die Content-Security-Policy berechnen |
| `.htaccess` | Apache: Sicherheits-Header, Zugriffsschutz |
| `nginx.conf.example` | Entsprechung für nginx |

---

## Inbetriebnahme

### 1. Konfiguration anlegen

```bash
cp api/config.example.php api/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # Wert für ip_pepper
```

In `api/config.php` einzutragen:

| Feld | Bedeutung |
| --- | --- |
| `recipient` | Postfach, das die Anfragen erhält |
| `from` | Absenderadresse — **muss** zur eigenen Domain gehören, sonst scheitert SPF/DMARC |
| `allowed_hosts` | Alle Hostnamen, unter denen die Seite erreichbar ist |
| `ip_pepper` | Der eben erzeugte Zufallswert |
| `storage_ttl` | Löschfrist der Spamschutz-Daten — muss mit der Datenschutzerklärung übereinstimmen |

`api/config.php` steht in `.gitignore` und gehört nicht ins Repository.

### 2. Platzhalter ersetzen

```bash
grep -rn '\[\[' --include='*.html' .
```

Die vollständige Liste steht weiter unten unter „Platzhalter".

### 3. Domain eintragen

Die Domain `www.finanzwaechter.de` ist als Vorgabe hinterlegt und in diesen
Dateien anzupassen:

- `robots.txt`, `sitemap.xml`
- `<link rel="canonical">` und `og:url` in jeder HTML-Datei
- `allowed_hosts` in `api/config.php`
- der auskommentierte Umleitungsblock in `.htaccess`

```bash
# Beispiel: Domain über alle Dateien austauschen
grep -rl 'www.finanzwaechter.de' --include='*.html' --include='*.xml' --include='*.txt' . \
  | xargs sed -i 's|www\.finanzwaechter\.de|IHRE-DOMAIN.de|g'
```

### 4. Serverkonfiguration

**Apache:** `.htaccess` wird automatisch gelesen, sofern `AllowOverride All`
gesetzt ist. Nach dem Livegang die Header prüfen:

```bash
curl -sI https://IHRE-DOMAIN.de | grep -i -E 'content-security|x-frame|strict-transport'
```

**nginx:** `nginx.conf.example` als Vorlage verwenden. Die dortige
Anfragebegrenzung benötigt zusätzlich im `http`-Block:

```nginx
limit_req_zone $binary_remote_addr zone=formular:10m rate=10r/m;
```

**HSTS** ist in beiden Konfigurationen auskommentiert. Erst aktivieren, wenn
HTTPS dauerhaft und für alle Subdomains steht — ein voreiliges Aktivieren
macht die Domain für die angegebene Dauer unerreichbar, falls das Zertifikat
ausfällt.

### 5. Schreibrechte

```bash
chmod 750 api/storage
chown www-data:www-data api/storage    # Benutzer je nach Hoster anpassen
```

### 6. Abschlussprüfung

```bash
tools/pruefen.sh
```

---

## Platzhalter

| Platzhalter | Betrifft |
| --- | --- |
| `[[FIRMIERUNG]]` | Vollständige Firmierung inklusive Rechtsform |
| `[[NAME INHABER/IN]]`, `[[FUNKTION]]` | Vertretungsberechtigte Person |
| `[[STRASSE HAUSNR.]]`, `[[PLZ ORT]]` | Geschäftsanschrift |
| `[[TELEFON]]`, `[[E-MAIL]]`, `[[WWW-ADRESSE]]` | Kontaktdaten |
| `[[REGISTRIERUNGSNUMMER]]` | Nummer im Vermittlerregister (Format `D-XXXX-XXXXX-XX`) |
| `[[ZUSTÄNDIGE IHK]]` | Erlaubnisbehörde laut Erlaubnisbescheid |
| `[[VERSICHERER DER BERUFSHAFTPFLICHT]]` | Berufshaftpflicht nach § 34d Abs. 5 GewO |
| `[[NAME UND ANSCHRIFT DES HOSTERS]]` | Auftragsverarbeiter in der Datenschutzerklärung |
| `[[ZUSTÄNDIGE AUFSICHTSBEHÖRDE]]` | Datenschutzaufsicht nach Sitz des Unternehmens |
| `[[XX]]`, `[[XXX]]` | Kennzahlen auf der Startseite |

Zusätzlich stehen in den Rechtstexten **Entscheidungsplatzhalter**, bei denen
eine von mehreren Varianten zu wählen ist — etwa die Angabe nach § 36 VSBG,
die Beteiligungserklärung und der Vertreterstatus (Einfirmen- oder
Mehrfachvertreter). Diese sind im Text jeweils erläutert.

Ebenfalls zu ersetzen:

- `tel:+490000000000` — Vorgabewert in allen `tel:`-Verweisen
- `platzhalter@example.invalid` — Vorgabewert in allen `mailto:`-Verweisen
- Der Block mit strukturierten Daten am Ende von `index.html` — füllen oder entfernen

> Wird der Block mit strukturierten Daten geändert, verliert der Hash in der
> Content-Security-Policy seine Gültigkeit und der Browser blockiert den Block.
> Neuen Wert mit `tools/csp-hash.sh index.html` berechnen und in `.htaccess`
> sowie `nginx.conf.example` eintragen. Wird der Block entfernt, kann der Hash
> aus der Richtlinie gestrichen werden.

---

## Kontaktformular

### Ablauf

Mit JavaScript wird das Formular per `fetch` gesendet und die Antwort ohne
Seitenwechsel angezeigt. Ohne JavaScript erfolgt ein gewöhnlicher POST; der
Server leitet danach auf `kontakt-danke.html` oder `kontakt-fehler.html` um.
Beide Wege funktionieren vollständig.

### Schutzmaßnahmen

Die Prüfungen laufen in dieser Reihenfolge, von der günstigsten zur teuersten:

| # | Prüfung | Verhalten bei Verstoß |
| --- | --- | --- |
| 1 | Nur POST erlaubt | 405 |
| 2 | Herkunft (Origin/Referer) gegen `allowed_hosts` | 403 |
| 3 | CSRF-Token der Sitzung | 403 |
| 4 | Honeypot-Feld unausgefüllt | 200 mit Erfolgsmeldung (kein Versand) |
| 5 | Ausfüllzeit zwischen 3 s und 2 h | 200 mit Erfolgsmeldung (kein Versand) |
| 6 | Anfragebegrenzung je Absender und global | 429 |
| 7 | Inhaltliche Validierung | 422 mit Feldfehlern |

Zu 4 und 5: Automatisierte Einsendungen erhalten bewusst eine Erfolgsmeldung,
damit sie den Grund der Ablehnung nicht erkennen und ihr Vorgehen anpassen.

Weitere Maßnahmen:

- **Herkunftsprüfung als Fundament.** Browser senden bei seitenübergreifenden
  POST-Anfragen zwingend einen `Origin`-Header. Eine Anfrage ohne verwertbare
  Herkunft wird abgelehnt — auch mit gültigem Token.
- **Kein Freitext in Mail-Kopfzeilen.** Alle Eingaben werden von Steuerzeichen
  befreit; in die Kopfzeilen gelangen nur Konfigurationswerte und die geprüfte
  Absenderadresse als `Reply-To`. Header-Injection ist damit ausgeschlossen.
- **Keine Klartext-IP.** Für die Anfragebegrenzung wird ein HMAC der IP-Adresse
  mit geheimem Schlüssel gespeichert, nicht die Adresse selbst. Abgelaufene
  Einträge werden stichprobenartig gelöscht.
- **Schutz vor Direktaufruf.** Alle internen PHP-Dateien prüfen die Konstante
  `FW_ENTRYPOINT` und antworten sonst mit 404 — unabhängig davon, ob die
  Serverkonfiguration greift.
- **Keine Fehlerausgabe.** `display_errors` ist abgeschaltet; technische
  Details gehen ins Serverlog, nicht an den Browser.

### Bekannte Einschränkungen

- **`mail()` statt SMTP.** Um ohne Composer auszukommen, verwendet
  `api/lib/Mailer.php` die PHP-Funktion `mail()`. Für zuverlässige Zustellung
  sind **SPF**, **DKIM** und **DMARC** für die Absenderdomain einzurichten.
  Bei Zustellproblemen empfiehlt sich der Wechsel auf authentifizierten
  SMTP-Versand (etwa mit PHPMailer); auszutauschen ist dann allein die
  Methode `sendNotification()`.
- **Kein Token ohne JavaScript.** Das CSRF-Token wird per `fetch` von
  `api/token.php` geholt. Ohne JavaScript bleibt das Feld leer und der Schutz
  stützt sich auf die Herkunftsprüfung. Bei einem Kontaktformular ohne
  Anmeldung ist das vertretbar, weil ein Angreifer allenfalls das Absenden
  einer Anfrage im Namen eines Besuchers erreichen könnte.
- **Dateibasierte Anfragebegrenzung.** Ausreichend für einen einzelnen Server.
  Bei mehreren Anwendungsservern hinter einem Lastverteiler wäre ein
  gemeinsamer Speicher nötig.
- **Anfragebegrenzung je IP-Adresse.** Besucher hinter einem gemeinsamen
  Anschluss teilen sich das Kontingent. Die Vorgabe von drei Anfragen pro
  Stunde ist entsprechend gewählt.

### Testen

```bash
php -S 127.0.0.1:8000 -t .
```

Bei lokalen Tests `localhost` und `127.0.0.1` in `allowed_hosts` eintragen und
vor dem Livegang wieder entfernen. Der eingebaute Server liest keine
`.htaccess` — der Zugriffsschutz greift dort über die `FW_ENTRYPOINT`-Prüfung.

---

## Datenschutz

Im Auslieferungszustand setzt die Website:

- **keine** Analyse- oder Marketing-Cookies
- **keine** externen Schriftarten, Karten, Videos oder Social-Media-Einbindungen
- **kein** Content-Delivery-Netzwerk

Deshalb ist **kein Cookie-Banner erforderlich**. Verwendet werden nur:

| Speicherung | Zweck | Rechtsgrundlage |
| --- | --- | --- |
| Sitzungs-Cookie `fw_sess` | Missbrauchsschutz des Formulars | § 25 Abs. 2 Nr. 2 TDDDG — unbedingt erforderlich |
| — | Sonst nichts. Kein `localStorage`, keine Analyse, keine Einbettungen | — |

> **Sobald externe Dienste ergänzt werden**, wird die Datenschutzerklärung
> unrichtig. Dann sind anzupassen: die Erklärung selbst, die
> Content-Security-Policy — und bei Tracking zusätzlich eine
> Einwilligungslösung nach § 25 TDDDG, die vor dem Laden greift.

---

## Barrierefreiheit

Umgesetzt wurde:

- Bedienbarkeit allein über die Tastatur, sichtbare Fokusmarkierung
- Sprungmarke zum Hauptinhalt
- semantische Auszeichnung, genau eine `h1` je Seite, lückenlose Überschriftenfolge
- Formularfelder mit zugeordneten Beschriftungen und Fehlermeldungen über `aria-live`
- Berücksichtigung von `prefers-reduced-motion`
- alle Farbkombinationen auf **WCAG 2.1 AA** geprüft (Text ≥ 4,5:1, Bedienelemente ≥ 3:1)
- kein waagerechter Überlauf bei 320, 390, 768, 1024 und 1440 px Breite
- lange Fachwörter in Überschriften mit weichen Trennstellen (`&shy;`), damit
  sie mit Trennstrich umbrechen statt mitten im Wort

Nicht geleistet: eine Prüfung mit Screenreadern und assistiven Technologien.
Die Aussagen in `recht/barrierefreiheit.html` sind erst nach einer solchen
Prüfung belastbar — der dortige Platzhalter ist entsprechend gekennzeichnet.

Ob das Barrierefreiheitsstärkungsgesetz (BFSG) auf das Unternehmen anwendbar
ist, hängt unter anderem von der Kleinstunternehmen-Ausnahme ab und ist
gesondert zu klären.

---

## Pflege

**Nach Änderungen an CSS oder JavaScript:** Beide Dateien werden ein Jahr lang
im Browser zwischengespeichert. Damit wiederkehrende Besucher die neue Fassung
erhalten, den Dateinamen versionieren:

```bash
mv assets/css/style.css assets/css/style.v2.css
grep -rl 'style.css' --include='*.html' . | xargs sed -i 's|style\.css|style.v2.css|g'
```

**Nach Änderungen am Seitenkopf oder an der Fußzeile:** Beide Bereiche stehen
in jeder HTML-Datei einzeln. Änderungen über alle Seiten hinweg vornehmen:

```bash
grep -rn 'zu-aenderender-text' --include='*.html' .
```

**Neue Seite anlegen:** Eine bestehende Seite kopieren und `<title>`,
`<meta name="description">`, `<link rel="canonical">`, `og:*` sowie das
`aria-current="page"` in der Navigation anpassen. Anschließend in
`sitemap.xml` eintragen.

---

## Geprüft

Vor der Übergabe wurde geprüft:

- **HTML** — Struktur, Verschachtelung, doppelte `id`-Werte, Überschriftenfolge,
  Beschriftung aller Formularfelder: keine Befunde
- **Farbkontraste** — alle Kombinationen der Gold-auf-Schwarz-Palette gegen
  WCAG 2.1 AA: bestanden
- **Waagerechter Überlauf** — 13 Seiten × 5 Bildschirmbreiten automatisiert
  geprüft: keine Abweichung
- **Kontaktformular** — alle sieben Schutzstufen einzeln ausgelöst, dazu
  Header-Injection über Name und Adresse, Anfragebegrenzung, CSRF-Ablauf und
  der Betrieb ohne JavaScript
- **Interne Verweise** — alle Ziele vorhanden
- **Externe Ressourcen** — keine vorhanden
- **PHP** — Syntaxprüfung aller Dateien

Nicht geprüft: Darstellung in echten Browsern außer Chromium, Screenreader,
Zustellbarkeit der E-Mails (hängt von der Serverumgebung ab) und die
inhaltliche Richtigkeit der Rechtstexte.

## Verkaufspsychologie — bewusst gesetzte Grenze

Die Seite arbeitet mit den Mitteln, die bei erklärungsbedürftigen
Finanzdienstleistungen tatsächlich wirken: Autorität durch nachprüfbare
Nachweise (Erlaubnis, Registernummer), Reibungsabbau („kostenfrei",
„unverbindlich", „kein Abschluss im Erstgespräch"), vorweggenommene Einwände
im Fragenbereich, Transparenz bei der Vergütung und klar wiederholte
Handlungsaufforderungen.

Bewusst **nicht** eingesetzt: künstliche Verknappung, Countdown, erfundene
Bewertungen oder Kundenzahlen. Solche Mittel sind bei Versicherungsvermittlung
nach § 5 UWG angreifbar und würden dem Markenkern „Klartext" widersprechen —
sie würden die Seite schwächer machen, nicht stärker. Die Kennzahlen auf der
Startseite nennen deshalb nur belegbare Größen (0 €, 100 %, 24 h, § 34d).
