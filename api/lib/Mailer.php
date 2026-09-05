<?php
declare(strict_types=1);

// Schutz vor direktem Aufruf. Wirkt unabhängig von der Serverkonfiguration
// und greift auch dann, wenn .htaccess oder die nginx-Regeln nicht aktiv sind.
if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Versand der Formularbenachrichtigung.
 *
 * Es wird bewusst nur die PHP-Funktion mail() verwendet, damit keine externe
 * Abhängigkeit nötig ist. Für den Produktivbetrieb ist authentifizierter
 * SMTP-Versand vorzuziehen — siehe Hinweis in der README.
 *
 * Sicherheitsprinzip: In die Kopfzeilen der Mail gelangen ausschließlich Werte
 * aus der Konfiguration sowie die zuvor validierte Absenderadresse. Sämtliche
 * Freitexteingaben stehen im Nachrichtenrumpf, niemals in einem Header.
 */
final class Mailer
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string,string> $data Bereits validierte Formulardaten
     */
    public function sendNotification(array $data, string $clientKey): bool
    {
        $to = (string) $this->config['recipient'];
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            error_log('Kontaktformular: ungültige Empfängeradresse konfiguriert.');
            return false;
        }

        $subject = trim(sprintf(
            '%s %s — %s %s',
            (string) $this->config['subject_prefix'],
            $data['thema'] ?? 'Anfrage',
            $data['vorname'] ?? '',
            $data['nachname'] ?? ''
        ));

        $body = $this->buildBody($data, $clientKey);

        $headers = $this->buildHeaders($data['email'] ?? null);

        // Envelope-Sender setzen, damit Rückläufer an der richtigen Stelle landen.
        $params = '';
        $from = (string) $this->config['from'];
        if (filter_var($from, FILTER_VALIDATE_EMAIL) !== false && $this->safeForShell($from)) {
            $params = '-f' . $from;
        }

        $ok = @mail($to, $this->encodeHeaderValue($subject), $body, $headers, $params);

        if (!$ok) {
            error_log('Kontaktformular: mail() meldete einen Fehlschlag.');
        }

        return $ok;
    }

    // ------------------------------------------------------------------

    /** @param array<string,string> $data */
    private function buildBody(array $data, string $clientKey): string
    {
        $lines = [
            'Neue Anfrage über das Kontaktformular der Website',
            str_repeat('=', 56),
            '',
            'Name:      ' . ($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''),
            'E-Mail:    ' . ($data['email'] ?? ''),
            'Telefon:   ' . ($data['telefon'] ?? '— nicht angegeben —'),
            'Thema:     ' . ($data['thema'] ?? ''),
            'Rückruf:   ' . ($data['rueckruf'] ?? 'nein'),
            '',
            'Nachricht:',
            str_repeat('-', 56),
            $data['nachricht'] ?? '',
            str_repeat('-', 56),
            '',
            'Datenschutzhinweis bestätigt: ' . ($data['datenschutz'] ?? 'nein'),
            'Eingegangen am:               ' . date('d.m.Y H:i:s') . ' Uhr',
            'Absenderkennung (pseudonym):  ' . $clientKey,
            '',
            'Hinweis: Die Absenderkennung ist ein nicht rückrechenbarer Hashwert',
            'und dient ausschließlich dem Spamschutz. Die IP-Adresse selbst wird',
            'nicht gespeichert.',
        ];

        $body = implode("\r\n", $lines);

        // RFC 5322: Zeilen dürfen 998 Zeichen nicht überschreiten.
        return wordwrap($body, 900, "\r\n", true);
    }

    private function buildHeaders(?string $replyTo): string
    {
        $fromAddress = (string) $this->config['from'];
        $fromName    = $this->encodeHeaderValue((string) $this->config['from_name']);

        $headers = [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Finanzwaechter-Kontaktformular',
            'Auto-Submitted: auto-generated',
        ];

        // Reply-To nur mit einer nachweislich gültigen Adresse belegen.
        if (is_string($replyTo)
            && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/[\r\n]/', $replyTo) !== 1
        ) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        return implode("\r\n", $headers);
    }

    /**
     * Kodiert einen Headerwert nach RFC 2047, damit Umlaute korrekt ankommen,
     * und entfernt zuvor alle Zeilenumbrüche.
     */
    private function encodeHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value; // reines ASCII, keine Kodierung nötig
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Schützt den -f-Parameter vor Einschleusung zusätzlicher Argumente. */
    private function safeForShell(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $value) === 1;
    }
}
