<?php
/**
 * Konfiguration des Kontaktformulars.
 *
 * WICHTIG: Diese Datei nach `api/config.php` kopieren und dort die echten Werte
 * eintragen. `api/config.php` ist bewusst in .gitignore aufgeführt, damit
 * E-Mail-Adressen und der Signaturschlüssel nicht im Repository landen.
 *
 *   cp api/config.example.php api/config.php
 */

declare(strict_types=1);

// Diese Datei wird ausschließlich von api/bootstrap.php eingebunden.
if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

return [

    // ---------------------------------------------------------------------
    // Empfänger und Absender
    // ---------------------------------------------------------------------

    /** Wohin die Formularanfragen zugestellt werden. */
    'recipient' => 'anfragen@example.invalid',

    /**
     * Absenderadresse der Benachrichtigung. MUSS eine Adresse der eigenen
     * Domain sein, sonst scheitert die SPF-/DMARC-Prüfung und die Mail landet
     * im Spam. Niemals die Adresse des Absenders aus dem Formular eintragen.
     */
    'from'       => 'website@example.invalid',
    'from_name'  => 'Finanzwaechter Website',

    /** Betreffpräfix zur Filterung im Postfach. */
    'subject_prefix' => '[Website]',

    // ---------------------------------------------------------------------
    // Sicherheit
    // ---------------------------------------------------------------------

    /**
     * Erlaubte Hosts für Origin-/Referer-Prüfung (ohne Schema, ohne Port).
     * Nur Anfragen von diesen Hosts werden angenommen.
     */
    'allowed_hosts' => [
        'www.finanzwaechter.de',
        'finanzwaechter.de',
    ],

    /**
     * Zufälliger Schlüssel zum Hashen der IP-Adresse (Pepper). Damit ist der
     * gespeicherte Wert nicht auf die IP zurückrechenbar.
     *
     * Erzeugen mit:  php -r "echo bin2hex(random_bytes(32));"
     */
    'ip_pepper' => 'BITTE-ERSETZEN-mit-64-zufaelligen-Hex-Zeichen',

    /** Mindest-/Höchstzeit zwischen Seitenaufbau und Absenden (Sekunden). */
    'min_fill_seconds' => 3,
    'max_fill_seconds' => 7200,

    // ---------------------------------------------------------------------
    // Anfragebegrenzung
    // ---------------------------------------------------------------------

    /** Höchstzahl an Absendungen je Absender innerhalb des Zeitfensters. */
    'rate_limit_per_ip'      => 3,
    'rate_limit_window'      => 3600,   // 1 Stunde

    /** Notbremse über alle Absender hinweg (Schutz vor verteiltem Spam). */
    'rate_limit_global'      => 60,
    'rate_limit_global_window' => 3600,

    /**
     * Aufbewahrungsdauer der Spamschutz-Daten in Sekunden.
     * Dieser Wert muss mit der Angabe in der Datenschutzerklärung übereinstimmen.
     */
    'storage_ttl' => 86400,             // 24 Stunden

    // ---------------------------------------------------------------------
    // Betrieb
    // ---------------------------------------------------------------------

    /** Verzeichnis für Rate-Limit-Daten. Ideal ausserhalb des Webroots. */
    'storage_dir' => __DIR__ . '/storage',

    /**
     * Zusätzliche Ablage jeder Anfrage als Datei, falls der Mailversand
     * ausfällt. Bei true unbedingt sicherstellen, dass das Verzeichnis
     * nicht über das Web erreichbar ist.
     */
    'archive_submissions' => false,

    /** Bei true werden technische Fehlerdetails ausgegeben. NUR für Tests. */
    'debug' => false,
];
