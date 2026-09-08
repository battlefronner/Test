<?php
declare(strict_types=1);

/**
 * Verarbeitung des Kontaktformulars.
 *
 * Reihenfolge der Prüfungen — von der günstigsten zur teuersten, damit
 * offensichtlich ungültige Anfragen früh abgewiesen werden:
 *
 *   1. HTTP-Methode
 *   2. Herkunft (Origin/Referer)
 *   3. CSRF-Token, sofern eine Sitzung vorliegt
 *   4. Honeypot-Feld
 *   5. Ausfüllzeit
 *   6. Anfragebegrenzung je Absender und global
 *   7. Inhaltliche Validierung
 *   8. Versand
 */

/** Kennzeichnet diese Datei als zulässigen Einstiegspunkt (siehe bootstrap.php). */
define('FW_ENTRYPOINT', true);

require __DIR__ . '/bootstrap.php';

$security = new Security($config);

// --- 1. Nur POST -----------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'error', 'Diese Adresse nimmt nur Formularsendungen entgegen.');
}

// --- 2. Herkunft -----------------------------------------------------------
// Browser senden bei seitenübergreifenden POST-Anfragen zwingend einen
// Origin-Header. Eine Anfrage ohne verwertbare Herkunft wird abgelehnt.
if (!$security->originAllowed()) {
    respond(403, 'error', 'Die Anfrage konnte nicht zugeordnet werden. Bitte laden Sie die Seite neu.');
}

$security->startSession();

// Abgelaufene Spamschutz-Daten bei jeder Anfrage mit gültiger Herkunft aufräumen —
// unabhängig davon, ob die Anfrage danach angenommen oder abgewiesen wird. Der Lauf
// ist billig (ein Verzeichnis mit wenigen kleinen Dateien) und sorgt dafür, dass die
// in der Datenschutzerklärung genannte Löschfrist auch bei wenig Verkehr gilt.
$security->purgeExpired();

// --- 3. CSRF-Token ---------------------------------------------------------
// Das Token wird per JavaScript geholt. Fehlt in der Sitzung ein Token,
// wurde die Seite ohne JavaScript genutzt; dann trägt die Herkunftsprüfung
// aus Schritt 2 den Schutz.
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionHasToken = !empty($_SESSION['csrf_token']);

if ($sessionHasToken && !$security->csrfTokenValid($submittedToken)) {
    respond(403, 'error', 'Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
}

// --- 4. Honeypot -----------------------------------------------------------
// Ein für Menschen unsichtbares Feld. Ist es ausgefüllt, war ein Bot am Werk.
// Antwort bewusst wie bei Erfolg, damit der Bot keinen Rückschluss zieht.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    error_log('Kontaktformular: Honeypot ausgelöst.');
    respond(200, 'ok', 'Vielen Dank. Ihre Nachricht ist eingegangen.');
}

// --- 5. Ausfüllzeit --------------------------------------------------------
// Maßgeblich ist der Zeitpunkt, den der Server beim Ausliefern des Tokens
// vermerkt hat. Ein Wert aus dem Formular waere die Uhr des Besuchers: ein Bot
// koennte das Feld einfach weglassen, und eine falsch gestellte Uhr wuerde
// echte Anfragen aussortieren. Liegt kein Serververmerk vor, wurde die Seite
// ohne JavaScript benutzt — dann tragen Herkunftspruefung und Honigtopf.
$now = time();
$minSeconds = (int) ($config['min_fill_seconds'] ?? 3);
$maxSeconds = (int) ($config['max_fill_seconds'] ?? 7200);
$ausgabe = $security->ausgabeZeit();

if ($ausgabe !== null) {
    $elapsed = $now - $ausgabe;
    if ($elapsed < $minSeconds) {
        error_log('Kontaktformular: Zeitfalle ausgelöst (zu schnell abgesendet).');
        respond(200, 'ok', 'Vielen Dank. Ihre Nachricht ist eingegangen.');
    }
    if ($elapsed > $maxSeconds) {
        respond(400, 'error', 'Das Formular war zu lange geöffnet. Bitte laden Sie die Seite neu.');
    }
}

