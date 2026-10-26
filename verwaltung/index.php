<?php
/**
 * Verwaltung der Website — Inhalte pflegen, Vorschau ansehen, veröffentlichen.
 *
 * Aufruf im Browser: https://www.ihre-domain.de/verwaltung/
 * Einrichtung: README.md, Abschnitt „Verwaltung“.
 */
declare(strict_types=1);

define('FW_ENTRYPOINT', true);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header_remove('X-Powered-By');
date_default_timezone_set('Europe/Berlin');

$ROOT = dirname(__DIR__);

// Sicherheits-Header für alle Antworten der Verwaltung. Sie entsprechen der
// Website und verbieten zusätzlich das Zwischenspeichern und die Indexierung.
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('Cross-Origin-Opener-Policy: same-origin');

$configFile = $ROOT . '/api/config.php';
if (!is_file($configFile)) {
    ansicht_einfach('Noch nicht eingerichtet', '<p>Die Datei <code>api/config.php</code> fehlt. Sie entsteht aus <code>api/config.example.php</code> und wird für das Kontaktformular ohnehin gebraucht. Danach dort das Passwort der Verwaltung eintragen (siehe README, Abschnitt „Verwaltung“).</p>');
}
/** @var array<string,mixed> $config */
$config = require $configFile;

require_once $ROOT . '/api/lib/Security.php';
require_once $ROOT . '/api/lib/Baukasten.php';
require_once $ROOT . '/api/lib/Texte.php';
require_once $ROOT . '/api/lib/Foto.php';
require_once $ROOT . '/api/lib/Verwaltung.php';

$v = new Verwaltung($ROOT, $config);
$v->sitzungStarten();
$csrf = $v->security->csrfToken();

if ($v->httpsFehlt()) {
    ansicht_einfach('Nur über HTTPS', '<p>Die Verwaltung öffnet sich nur über eine verschlüsselte Verbindung. Bitte die Adresse mit <strong>https://</strong> aufrufen.</p>');
}

// ---------------------------------------------------------------------------
// Ersteinrichtung: Passwort-Hash erzeugen
// ---------------------------------------------------------------------------
if (!$v->eingerichtet()) {
    $hash = null;
    $fehler = null;
    if (($_POST['aktion'] ?? '') === 'hash') {
        if (!$v->postGueltig()) {
            $fehler = 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.';
        } elseif ($v->security->rateLimitExceeded(hash('sha256', 'verw-hash|' . $v->security->clientKey()), 10, 3600)) {
            $fehler = 'Zu viele Versuche. Bitte später erneut.';
        } else {
            $pw = (string) ($_POST['passwort'] ?? '');
            if (mb_strlen($pw) < 12) {
                $fehler = 'Bitte mindestens 12 Zeichen verwenden.';
            } elseif ($pw !== (string) ($_POST['passwort2'] ?? '')) {
                $fehler = 'Die beiden Eingaben stimmen nicht überein.';
            } else {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
            }
        }
    }
    $html = '<p>Die Verwaltung hat noch kein Passwort. So richten Sie es ein:</p>
<ol class="schritte"><li>Hier ein Passwort mit mindestens 12 Zeichen wählen. Es wird nicht gespeichert.</li>
<li>Die erzeugte Zeile in <code>api/config.php</code> eintragen (per FTP oder im Dateimanager des Hosters).</li>
<li>Diese Seite neu laden und anmelden.</li></ol>';
    if ($fehler) {
        $html .= '<p class="meldung meldung--fehler">' . e($fehler) . '</p>';
    }
    if ($hash) {
        $html .= '<p class="meldung meldung--ok">Diese Zeile in <code>api/config.php</code> einsetzen (die vorhandene Zeile <code>verwaltung_passwort_hash</code> ersetzen):</p>
<textarea class="code" readonly rows="3">\'verwaltung_passwort_hash\' => \'' . e($hash) . '\',</textarea>';
    }
    $html .= '<form method="post" class="karte stapel" autocomplete="off">
<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="aktion" value="hash">
<label class="feld"><span class="feld__label">Neues Passwort</span><input type="password" name="passwort" minlength="12" required autocomplete="new-password"></label>
<label class="feld"><span class="feld__label">Wiederholen</span><input type="password" name="passwort2" minlength="12" required autocomplete="new-password"></label>
<button class="knopf knopf--gold">Zeile erzeugen</button></form>';
    ansicht_einfach('Verwaltung einrichten', $html);
}

// ---------------------------------------------------------------------------
// Anmeldung
// ---------------------------------------------------------------------------
$aktion = (string) ($_POST['aktion'] ?? '');

if ($aktion === 'anmelden') {
    $ok = false;
    $meldung = 'Das Passwort stimmt nicht.';
    if (!$v->postGueltig()) {
        $meldung = 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.';
    } else {
        try {
            $ok = $v->anmelden((string) ($_POST['passwort'] ?? ''));
        } catch (RuntimeException $ex) {
            $meldung = $ex->getMessage();
        }
    }
    if ($ok) {
        if ($v->sicherungen() === []) {
            $v->sichern('ausgangsstand', 'Stand bei der ersten Anmeldung');
        }
        weiter('start');
    }
    $v->meldung('fehler', $meldung);
    weiter('anmelden');
}

$grund = null;
if (!$v->angemeldet($grund)) {
    if ($grund) {
        $v->meldung('hinweis', $grund);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        weiter('anmelden');
    }
    $html = meldungen_html($v->meldungen()) . '<form method="post" class="karte stapel" action="./">
<input type="hidden" name="csrf" value="' . e($v->security->csrfToken()) . '"><input type="hidden" name="aktion" value="anmelden">
<label class="feld"><span class="feld__label">Passwort</span><input type="password" name="passwort" required autocomplete="current-password" autofocus></label>
<button class="knopf knopf--gold">Anmelden</button></form>';
    ansicht_einfach('Anmelden', $html);
}

// ---------------------------------------------------------------------------
// Vorschau
// ---------------------------------------------------------------------------
if (isset($_GET['vorschau'])) {
    $rel = (string) $_GET['vorschau'];
    if (!in_array($rel, $v->alleSeiten(), true)) {
        http_response_code(404);
        exit('Seite nicht gefunden.');
    }
    $html = (new Baukasten($ROOT))->seite($rel, true)['html'];
    echo vorschau_aufbereiten($html, $rel);
    exit;
}

// ---------------------------------------------------------------------------
// Änderungen
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Größer als post_max_size: PHP verwirft dann die gesamte Anfrage
    $v->meldung('fehler', 'Die Datei ist zu groß für den Server (höchstens ' . upload_grenze() . '). Bitte ein kleineres Foto wählen.');
    weiter(preg_replace('/[^a-z]/', '', (string) ($_GET['s'] ?? 'start')) ?: 'start');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $zurueck = preg_replace('/[^a-z]/', '', (string) ($_POST['zurueck'] ?? 'start')) ?: 'start';
    $anker = '';
    if (!$v->postGueltig()) {
        $v->meldung('fehler', 'Die Sitzung ist abgelaufen oder die Anfrage kam nicht von dieser Seite. Bitte erneut versuchen.');
        weiter($zurueck);
    }
    try {
        $anker = $v->gesperrt(fn() => aktion_ausfuehren($v, $aktion));
    } catch (InvalidArgumentException | DomainException $ex) {
        $v->meldung('fehler', $ex->getMessage());
    } catch (RuntimeException $ex) {
        error_log('Verwaltung: ' . $ex->getMessage());
        $v->meldung('fehler', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('Verwaltung: ' . $ex);
        $v->meldung('fehler', 'Etwas ist schiefgegangen. Es wurde nichts verändert.');
    }
    $extra = '';
    if ($zurueck === 'texte' && isset($_POST['seite'])) {
        $extra = '&seite=' . rawurlencode((string) $_POST['seite']);
    }
    weiter($zurueck, $extra . (is_string($anker) ? $anker : ''));
}

