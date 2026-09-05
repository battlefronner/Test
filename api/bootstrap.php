<?php
declare(strict_types=1);

// Schutz vor direktem Aufruf. Wirkt unabhängig von der Serverkonfiguration
// und greift auch dann, wenn .htaccess oder die nginx-Regeln nicht aktiv sind.
if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Gemeinsame Initialisierung der API-Endpunkte.
 * Lädt die Konfiguration, setzt Sicherheitsvorgaben und stellt Hilfsfunktionen bereit.
 */

// Fehler niemals an den Browser ausgeben — sie können Pfade und Konfiguration verraten.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Der Dienst ist nicht vollständig eingerichtet. Bitte kontaktieren Sie uns telefonisch.',
    ], JSON_UNESCAPED_UNICODE);
    error_log('Kontaktformular: api/config.php fehlt. Bitte api/config.example.php kopieren.');
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configFile;

if (($config['debug'] ?? false) === true) {
    ini_set('display_errors', '1');
}

require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/Validator.php';
require_once __DIR__ . '/lib/Mailer.php';

/**
 * Antwortet als JSON und beendet die Ausführung.
 *
 * @param array<string,mixed> $payload
 */
function respond_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Erkennt, ob die Anfrage per fetch gestellt wurde und JSON erwartet. */
function wants_json(): bool
{
    if (($_POST['js'] ?? '') === '1') {
        return true;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        return true;
    }
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * Antwortet je nach Anfrageart als JSON oder per Weiterleitung auf eine
 * Statusseite, damit das Formular auch ohne JavaScript funktioniert.
 *
 * @param array<string,string> $fields
 */
function respond(int $status, string $state, string $message, array $fields = []): never
{
    if (wants_json()) {
        $payload = ['status' => $state, 'message' => $message];
        if ($fields !== []) {
            $payload['fields'] = $fields;
        }
        respond_json($status, $payload);
    }

    $target = $state === 'ok' ? '/kontakt-danke.html' : '/kontakt-fehler.html';
    header('Location: ' . $target, true, 303);
    exit;
}