// --- 6. Anfragebegrenzung (Teil 1: Versuche) -------------------------------
// Gezaehlt wird zweistufig. Hier laufen zunaechst nur die Versuche: grosszuegig
// bemessen, damit ein paar Tippfehler niemanden aussperren, aber eng genug, um
// ein Durchprobieren zu stoppen. Das eigentliche Kontingent fuer abgeschickte
// Anfragen folgt weiter unten, nach der Pruefung der Eingaben.
$clientKey = $security->clientKey();

if ($security->rateLimitExceeded(
    hash_hmac('sha256', 'versuche', $clientKey),
    (int) ($config['rate_limit_attempts'] ?? 15),
    (int) ($config['rate_limit_window'] ?? 3600)
)) {
    respond(429, 'error', 'Es wurden bereits viele Versuche von Ihnen gesendet. Bitte versuchen Sie es später erneut oder rufen Sie uns an.');
}

if ($security->rateLimitExceeded(
    hash('sha256', 'global'),
    (int) ($config['rate_limit_global'] ?? 60),
    (int) ($config['rate_limit_global_window'] ?? 3600)
)) {
    error_log('Kontaktformular: globale Anfragegrenze erreicht.');
    respond(429, 'error', 'Das Formular ist derzeit stark ausgelastet. Bitte versuchen Sie es später erneut oder rufen Sie uns an.');
}

// --- 7. Validierung --------------------------------------------------------
$validator = new Validator();

if (!$validator->validate($_POST)) {
    respond(422, 'error', 'Bitte korrigieren Sie die markierten Felder.', $validator->errors());
}

$data = $validator->clean();

// --- 7b. Anfragebegrenzung (Teil 2: tatsaechliche Anfragen) ----------------
if ($security->rateLimitExceeded(
    $clientKey,
    (int) ($config['rate_limit_per_ip'] ?? 3),
    (int) ($config['rate_limit_window'] ?? 3600)
)) {
    respond(429, 'error', 'Es wurden bereits mehrere Anfragen von Ihnen gesendet. Bitte versuchen Sie es später erneut oder rufen Sie uns an.');
}

// --- 8. Versand ------------------------------------------------------------
$mailer = new Mailer($config);
$sent = $mailer->sendNotification($data, $clientKey);

// Optionale Ablage als Rückfallebene, falls der Mailversand ausfällt.
if (($config['archive_submissions'] ?? false) === true) {
    $dir = rtrim((string) $config['storage_dir'], '/') . '/anfragen';
    if (is_dir($dir) || @mkdir($dir, 0750, true)) {
        // Sperrdateien in genau diesem Verzeichnis. Eine Sperre im
        // uebergeordneten Verzeichnis wirkt nur bei Apache — nginx liest
        // keine .htaccess.
        $security->guard($dir);
        if ($security->inWebroot($dir)) {
            error_log('Kontaktformular: storage_dir liegt im Webverzeichnis. '
                . 'Bitte in der Konfiguration auf einen Pfad ausserhalb des '
                . 'Webverzeichnisses umstellen.');
        }
        // 16 zufaellige Bytes statt 4: der Dateiname darf nicht erratbar sein,
        // falls der Verzeichnisschutz irgendwann ausfaellt.
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(16)) . '.json';
        @file_put_contents(
            $file,
            json_encode(
                $data + ['eingegangen' => date('c'), 'versendet' => $sent],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ),
            LOCK_EX
        );
        @chmod($file, 0640);
    }
}

if (!$sent) {
    respond(
        500,
        'error',
        'Ihre Nachricht konnte technisch nicht zugestellt werden. Bitte rufen Sie mich an oder schreiben Sie mir direkt per E-Mail.'
    );
}

// Token nach erfolgreichem Versand erneuern, damit dieselbe Sendung
// nicht wiederholt werden kann. Die Sitzungskennung wird gleich mit
// gewechselt — kostet nichts und raeumt eine untergeschobene Kennung ab.
unset($_SESSION['csrf_token'], $_SESSION['form_ts']);
$security->rotateSession();

respond(
    200,
    'ok',
    'Vielen Dank für Ihre Nachricht. Ich melde mich in der Regel innerhalb eines Werktages bei Ihnen.'
);