/** Führt eine Änderung aus. Liefert optional einen Sprunganker. */
function aktion_ausfuehren(Verwaltung $v, string $aktion): string
{
    switch ($aktion) {
        case 'abmelden':
            $v->abmelden();
            $v->meldung('ok', 'Sie sind abgemeldet.');
            return '';

        case 'angaben':
            $site = $v->site();
            $felder = angaben_felder($v, $site);
            $eingang = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
            $geaendert = 0;
            foreach ($felder as $pfad => $f) {
                if (!array_key_exists($pfad, $eingang)) {
                    continue;
                }
                $wert = Verwaltung::eingabe($eingang[$pfad], $f['label'], $f['typ'] === 'textarea' ? 2000 : 300, $f['typ'] === 'textarea');
                if ($wert !== '' && $f['typ'] === 'email' && !filter_var($wert, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException($f['label'] . ': keine gültige E-Mail-Adresse.');
                }
                if ($wert !== '' && $f['typ'] === 'url' && !preg_match('#^https://[^\s"<>]+$#', $wert)) {
                    throw new InvalidArgumentException($f['label'] . ': bitte vollständig mit https:// angeben.');
                }
                if ($f['typ'] === 'grundlage' && !in_array($wert, Verwaltung::GRUNDLAGEN, true) && $wert !== $f['wert']) {
                    throw new InvalidArgumentException('Bitte einen der vorgegebenen Status wählen.');
                }
                if ($pfad === 'web.domain') {
                    $wert = rtrim($wert, '/');
                }
                if (pfad_lesen($site, $pfad) !== $wert) {
                    pfad_setzen($site, $pfad, $wert);
                    $geaendert++;
                }
            }
            // Wählnummern für Telefon-Links folgen der angezeigten Nummer
            foreach (['telefon', 'mobil'] as $k) {
                if (isset($site['kontakt'][$k]) && array_key_exists($k . '_link', $site['kontakt'])) {
                    $site['kontakt'][$k . '_link'] = Verwaltung::telLink((string) $site['kontakt'][$k]);
                }
            }
            if ($geaendert > 0) {
                $v->siteSpeichern($site);
                $v->meldung('ok', $geaendert === 1 ? 'Eine Angabe gespeichert.' : $geaendert . ' Angaben gespeichert.');
            } else {
                $v->meldung('hinweis', 'Es gab nichts zu speichern.');
            }
            return '';

        case 'texte':
            return texte_speichern($v);

        case 'person':
        case 'person_neu':
        case 'person_loeschen':
        case 'person_hoch':
        case 'foto_entfernen':
            return team_aendern($v, $aktion);

        case 'standort':
        case 'standort_neu':
        case 'standort_loeschen':
        case 'standort_haupt':
            return standorte_aendern($v, $aktion);

        case 'veroeffentlichen':
            $fehlend = $v->fehlend();
            if ($fehlend !== []) {
                throw new DomainException('Noch nicht veröffentlicht: ' . count($fehlend) . ' Pflichtangaben fehlen. Die Liste steht auf der Übersicht.');
            }
            $r = $v->veroeffentlichen();
            $v->meldung('ok', 'Veröffentlicht. ' . ($r['geaendert'] === 0 ? 'Die Seiten waren bereits aktuell.' : ($r['geaendert'] === 1 ? 'Eine Seite wurde aktualisiert.' : $r['geaendert'] . ' Seiten wurden aktualisiert.')));
            foreach ($r['hinweise'] as $h) {
                $v->meldung('hinweis', $h);
            }
            return '';

        case 'wiederherstellen':
            $v->wiederherstellen((string) ($_POST['id'] ?? ''));
            $v->meldung('ok', 'Stand wiederhergestellt. Er ist noch nicht veröffentlicht — erst Vorschau prüfen, dann veröffentlichen.');
            return '';

        case 'verwerfen':
            $l = $v->letzteVeroeffentlichung();
            if ($l === null) {
                throw new DomainException('Es gibt noch keine Veröffentlichung, zu der zurückgekehrt werden könnte.');
            }
            $v->wiederherstellen($l['id']);
            $v->meldung('ok', 'Änderungen verworfen. Der Stand der letzten Veröffentlichung ist wiederhergestellt.');
            return '';
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function texte_speichern(Verwaltung $v): string
{
    $rel = (string) ($_POST['seite'] ?? '');
    if (!in_array($rel, $v->bearbeitbareSeiten(), true)) {
        throw new InvalidArgumentException('Diese Seite kann hier nicht bearbeitet werden.');
    }
    $src = $v->vorlage($rel);
    $elemente = Texte::finde($src);
    $neu = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
    $hashes = is_array($_POST['h'] ?? null) ? $_POST['h'] : [];

    $aenderungen = [];
    foreach ($neu as $i => $html) {
        $i = (int) $i;
        if (!isset($elemente[$i]) || !is_string($html)) {
            throw new DomainException('Die Seite wurde inzwischen verändert. Bitte neu laden.');
        }
        $el = $elemente[$i];
        if (!hash_equals($el['hash'], (string) ($hashes[$i] ?? ''))) {
            throw new DomainException('Die Seite wurde inzwischen verändert. Bitte neu laden und die Änderung wiederholen.');
        }
        $ueber = in_array($el['tag'], ['h1', 'h2', 'h3', 'h4', 'summary', 'dt', 'th'], true);
        $sauber = Texte::bereinige($html, Texte::festeBausteine($el['teile']), $ueber);
        $alt = Texte::bereinige(Texte::editorHtml($el['teile'], fn($c) => ''), Texte::festeBausteine($el['teile']), $ueber);
        if ($sauber !== $alt) {
            $aenderungen[] = [$el['start'], $el['ende'], $sauber];
        }
    }
    // Von hinten nach vorn einsetzen, damit die Positionen gültig bleiben
    usort($aenderungen, fn($a, $b) => $b[0] <=> $a[0]);
    foreach ($aenderungen as [$s, $e, $html]) {
        $src = Texte::einsetzen($src, $s, $e, $html);
    }

    [$meta] = Baukasten::meta($src);
    $metaNeu = 0;
    foreach (['title' => 'Seitentitel', 'desc' => 'Beschreibung'] as $feld => $label) {
        if (!isset($_POST['meta'][$feld])) {
            continue;
        }
        $wert = Verwaltung::eingabe($_POST['meta'][$feld], $label, $feld === 'title' ? 90 : 300);
        if (preg_match('/[<>]|--/', $wert)) {
            throw new InvalidArgumentException($label . ': die Zeichen < > und -- sind nicht erlaubt.');
        }
        if ($wert === '') {
            throw new InvalidArgumentException($label . ' darf nicht leer sein.');
        }
        if (($meta[$feld] ?? '') !== $wert) {
            $src = Texte::metaSetzen($src, $feld, $wert);
            $metaNeu++;
        }
    }

    $anzahl = count($aenderungen) + $metaNeu;
    if ($anzahl === 0) {
        $v->meldung('hinweis', 'Es gab nichts zu speichern.');
        return '';
    }
    // Probebau: die geänderte Vorlage muss sich fehlerfrei zusammensetzen lassen
    Baukasten::meta($src);
    $pfad = $v->root . '/build/pages/' . $rel;
    $alt = $v->vorlage($rel);
    Verwaltung::schreiben($pfad, $src);
    try {
        (new Baukasten($v->root))->seite($rel, false);
    } catch (Throwable $ex) {
        Verwaltung::schreiben($pfad, $alt);
        throw new RuntimeException('Die Änderung hätte die Seite beschädigt und wurde nicht gespeichert.');
    }
    $v->meldung('ok', $anzahl === 1 ? 'Ein Text gespeichert.' : $anzahl . ' Texte gespeichert.');
    return '';
}

function team_aendern(Verwaltung $v, string $aktion): string
{
    $site = $v->site();
    $team = is_array($site['team'] ?? null) ? array_values($site['team']) : [];
    $i = (int) ($_POST['i'] ?? -1);
    if ($aktion !== 'person_neu' && !isset($team[$i])) {
        throw new DomainException('Diese Person gibt es nicht mehr. Bitte neu laden.');
    }
    $felder = function (array $p): array {
        $p['name'] = Verwaltung::eingabe($_POST['name'] ?? '', 'Name', 80);
        $p['rolle'] = Verwaltung::eingabe($_POST['rolle'] ?? '', 'Kennzeichnung', 40);
        $p['funktion'] = Verwaltung::eingabe($_POST['funktion'] ?? '', 'Funktion', 120);
        $p['text'] = Verwaltung::eingabe($_POST['text'] ?? '', 'Kurzprofil', 700, true);
        if ($p['name'] === '') {
            throw new InvalidArgumentException('Bitte einen Namen eintragen.');
        }
        return $p;
    };
    $foto = fn(): ?string => (isset($_FILES['foto']) && (int) ($_FILES['foto']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE)
        ? Foto::speichern($_FILES['foto'], $v->root) : null;

    switch ($aktion) {
        case 'person_neu':
            $p = $felder(['name' => '', 'rolle' => 'Team', 'funktion' => '', 'text' => '', 'foto' => '']);
            $p['foto'] = $foto() ?? '';
            $team[] = $p;
            $i = count($team) - 1;
            $v->meldung('ok', $p['name'] . ' wurde hinzugefügt.');
            break;
        case 'person':
            $team[$i] = $felder($team[$i]);
            $neu = $foto();
            if ($neu !== null) {
                $team[$i]['foto'] = $neu;
            }
            $v->meldung('ok', 'Gespeichert' . ($neu !== null ? ', das Foto ist eingesetzt.' : '.'));
            break;
        case 'foto_entfernen':
            $team[$i]['foto'] = '';
            $v->meldung('ok', 'Foto entfernt.');
            break;
        case 'person_loeschen':
            if (count($team) <= 1) {
                throw new DomainException('Mindestens eine Person muss im Team bleiben.');
            }
            $name = (string) ($team[$i]['name'] ?? '');
            array_splice($team, $i, 1);
            $v->meldung('ok', $name . ' wurde aus dem Team entfernt.');
            $i = -1;
            break;
        case 'person_hoch':
            if ($i > 0) {
                [$team[$i - 1], $team[$i]] = [$team[$i], $team[$i - 1]];
                $i--;
            }
            break;
    }
    foreach ($team as $p) {
        if (($p['foto'] ?? '') !== '' && !Foto::gueltig((string) $p['foto'])) {
            throw new DomainException('Ungültiger Fotopfad.');
        }
    }
    $site['team'] = $team;
    $v->siteSpeichern($site);
    return $i >= 0 ? '#person-' . $i : '';
}

function standorte_aendern(Verwaltung $v, string $aktion): string
{
    $site = $v->site();
    $liste = is_array($site['standorte'] ?? null) ? array_values($site['standorte']) : [];
    $i = (int) ($_POST['i'] ?? -1);
    if ($aktion !== 'standort_neu' && !isset($liste[$i])) {
        throw new DomainException('Diesen Standort gibt es nicht mehr. Bitte neu laden.');
    }
    $felder = function (array $s): array {
        $s['name'] = Verwaltung::eingabe($_POST['name'] ?? '', 'Name', 60);
        $s['titel'] = Verwaltung::eingabe($_POST['titel'] ?? '', 'Bezeichnung', 80);
        $s['strasse'] = Verwaltung::eingabe($_POST['strasse'] ?? '', 'Straße', 100);
        $s['plz'] = Verwaltung::eingabe($_POST['plz'] ?? '', 'PLZ', 10);
        $s['ort'] = Verwaltung::eingabe($_POST['ort'] ?? '', 'Ort', 60);
        $s['region'] = Verwaltung::eingabe($_POST['region'] ?? '', 'Region', 120);
        $s['telefon'] = Verwaltung::eingabe($_POST['telefon'] ?? '', 'Telefon', 40);
        $s['email'] = Verwaltung::eingabe($_POST['email'] ?? '', 'E-Mail', 120);
        foreach (['name' => 'Name', 'strasse' => 'Straße', 'plz' => 'PLZ', 'ort' => 'Ort'] as $k => $l) {
            if ($s[$k] === '') {
                throw new InvalidArgumentException('Bitte ' . $l . ' eintragen.');
            }
        }
        if (!preg_match('/^\d{5}$/', $s['plz'])) {
            throw new InvalidArgumentException('Die PLZ hat fünf Ziffern.');
        }
        if ($s['email'] !== '' && !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Die E-Mail-Adresse ist ungültig.');
        }
        if ($s['titel'] === '') {
            $s['titel'] = $s['name'];
        }
        return $s;
    };
    switch ($aktion) {
        case 'standort_neu':
            $s = $felder(['id' => '', 'name' => '', 'titel' => '', 'strasse' => '', 'plz' => '', 'ort' => '', 'region' => '', 'telefon' => '', 'email' => '', 'hauptsitz' => false]);
            $liste[] = $s;
            $i = count($liste) - 1;
            $v->meldung('ok', 'Standort ' . $s['name'] . ' hinzugefügt.');
            break;
        case 'standort':
            $liste[$i] = $felder($liste[$i]);
            $v->meldung('ok', 'Standort gespeichert.');
            break;
        case 'standort_loeschen':
            if (count($liste) <= 1) {
                throw new DomainException('Der letzte Standort kann nicht entfernt werden — seine Anschrift steht im Impressum.');
            }
            $war = !empty($liste[$i]['hauptsitz']);
            array_splice($liste, $i, 1);
            if ($war) {
                $liste[0]['hauptsitz'] = true;
                $v->meldung('hinweis', 'Der Hauptsitz wurde entfernt. Neuer Hauptsitz: ' . ($liste[0]['name'] ?? '') . '. Bitte prüfen — diese Anschrift steht im Impressum.');
            } else {
                $v->meldung('ok', 'Standort entfernt.');
            }
            $i = -1;
            break;
        case 'standort_haupt':
            foreach ($liste as $k => $s) {
                $liste[$k]['hauptsitz'] = ($k === $i);
            }
            $v->meldung('ok', ($liste[$i]['name'] ?? '') . ' ist jetzt der Hauptsitz. Impressum und Erstinformation nennen ab der nächsten Veröffentlichung diese Anschrift.');
            break;
    }
    // Kennungen eindeutig halten; der Hauptsitz steht vorn
    $ids = [];
    foreach ($liste as $k => $s) {
        $id = Verwaltung::slug((string) ($s['name'] ?? ''));
        $basis = $id;
        $n = 2;
        while (in_array($id, $ids, true)) {
            $id = $basis . '-' . $n++;
        }
        $ids[] = $id;
        $liste[$k]['id'] = $id;
        $liste[$k]['hauptsitz'] = (bool) ($s['hauptsitz'] ?? false);
    }
    $site['standorte'] = $liste;
    $v->siteSpeichern($site);
    return $i >= 0 ? '#standort-' . $i : '';
}

// ---------------------------------------------------------------------------
// Ansichten
// ---------------------------------------------------------------------------
$bereich = (string) ($_GET['s'] ?? 'start');
$bereiche = ['start' => 'Übersicht', 'angaben' => 'Kontakt & Recht', 'texte' => 'Texte', 'team' => 'Team & Fotos', 'standorte' => 'Standorte', 'sicherungen' => 'Sicherungen'];
if (!isset($bereiche[$bereich])) {
    $bereich = 'start';
}

$site = $v->site();
$inhalt = match ($bereich) {
    'start' => ansicht_start($v, $site),
    'angaben' => ansicht_angaben($v, $site),
    'texte' => ansicht_texte($v, $site),
    'team' => ansicht_team($v, $site),
    'standorte' => ansicht_standorte($v, $site),
    'sicherungen' => ansicht_sicherungen($v),
};
ansicht_rahmen($v, $bereich, $bereiche, $inhalt);

// ---------------------------------------------------------------------------

function ansicht_start(Verwaltung $v, array $site): string
{
    $fehlend = $v->fehlend();
    $offen = $v->entwurfOffen();
    $letzte = $v->letzteVeroeffentlichung();
    $h = '<section class="stapel">';
    $h .= '<div class="karte status ' . ($offen === false ? 'status--ok' : 'status--offen') . '"><div>';
    if ($offen === null) {
        $h .= '<p class="status__titel">Noch nicht über die Verwaltung veröffentlicht</p><p>Auf der Website steht der zuletzt hochgeladene Stand.</p>';
    } elseif ($offen) {
        $h .= '<p class="status__titel">Unveröffentlichte Änderungen</p><p>Die Website zeigt noch den Stand vom ' . e(datum($letzte['zeit'] ?? 0)) . '.</p>';
    } else {
        $h .= '<p class="status__titel">Alles veröffentlicht</p><p>Stand vom ' . e(datum($letzte['zeit'] ?? 0)) . '.</p>';
    }
    $h .= '</div><div class="reihe">' . vorschau_link('index.html', 'Vorschau ansehen') . veroeffentlichen_form($fehlend) . '</div></div>';

    if ($fehlend !== []) {
        $h .= '<div class="karte"><h2>Vor dem Veröffentlichen noch ausfüllen</h2><p class="leise">Diese Angaben verlangt das Gesetz oder die Seite braucht sie. Solange eines fehlt, bleibt die Website unverändert.</p><ul class="fehlliste">';
        $labels = angaben_felder($v, $site);
        foreach ($fehlend as $f) {
            if (str_starts_with($f, '.')) {
                $team = in_array($f, ['.name', '.rolle', '.funktion', '.text'], true);
                $h .= '<li><a href="?s=' . ($team ? 'team' : 'standorte') . '">' . ($team ? 'Team' : 'Standorte') . ': Feld „' . e(ltrim($f, '.')) . '“</a></li>';
            } else {
                $l = $labels[$f]['label'] ?? $f;
                $gruppe = $labels[$f]['gruppe'] ?? '';
                $h .= '<li><a href="?s=angaben#' . e(feld_id($f)) . '">' . e($l) . '</a>' . ($gruppe ? ' <span class="leise">· ' . e($gruppe) . '</span>' : '') . '</li>';
            }
        }
        $h .= '</ul></div>';
    }

    $schutz = $v->schreibschutz();
    if ($schutz !== []) {
        $h .= '<div class="meldung meldung--fehler"><strong>Fehlende Schreibrechte.</strong> Der Webserver darf diese Dateien nicht ändern: ' . e(implode(', ', array_slice($schutz, 0, 8))) . (count($schutz) > 8 ? ' …' : '') . '. Bitte beim Hoster die Rechte anpassen (README, „Verwaltung“).</div>';
    }

    $kacheln = [
        ['angaben', 'Kontakt & Recht', 'Telefon, E-Mail, Registerangaben, Rechtstexte'],
        ['texte', 'Texte', 'Überschriften und Absätze der Seiten'],
        ['team', 'Team & Fotos', count((array) ($site['team'] ?? [])) . ' Person(en)'],
        ['standorte', 'Standorte', count((array) ($site['standorte'] ?? [])) . ' Standort(e)'],
    ];
    $h .= '<div class="kacheln">';
    foreach ($kacheln as [$s, $t, $d]) {
        $h .= '<a class="kachel" href="?s=' . $s . '"><span class="kachel__t">' . e($t) . '</span><span class="leise">' . e($d) . '</span></a>';
    }
    $h .= '</div>';
    $h .= '<p class="leise klein">So funktioniert es: Änderungen werden sofort gespeichert, sind aber erst nach „Veröffentlichen“ auf der Website zu sehen. Vor jeder Veröffentlichung entsteht eine Sicherung.</p>';
    return $h . '</section>';
}

function veroeffentlichen_form(array $fehlend): string
{
    $gesperrt = $fehlend !== [];
    return '<form method="post"><input type="hidden" name="csrf" value="' . e($_SESSION['csrf_token']) . '"><input type="hidden" name="aktion" value="veroeffentlichen"><input type="hidden" name="zurueck" value="start">'
        . '<button class="knopf knopf--gold"' . ($gesperrt ? ' disabled title="Erst die fehlenden Angaben ausfüllen"' : ' data-confirm="Jetzt alle Seiten veröffentlichen?"') . '>Veröffentlichen</button></form>';
}

/**
 * Felder für „Kontakt & Recht“: alle Textangaben aus site.json, die auf der
 * Website tatsächlich vorkommen. Neue Felder in site.json erscheinen damit
 * automatisch, sobald eine Vorlage sie verwendet.
 *
 * @return array<string, array{label:string, gruppe:string, typ:string, hinweis:string, wert:string}>
 */
function angaben_felder(Verwaltung $v, array $site): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $benutzt = [];
    foreach (['pages', 'pages/recht', 'partials', 'extra'] as $d) {
        foreach (glob($v->root . '/build/' . $d . '/*.html') ?: [] as $f) {
            preg_match_all('/\{\{[@?!]?([a-z_][\w.]*)/u', (string) file_get_contents($f), $m);
            foreach ($m[1] as $p) {
                $benutzt[$p] = true;
            }
        }
    }
    $gruppen = ['web' => 'Marke', 'firma' => 'Unternehmen', 'kontakt' => 'Kontakt', 'register' => 'Vermittlerstatus & Register',
                'ihk' => 'Zuständige IHK', 'recht' => 'Rechtliche Angaben', 'behoerden' => 'Behörden & Schlichtungsstellen'];
    $felder = [];
    $sammeln = function (array $knoten, string $pfad, string $gruppe) use (&$sammeln, &$felder, $benutzt): void {
        foreach ($knoten as $k => $wert) {
            $k = (string) $k;
            if (str_starts_with($k, '_') || str_ends_with($k, '_link')) {
                continue;
            }
            $p = $pfad . '.' . $k;
            if (is_array($wert) && !array_is_list($wert)) {
                $sammeln($wert, $p, $gruppe . ' · ' . (string) ($wert['name'] ?? $k));
                continue;
            }
            if (!is_string($wert) || !isset($benutzt[$p])) {
                continue;
            }
            $felder[$p] = [
                'label' => feld_label($p), 'gruppe' => $gruppe, 'typ' => feld_typ($p, $wert),
                'hinweis' => is_string($knoten['_' . $k] ?? null) ? $knoten['_' . $k] : '', 'wert' => $wert,
            ];
        }
    };
    foreach ($gruppen as $key => $titel) {
        if (is_array($site[$key] ?? null)) {
            $sammeln($site[$key], $key, $titel);
        }
    }
    return $cache = $felder;
}

function feld_label(string $p): string
{
    static $l = [
        'web.name' => 'Markenname', 'web.zusatz' => 'Zeile unter dem Schriftzug', 'web.domain' => 'Adresse der Website',
        'firma.firmierung' => 'Firmierung', 'firma.inhaber' => 'Inhaber / vertretungsberechtigte Person',
        'kontakt.telefon' => 'Telefon', 'kontakt.mobil' => 'Mobil', 'kontakt.email' => 'E-Mail', 'kontakt.sprechzeiten' => 'Erreichbarkeit',
        'register.nummer' => 'Registrierungsnummer', 'register.grundlage' => 'Status als Vermittler',
        'register.vertretung' => 'Versicherer, für den Sie tätig sind', 'register.beteiligungen' => 'Beteiligungen',
        'register.haftpflicht_versicherer' => 'Berufshaftpflicht: Versicherer', 'register.haftpflicht_gebiet' => 'Berufshaftpflicht: Geltungsbereich',
        'recht.handelsregister' => 'Handelsregister', 'recht.ustid' => 'Umsatzsteuer-ID', 'recht.vsbg' => 'Verbraucherstreitbeilegung (§ 36 VSBG)',
        'recht.datenschutzbeauftragter' => 'Datenschutzbeauftragter', 'recht.hoster' => 'Hoster (Name und Anschrift)',
        'recht.logfile_tage' => 'Speicherdauer der Server-Logfiles', 'recht.weitere_auftragsverarbeiter' => 'Weitere Auftragsverarbeiter',
        'recht.drittland' => 'Übermittlung in Drittländer', 'recht.empfehlung_verguetung' => 'Empfehlung: Gibt es eine Gegenleistung?',
        'recht.spamschutz_frist' => 'Löschfrist Spamschutz', 'recht.anfragen_loeschfrist' => 'Löschfrist Anfragen',
        'recht.beschwerde_frist' => 'Antwortfrist bei Beschwerden', 'recht.stand' => 'Stand der Rechtstexte',
        'recht.bf_status' => 'Barrierefreiheit: Status', 'recht.bf_einschraenkungen' => 'Barrierefreiheit: Einschränkungen',
        'recht.bf_datum' => 'Barrierefreiheit: Datum der Erklärung', 'recht.bf_grundlage' => 'Barrierefreiheit: Grundlage',
        'recht.bf_schlichtung' => 'Barrierefreiheit: Schlichtung',
    ];
    static $teil = ['name' => 'Name', 'zusatz' => 'Zusatz', 'strasse' => 'Straße', 'plz' => 'PLZ', 'ort' => 'Ort', 'telefon' => 'Telefon',
                    'telefon_hinweis' => 'Telefonkosten', 'web' => 'Website', 'email' => 'E-Mail', 'postfach' => 'Postfach', 'postfach_plz' => 'PLZ des Postfachs'];
    if (isset($l[$p])) {
        return $l[$p];
    }
    $k = substr($p, strrpos($p, '.') + 1);
    return $teil[$k] ?? ucfirst(str_replace('_', ' ', $k));
}

function feld_typ(string $p, string $wert): string
{
    $k = substr($p, strrpos($p, '.') + 1);
    if ($p === 'register.grundlage') {
        return 'grundlage';
    }
    if ($k === 'email') {
        return 'email';
    }
    if ($k === 'web' || $k === 'domain') {
        return 'url';
    }
    if (in_array($k, ['telefon', 'mobil'], true)) {
        return 'tel';
    }
    $lang = ['beteiligungen', 'vsbg', 'drittland', 'empfehlung_verguetung', 'bf_status', 'bf_einschraenkungen', 'bf_schlichtung', 'weitere_auftragsverarbeiter', 'hoster', 'datenschutzbeauftragter'];
    return (in_array($k, $lang, true) || mb_strlen($wert) > 80) ? 'textarea' : 'text';
}

function feld_id(string $p): string
{
    return 'f-' . str_replace(['.', '_'], '-', $p);
}

function ansicht_angaben(Verwaltung $v, array $site): string
{
    $felder = angaben_felder($v, $site);
    $fehlend = array_flip($v->fehlend());
    $h = '<form method="post" class="stapel" data-warnen>' . versteckt('angaben', 'angaben');
    $gruppe = null;
    $offen = false;
    foreach ($felder as $p => $f) {
            if ($f['gruppe'] !== $gruppe) {
            if ($offen) {
                $h .= '</div></details>';
            }
            $behoerde = str_starts_with($p, 'behoerden.');
            $hat = false;
            foreach ($felder as $q => $g) {
                if ($g['gruppe'] === $f['gruppe'] && isset($fehlend[$q])) {
                    $hat = true;
                }
            }
            $h .= '<details class="gruppe karte"' . (($behoerde && !$hat) ? '' : ' open') . '><summary><h2>' . e($f['gruppe']) . '</h2>'
                . ($hat ? '<span class="marke marke--fehlt">fehlt</span>' : '') . '</summary><div class="stapel">';
            if ($p === 'behoerden.dihk.name') {
                $h .= '<p class="leise">Diese Anschriften ändern sich selten. Sie wurden recherchiert; vor dem Livegang einmal gegenprüfen.</p>';
            }
            $gruppe = $f['gruppe'];
            $offen = true;
        }
        $h .= feld_html($p, $f, isset($fehlend[$p]));
    }
    if ($offen) {
        $h .= '</div></details>';
    }
    $h .= '<div class="leiste"><button class="knopf knopf--gold">Speichern</button><span class="leise klein">Sichtbar nach dem Veröffentlichen</span></div></form>';
    return $h;
}

function feld_html(string $p, array $f, bool $fehlt): string
{
    $id = feld_id($p);
    $name = 'f[' . e($p) . ']';
    $h = '<div class="feld' . ($fehlt ? ' feld--fehlt' : '') . '" id="' . e($id) . '-box"><label class="feld__label" for="' . e($id) . '">' . e($f['label'])
       . ($fehlt ? ' <span class="marke marke--fehlt">fehlt</span>' : '') . '</label>';
    if ($f['typ'] === 'grundlage') {
        $h .= '<select id="' . e($id) . '" name="' . $name . '">';
        $opts = Verwaltung::GRUNDLAGEN;
        if ($f['wert'] !== '' && !in_array($f['wert'], $opts, true)) {
            array_unshift($opts, $f['wert']);
        }
        foreach ($opts as $o) {
            $h .= '<option' . ($o === $f['wert'] ? ' selected' : '') . '>' . e($o) . '</option>';
        }
        $h .= '</select>';
    } elseif ($f['typ'] === 'textarea') {
        $h .= '<textarea id="' . e($id) . '" name="' . $name . '" rows="3">' . e($f['wert']) . '</textarea>';
    } else {
        $typ = ['email' => 'email', 'url' => 'url', 'tel' => 'tel'][$f['typ']] ?? 'text';
        $h .= '<input id="' . e($id) . '" name="' . $name . '" type="' . $typ . '" value="' . e($f['wert']) . '"'
            . ($typ === 'tel' ? ' inputmode="tel"' : '') . ($typ === 'email' ? ' autocomplete="off"' : '') . '>';
    }
    if ($f['hinweis'] !== '') {
        $h .= '<p class="feld__hinweis">' . e($f['hinweis']) . '</p>';
    }
    return $h . '</div>';
}

function ansicht_texte(Verwaltung $v, array $site): string
{
    $seiten = $v->bearbeitbareSeiten();
    $rel = (string) ($_GET['seite'] ?? '');
    $namen = [];
    foreach ($seiten as $s) {
        [$m] = Baukasten::meta($v->vorlage($s));
        $namen[$s] = trim(explode('—', $m['title'] ?? $s)[0]);
    }
    if (!in_array($rel, $seiten, true)) {
        $h = '<div class="karte"><h2>Welche Seite?</h2><p class="leise">Rechtstexte (Impressum, Datenschutz …) stellen sich aus den Angaben unter „Kontakt &amp; Recht“ selbst zusammen und werden hier nicht bearbeitet.</p><ul class="seitenliste">';
        foreach ($namen as $s => $n) {
            $h .= '<li><a href="?s=texte&amp;seite=' . e(rawurlencode($s)) . '"><span>' . e($n) . '</span><span class="leise mono">/' . e($s === 'index.html' ? '' : $s) . '</span></a></li>';
        }
        return $h . '</ul></div>';
    }
    $src = $v->vorlage($rel);
    [$meta] = Baukasten::meta($src);
    $b = new Baukasten($v->root);
    $daten = $b->daten();
    $anzeige = function (string $code) use ($daten): string {
        preg_match('/^\{\{([\w.]+)(?:\|([^}]*))?\}\}$/u', $code, $m);
        $wert = $daten;
        foreach (explode('.', $m[1] ?? '') as $teil) {
            $wert = is_array($wert) && array_key_exists($teil, $wert) ? $wert[$teil] : null;
        }
        return is_scalar($wert) && (string) $wert !== '' ? (string) $wert : '[' . ($m[2] ?? $m[1] ?? '') . ']';
    };

    $h = '<p><a href="?s=texte" class="zurueck">← Alle Seiten</a></p>';
    $h .= '<form method="post" class="stapel" id="texte-form" data-warnen>' . versteckt('texte', 'texte') . '<input type="hidden" name="seite" value="' . e($rel) . '">';
    $h .= '<div class="reihe reihe--zwischen"><h2>' . e($namen[$rel]) . '</h2>' . vorschau_link($rel, 'Vorschau') . '</div>'
        . '<details class="karte"><summary><h2>Titel und Beschreibung bei Google</h2></summary><div class="stapel"><div class="feld"><label class="feld__label" for="m-title">Seitentitel bei Google</label><input id="m-title" name="meta[title]" value="' . e($meta['title'] ?? '') . '" maxlength="90" data-zaehler="60"><p class="feld__hinweis">Erscheint im Browser-Tab und als Überschrift im Suchergebnis. Ideal bis 60 Zeichen.</p></div>'
        . '<div class="feld"><label class="feld__label" for="m-desc">Beschreibung bei Google</label><textarea id="m-desc" name="meta[desc]" rows="3" maxlength="300" data-zaehler="155">' . e($meta['desc'] ?? '') . '</textarea><p class="feld__hinweis">Der Text unter dem Titel im Suchergebnis. Ideal 120 bis 155 Zeichen.</p></div></div></details>';

    $h .= '<noscript><p class="meldung meldung--fehler">Zum Bearbeiten der Texte bitte JavaScript einschalten.</p></noscript>';
    $h .= '<div class="werkzeug" role="toolbar" aria-label="Textauszeichnung"><button type="button" data-cmd="bold" title="Fett"><strong>F</strong></button><button type="button" data-cmd="italic" title="Kursiv"><em>K</em></button><button type="button" data-cmd="gold" title="Gold hervorheben"><span class="gold">Gold</span></button><button type="button" data-cmd="plain" title="Auszeichnung entfernen">Normal</button><span class="leise klein">Graue Bausteine setzt die Seite selbst ein (z. B. Ort, Telefonnummer, Links).</span></div>';
    $h .= '<div class="texte">';
    foreach (Texte::finde($src) as $i => $el) {
        $ueber = in_array($el['tag'], ['h1', 'h2', 'h3', 'h4'], true);
        $h .= '<div class="te-box te-box--' . e($el['tag']) . '"><span class="te-art">' . e(Texte::ART[$el['tag']] ?? $el['tag']) . '</span>'
            . '<div class="te' . ($ueber ? ' te--ueber' : '') . '" contenteditable="true" spellcheck="true" lang="de" role="textbox" aria-multiline="' . ($ueber ? 'false' : 'true') . '"'
            . ' aria-label="' . e(Texte::ART[$el['tag']] ?? 'Text') . ' ' . ($i + 1) . '" data-i="' . $i . '" data-hash="' . e($el['hash']) . '"' . ($ueber || $el['tag'] === 'summary' ? ' data-einzeilig' : '') . '>'
            . Texte::editorHtml($el['teile'], $anzeige) . '</div></div>';
    }
    $h .= '</div><div class="leiste"><button class="knopf knopf--gold">Texte speichern</button><span class="leise klein" data-geaendert>Keine Änderungen</span></div></form>';
    return $h;
}

function ansicht_team(Verwaltung $v, array $site): string
{
    $team = is_array($site['team'] ?? null) ? array_values($site['team']) : [];
    $h = '<p class="leise">Erscheint auf der Seite „Über uns“. Ein Foto im Hochformat wirkt am besten; es wird automatisch auf 4 : 5 zugeschnitten und verkleinert. Metadaten wie Aufnahmeort werden dabei entfernt.</p>';
    foreach ($team as $i => $p) {
        $h .= '<form method="post" enctype="multipart/form-data" class="karte person-karte" id="person-' . $i . '" data-warnen>' . versteckt('person', 'team')
            . '<input type="hidden" name="i" value="' . $i . '"><div class="person-karte__foto">';
        $h .= ($p['foto'] ?? '') !== '' && Foto::gueltig((string) $p['foto'])
            ? '<img src="/' . e((string) $p['foto']) . '" alt="" width="150" height="188">'
            : '<div class="foto-leer" aria-hidden="true">' . e(mb_substr((string) ($p['name'] ?? '?'), 0, 1)) . '</div>';
        $h .= '<label class="knopf knopf--linie knopf--klein datei"><input type="file" name="foto" accept="image/jpeg,image/png,image/webp" data-datei><span>Foto wählen</span></label><span class="leise klein" data-dateiname></span></div><div class="stapel">'
            . team_feld('name', 'Name', $p, 80, true)
            . team_feld('rolle', 'Kennzeichnung über dem Namen', $p, 40, false, 'z. B. Gründer, Team, Assistenz')
            . team_feld('funktion', 'Funktion', $p, 120, false, 'z. B. „Gründer und Inhaber“. Geschützte Titel wie „Versicherungsfachmann (IHK)“ nur mit abgelegter Prüfung.')
            . '<div class="feld"><label class="feld__label" for="t-text-' . $i . '">Kurzprofil <span class="leise">(freiwillig)</span></label><textarea id="t-text-' . $i . '" name="text" rows="4" maxlength="700">' . e((string) ($p['text'] ?? '')) . '</textarea><p class="feld__hinweis">Zwei bis vier Sätze: Werdegang, Schwerpunkt, was Ihnen wichtig ist.</p></div>'
            . '<div class="reihe"><button class="knopf knopf--gold">Speichern</button>';
        if (($p['foto'] ?? '') !== '') {
            $h .= '<button class="knopf knopf--linie" name="aktion" value="foto_entfernen" data-confirm="Foto entfernen?">Foto entfernen</button>';
        }
        if ($i > 0) {
            $h .= '<button class="knopf knopf--linie" name="aktion" value="person_hoch" title="Nach oben">↑ Nach oben</button>';
        }
        if (count($team) > 1) {
            $h .= '<button class="knopf knopf--linie knopf--gefahr" name="aktion" value="person_loeschen" data-confirm="' . e((string) ($p['name'] ?? '')) . ' aus dem Team entfernen?">Entfernen</button>';
        }
        $h .= '</div></div></form>';
    }
    $h .= '<details class="karte"><summary><h2>Person hinzufügen</h2></summary><form method="post" enctype="multipart/form-data" class="stapel">' . versteckt('person_neu', 'team')
        . team_feld('name', 'Name', [], 80, true) . team_feld('rolle', 'Kennzeichnung über dem Namen', ['rolle' => 'Team'], 40, false)
        . team_feld('funktion', 'Funktion', [], 120, false)
        . '<div class="feld"><label class="feld__label" for="neu-text">Kurzprofil</label><textarea id="neu-text" name="text" rows="3" maxlength="700"></textarea></div>'
        . '<div class="feld"><span class="feld__label">Foto <span class="leise">(freiwillig)</span></span><label class="knopf knopf--linie knopf--klein datei"><input type="file" name="foto" accept="image/jpeg,image/png,image/webp" data-datei><span>Foto wählen</span></label><span class="leise klein" data-dateiname></span></div>'
        . '<div class="reihe"><button class="knopf knopf--gold">Hinzufügen</button></div></form></details>';
    return '<section class="stapel">' . $h . '</section>';
}

function team_feld(string $k, string $label, array $p, int $max, bool $pflicht, string $hinweis = ''): string
{
    $id = 't-' . $k . '-' . bin2hex(random_bytes(3));
    return '<div class="feld"><label class="feld__label" for="' . $id . '">' . e($label) . '</label><input id="' . $id . '" name="' . $k . '" value="' . e((string) ($p[$k] ?? '')) . '" maxlength="' . $max . '"' . ($pflicht ? ' required' : '') . '>'
        . ($hinweis !== '' ? '<p class="feld__hinweis">' . e($hinweis) . '</p>' : '') . '</div>';
}

function ansicht_standorte(Verwaltung $v, array $site): string
{
    $liste = is_array($site['standorte'] ?? null) ? array_values($site['standorte']) : [];
    $h = '<p class="leise">Die Anschrift des Hauptsitzes steht in Impressum und Erstinformation. Alle Standorte erscheinen im Fußbereich und auf „Über uns“.</p>';
    $feldliste = [['name', 'Name', 'z. B. Marl', true], ['titel', 'Bezeichnung', 'z. B. Hauptsitz Marl', false], ['strasse', 'Straße und Hausnummer', '', true],
                  ['plz', 'PLZ', '', true], ['ort', 'Ort', '', true], ['region', 'Region', 'z. B. Kreis Recklinghausen', false],
                  ['telefon', 'Telefon dieses Standorts', 'freiwillig', false], ['email', 'E-Mail dieses Standorts', 'freiwillig', false]];
    $felder = function (array $s, string $pre) use ($feldliste): string {
        $o = '<div class="raster">';
        foreach ($feldliste as [$k, $l, $hint, $pflicht]) {
            $id = $pre . '-' . $k;
            $o .= '<div class="feld' . ($k === 'plz' ? ' feld--kurz' : '') . '"><label class="feld__label" for="' . e($id) . '">' . e($l) . '</label><input id="' . e($id) . '" name="' . $k . '" value="' . e((string) ($s[$k] ?? '')) . '"'
                . ($pflicht ? ' required' : '') . ($k === 'plz' ? ' inputmode="numeric" pattern="\d{5}" maxlength="5"' : '') . ($k === 'email' ? ' type="email"' : '') . ($k === 'telefon' ? ' type="tel"' : '')
                . ($hint !== '' ? ' placeholder="' . e($hint) . '"' : '') . '></div>';
        }
        return $o . '</div>';
    };
    foreach ($liste as $i => $s) {
        $haupt = !empty($s['hauptsitz']);
        $h .= '<form method="post" class="karte stapel" id="standort-' . $i . '" data-warnen>' . versteckt('standort', 'standorte') . '<input type="hidden" name="i" value="' . $i . '">'
            . '<div class="reihe reihe--zwischen"><h2>' . e((string) ($s['titel'] ?? $s['name'] ?? '')) . '</h2>' . ($haupt ? '<span class="marke">Hauptsitz</span>' : '') . '</div>'
            . $felder($s, 's' . $i) . '<div class="reihe"><button class="knopf knopf--gold">Speichern</button>';
        if (!$haupt) {
            $h .= '<button class="knopf knopf--linie" name="aktion" value="standort_haupt" data-confirm="Diesen Standort zum Hauptsitz machen? Seine Anschrift steht dann im Impressum.">Zum Hauptsitz machen</button>';
        }
        if (count($liste) > 1) {
            $h .= '<button class="knopf knopf--linie knopf--gefahr" name="aktion" value="standort_loeschen" formnovalidate data-confirm="Standort entfernen?">Entfernen</button>';
        }
        $h .= '</div></form>';
    }
    $h .= '<details class="karte"><summary><h2>Standort hinzufügen</h2></summary><form method="post" class="stapel">' . versteckt('standort_neu', 'standorte')
        . $felder([], 'neu') . '<div class="reihe"><button class="knopf knopf--gold">Hinzufügen</button></div></form></details>';
    return '<section class="stapel">' . $h . '</section>';
}

function ansicht_sicherungen(Verwaltung $v): string
{
    $liste = $v->sicherungen();
    $offen = $v->entwurfOffen();
    $h = '<p class="leise">Vor jeder Veröffentlichung und vor jedem Wiederherstellen wird der Stand gesichert. Die letzten 30 Sicherungen bleiben erhalten. Wiederherstellen ändert zunächst nur den Entwurf — veröffentlicht wird erst auf Knopfdruck.</p>';
    if ($offen) {
        $h .= '<form method="post" class="karte reihe reihe--zwischen">' . versteckt('verwerfen', 'sicherungen') . '<div><h2>Änderungen verwerfen</h2><p class="leise">Zurück zum Stand der letzten Veröffentlichung.</p></div><button class="knopf knopf--linie knopf--gefahr" data-confirm="Alle Änderungen seit der letzten Veröffentlichung verwerfen?">Verwerfen</button></form>';
    }
    if ($liste === []) {
        return $h . '<p class="karte">Noch keine Sicherungen. Die erste entsteht beim ersten Veröffentlichen.</p>';
    }
    $arten = ['veroeffentlicht' => 'Veröffentlicht', 'automatisch' => 'Automatisch', 'ausgangsstand' => 'Ausgangsstand'];
    $h .= '<ul class="karte sicherungen">';
    foreach ($liste as $s) {
        $h .= '<li><div><strong>' . e(datum($s['zeit'])) . '</strong> <span class="marke">' . e($arten[$s['art']] ?? $s['art']) . '</span>'
            . ($s['notiz'] !== '' ? '<br><span class="leise klein">' . e($s['notiz']) . '</span>' : '') . '</div>'
            . '<form method="post">' . versteckt('wiederherstellen', 'sicherungen') . '<input type="hidden" name="id" value="' . e($s['id']) . '"><button class="knopf knopf--linie knopf--klein" data-confirm="Diesen Stand als Entwurf wiederherstellen?">Wiederherstellen</button></form></li>';
    }
    return $h . '</ul>';
}

// ---------------------------------------------------------------------------
// Rahmen und Hilfsfunktionen
// ---------------------------------------------------------------------------

function ansicht_rahmen(Verwaltung $v, string $bereich, array $bereiche, string $inhalt): never
{
    $offen = $v->entwurfOffen();
    $nav = '';
    foreach ($bereiche as $k => $t) {
        $nav .= '<a href="?s=' . $k . '"' . ($k === $bereich ? ' aria-current="page"' : '') . '>' . e($t) . '</a>';
    }
    $status = $offen === false ? '<span class="punkt punkt--ok"></span>Veröffentlicht' : '<span class="punkt"></span>Entwurf';
    $html = '<header class="kopf"><div class="kopf__innen"><a class="marke-logo" href="?s=start"><img src="/assets/img/signet.svg" alt="" width="28" height="28"><span>Verwaltung</span></a>'
        . '<span class="kopf__status">' . $status . '</span>'
        . '<form method="post" class="kopf__ab"><input type="hidden" name="csrf" value="' . e($_SESSION['csrf_token']) . '"><input type="hidden" name="aktion" value="abmelden"><button class="knopf knopf--text">Abmelden</button></form></div>'
        . '<nav class="reiter" aria-label="Bereiche">' . $nav . '</nav></header>'
        . '<main class="haupt" id="inhalt"><h1>' . e($bereiche[$bereich]) . '</h1>' . meldungen_html($v->meldungen()) . $inhalt . '</main>';
    seite_ausgeben($bereiche[$bereich] . ' · Verwaltung', $html);
}

function ansicht_einfach(string $titel, string $inhalt): never
{
    seite_ausgeben($titel . ' · Verwaltung', '<main class="haupt haupt--schmal"><div class="marke-logo marke-logo--gross"><img src="/assets/img/signet.svg" alt="" width="40" height="40"><span>Verwaltung</span></div><h1>' . e($titel) . '</h1>' . $inhalt . '</main>');
}

function seite_ausgeben(string $titel, string $body): never
{
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($titel) . '</title>'
        . '<link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="verwaltung.css?v=1">'
        . '<script src="verwaltung.js?v=1" defer></script></head><body>' . $body . '</body></html>';
    exit;
}

function meldungen_html(array $meldungen): string
{
    $h = '';
    foreach ($meldungen as $m) {
        $art = in_array($m['art'] ?? '', ['ok', 'fehler', 'hinweis'], true) ? $m['art'] : 'hinweis';
        $h .= '<p class="meldung meldung--' . $art . '" role="' . ($art === 'fehler' ? 'alert' : 'status') . '">' . e((string) ($m['text'] ?? '')) . '</p>';
    }
    return $h;
}

function versteckt(string $aktion, string $zurueck): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf_token']) . '"><input type="hidden" name="aktion" value="' . e($aktion) . '"><input type="hidden" name="zurueck" value="' . e($zurueck) . '">';
}

function vorschau_link(string $rel, string $text): string
{
    return '<a class="knopf knopf--linie" href="?vorschau=' . e(rawurlencode($rel)) . '" target="_blank" rel="noopener">' . e($text) . ' ↗</a>';
}

/** Vorschau: Hinweisleiste einblenden, Links in der Vorschau halten, Formulare stilllegen. */
function vorschau_aufbereiten(string $html, string $rel): string
{
    $html = (string) preg_replace_callback('/href="\/((?:[a-z0-9-]+\/)?[a-z0-9-]+\.html)?(#[^"]*)?"/', function ($m) {
        $ziel = ($m[1] ?? '') === '' ? 'index.html' : $m[1];
        return 'href="/verwaltung/?vorschau=' . rawurlencode($ziel) . ($m[2] ?? '') . '"';
    }, $html);
    $leiste = '<div class="fw-vorschau" role="note"><strong>Vorschau</strong> · noch nicht veröffentlicht · <a href="/verwaltung/">Zur Verwaltung</a></div>';
    $html = (string) preg_replace('/<body>/', '<body>' . $leiste, $html, 1);
    return (string) preg_replace('/<\/head>/', '<link rel="stylesheet" href="/verwaltung/vorschau.css?v=1"><script src="/verwaltung/vorschau.js?v=1" defer></script></head>', $html, 1);
}

function weiter(string $bereich, string $extra = ''): never
{
    $ziel = $bereich === 'anmelden' ? './' : './?s=' . rawurlencode($bereich) . $extra;
    header('Location: ' . $ziel, true, 303);
    exit;
}

/** Kleinste der beiden Upload-Grenzen des Servers, lesbar. */
function upload_grenze(): string
{
    $b = function (string $w): int {
        $w = trim($w);
        $n = (int) $w;
        return match (strtolower(substr($w, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    $min = min($b((string) ini_get('upload_max_filesize')), $b((string) ini_get('post_max_size')));
    return $min >= 1 << 20 ? round($min / (1 << 20)) . ' MB' : round($min / 1024) . ' KB';
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function datum(int $t): string
{
    return $t > 0 ? date('d.m.Y, H:i', $t) . ' Uhr' : '—';
}

function pfad_lesen(array $a, string $pfad): mixed
{
    foreach (explode('.', $pfad) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return null;
        }
        $a = $a[$k];
    }
    return $a;
}

function pfad_setzen(array &$a, string $pfad, mixed $wert): void
{
    $ref = &$a;
    foreach (explode('.', $pfad) as $k) {
        $ref = &$ref[$k];
    }
    $ref = $wert;
}
