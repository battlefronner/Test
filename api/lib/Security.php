<?php
declare(strict_types=1);

// Schutz vor direktem Aufruf. Wirkt unabhängig von der Serverkonfiguration
// und greift auch dann, wenn .htaccess oder die nginx-Regeln nicht aktiv sind.
if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Sicherheitsfunktionen für das Kontaktformular:
 * Sitzung, CSRF-Token, Herkunftsprüfung und Anfragebegrenzung.
 */
final class Security
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ------------------------------------------------------------------
    // Sitzung und CSRF
    // ------------------------------------------------------------------

    /**
     * Startet eine Sitzung mit restriktiven Cookie-Einstellungen.
     * SameSite=Strict verhindert, dass das Cookie bei seitenübergreifenden
     * Anfragen mitgesendet wird.
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('fw_sess');
        session_start();
    }

    /** Liefert das Token der Sitzung und erzeugt es beim ersten Aufruf. */
    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Zeitkonstanter Vergleich, um Rückschlüsse über die Laufzeit zu vermeiden. */
    public function csrfTokenValid(string $candidate): bool
    {
        $stored = $_SESSION['csrf_token'] ?? null;
        if (!is_string($stored) || $stored === '' || $candidate === '') {
            return false;
        }
        return hash_equals($stored, $candidate);
    }

    // ------------------------------------------------------------------
    // Herkunftsprüfung
    // ------------------------------------------------------------------

    /**
     * Prüft, ob die Anfrage von einem erlaubten Host stammt.
     *
     * Browser senden bei seitenübergreifenden POST-Anfragen zwingend einen
     * Origin-Header. Fehlen Origin und Referer vollständig, wird die Anfrage
     * abgelehnt — auch dann, wenn ein gültiges CSRF-Token vorliegt.
     */
    public function originAllowed(): bool
    {
        $allowed = array_map('strtolower', (array) ($this->config['allowed_hosts'] ?? []));
        if ($allowed === []) {
            return false; // Sichere Vorgabe: ohne Konfiguration nichts annehmen.
        }

        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
            $value = $_SERVER[$key] ?? '';
            if (!is_string($value) || $value === '') {
                continue;
            }
            $host = parse_url($value, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }
            return in_array(strtolower($host), $allowed, true);
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Anfragebegrenzung
    // ------------------------------------------------------------------

    /**
     * Pseudonymer Bezeichner des Absenders. Die IP-Adresse selbst wird nie
     * gespeichert, sondern nur ein mit einem geheimen Pepper gebildeter Hash.
     */
    public function clientKey(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $pepper = (string) ($this->config['ip_pepper'] ?? '');
        return substr(hash_hmac('sha256', (string) $ip, $pepper), 0, 40);
    }

    /**
     * Zählt eine Absendung und meldet, ob das Limit überschritten ist.
     * Die Zählung erfolgt dateibasiert mit exklusiver Sperre, damit
     * gleichzeitige Anfragen nicht aneinander vorbeischreiben.
     */
    public function rateLimitExceeded(string $bucket, int $limit, int $window): bool
    {
        $dir = $this->storageDir();
        if ($dir === null) {
            // Ohne funktionierenden Speicher lieber ablehnen als ungeschützt annehmen.
            return true;
        }

        $file = $dir . '/rl_' . preg_replace('/[^a-f0-9]/', '', $bucket) . '.json';
        $now  = time();

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($handle);
            $hits = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $hits = array_values(array_filter(
                        $decoded,
                        static fn($t): bool => is_int($t) && $t > ($now - $window)
                    ));
                }
            }

            if (count($hits) >= $limit) {
                return true;
            }

            $hits[] = $now;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($hits, JSON_THROW_ON_ERROR));
            fflush($handle);

            return false;
        } catch (\Throwable) {
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Entfernt abgelaufene Spamschutz-Dateien. Wird stichprobenartig
     * aufgerufen, damit keine Daten länger als nötig liegen bleiben —
     * das ist zugleich die technische Umsetzung der in der
     * Datenschutzerklärung genannten Löschfrist.
     */
    public function purgeExpired(): void
    {
        $dir = $this->storageDir();
        if ($dir === null) {
            return;
        }

        $ttl = (int) ($this->config['storage_ttl'] ?? 86400);
        $cutoff = time() - $ttl;

        foreach (glob($dir . '/rl_*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    public function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // Hinter einem Reverse Proxy nur auswerten, wenn der Proxy vertrauenswürdig ist.
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /** Legt das Speicherverzeichnis bei Bedarf an und schützt es vor Web-Zugriff. */
    private function storageDir(): ?string
    {
        $dir = (string) ($this->config['storage_dir'] ?? '');
        if ($dir === '') {
            return null;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        if (!is_writable($dir)) {
            return null;
        }

        // Zusätzliche Absicherung, falls das Verzeichnis im Webroot liegt.
        $guard = $dir . '/.htaccess';
        if (!file_exists($guard)) {
            @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        return $dir;
    }
}
