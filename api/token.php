<?php
declare(strict_types=1);

/**
 * Liefert das CSRF-Token der aktuellen Sitzung.
 * Wird vom Kontaktformular per JavaScript abgerufen.
 */

/** Kennzeichnet diese Datei als zulässigen Einstiegspunkt (siehe bootstrap.php). */
define('FW_ENTRYPOINT', true);

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond_json(405, ['status' => 'error', 'message' => 'Methode nicht erlaubt.']);
}

$security = new Security($config);

if (!$security->originAllowed()) {
    respond_json(403, ['status' => 'error', 'message' => 'Ungültige Herkunft der Anfrage.']);
}

$security->startSession();

respond_json(200, ['token' => $security->csrfToken()]);
