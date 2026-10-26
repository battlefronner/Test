<?php
declare(strict_types=1);

if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Kern der Verwaltung: Anmeldung, Speichern der Quellen, Sicherungen und
 * Veröffentlichen. Die Oberfläche steht in verwaltung/index.php.
 *
 * Grundsatz: Bearbeitet werden nur die Quellen in build/ (site.json und die
 * Seitenvorlagen). Die öffentlichen Seiten ändern sich erst beim
 * Veröffentlichen — und nur, wenn alle Pflichtangaben vollständig sind.
 */
final class Verwaltung
{
    public const GRUNDLAGEN = [
        'gebundener Versicherungsvertreter nach § 34d Abs. 7 Satz 1 Nr. 1 GewO',
        'Versicherungsvertreter mit Erlaubnis nach § 34d Abs. 1 GewO',
    ];

    private const MAX_SICHERUNGEN = 30;

    public string $root;
    /** @var array<string,mixed> */
    public array $config;
    public Security $security;

    /** @param array<string,mixed> $config */
    public function __construct(string $root, array $config)
    {
        $this->root = rtrim($root, '/');
        $this->config = $config + ['storage_dir' => $this->root . '/api/storage'];
        $this->security = new Security($this->config);
    }

    // ------------------------------------------------------------------
    // Sitzung und Anmeldung
    // ------------------------------------------------------------------

    public function sitzungStarten(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/verwaltung/',
            'domain'   => '',
            'secure'   => ($this->config['cookie_secure'] ?? null) ?? $this->security->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('fw_verw');
        session_start();
    }

    public function passwortHash(): string
    {
        return trim((string) ($this->config['verwaltung_passwort_hash'] ?? ''));
    }

    public function eingerichtet(): bool
    {
        $h = $this->passwortHash();
        return $h !== '' && (password_get_info($h)['algo'] ?? null) !== null;
    }

    public function httpsFehlt(): bool
    {
        if (($this->config['verwaltung_https_pflicht'] ?? true) === false || $this->security->isHttps()) {
            return false;
        }
        $host = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        return !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    private function minuten(): int
    {
        return max(5, (int) ($this->config['verwaltung_sitzung_minuten'] ?? 30));
    }

    /** Prüft die Sitzung. Liefert einen Grund, falls sie abgelaufen ist. */
    public function angemeldet(?string &$grund = null): bool
    {
        $a = $_SESSION['auth'] ?? null;
        if (!is_array($a)) {
            return false;
        }
        $jetzt = time();
        $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $abgelaufen = ($jetzt - (int) ($a['zuletzt'] ?? 0)) > $this->minuten() * 60
            || ($jetzt - (int) ($a['seit'] ?? 0)) > 12 * 3600
            || !hash_equals((string) ($a['ua'] ?? ''), $ua)
            || !hash_equals((string) ($a['pw'] ?? ''), $this->pwKennung());
        if ($abgelaufen) {
            $grund = 'Sie wurden aus Sicherheitsgründen abgemeldet. Bitte erneut anmelden.';
            $this->abmelden();
            return false;
        }
        $_SESSION['auth']['zuletzt'] = $jetzt;
        return true;
    }

    /** Ändert sich das Passwort in config.php, enden alle Sitzungen. */
    private function pwKennung(): string
    {
        return substr(hash('sha256', $this->passwortHash()), 0, 16);
    }

    public function anmelden(string $passwort): bool
    {
        $schluessel = $this->security->clientKey();
        // Je Absender 5 Versuche in 15 Minuten, insgesamt 30 je Stunde
        if ($this->security->rateLimitExceeded(hash('sha256', 'verw-login|' . $schluessel), 5, 900)
            || $this->security->rateLimitExceeded(hash('sha256', 'verw-login-alle'), 30, 3600)) {
            throw new RuntimeException('Zu viele Anmeldeversuche. Bitte in 15 Minuten erneut versuchen.');
        }
        if (strlen($passwort) > 1024 || !password_verify($passwort, $this->passwortHash())) {
            usleep(random_int(200_000, 400_000));
            return false;
        }
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['auth'] = [
            'seit' => time(), 'zuletzt' => time(),
            'ua' => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'pw' => $this->pwKennung(),
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->protokoll('Anmeldung');
        return true;
    }

    public function abmelden(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** CSRF-Token und Herkunft jeder Änderung prüfen. */
    public function postGueltig(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && $this->security->originAllowed()
            && $this->security->csrfTokenValid((string) ($_POST['csrf'] ?? ''));
    }

    public function meldung(string $art, string $text): void
    {
        $_SESSION['meldungen'][] = ['art' => $art, 'text' => $text];
    }

    /** @return list<array{art:string,text:string}> */
    public function meldungen(): array
    {
        $m = $_SESSION['meldungen'] ?? [];
        unset($_SESSION['meldungen']);
        return is_array($m) ? $m : [];
    }

    // ------------------------------------------------------------------
    // Dateien
    // ------------------------------------------------------------------

    public function speicher(): string
    {
        $dir = rtrim((string) $this->config['storage_dir'], '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $this->security->guard($dir);
        return $dir;
    }

    /** Führt eine Änderung unter exklusiver Sperre aus. */
    public function gesperrt(callable $fn): mixed
    {
        $h = fopen($this->speicher() . '/verwaltung.lock', 'c');
        if ($h === false || !flock($h, LOCK_EX)) {
            throw new RuntimeException('Die Verwaltung ist gerade beschäftigt. Bitte gleich noch einmal versuchen.');
        }
        try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** Schreibt eine Datei so, dass sie nie halb geschrieben sichtbar ist. */
    public static function schreiben(string $pfad, string $inhalt): void
    {
        $dir = dirname($pfad);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Ordner fehlt: ' . $dir);
        }
        $tmp = $dir . '/.fw-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $inhalt, LOCK_EX) !== strlen($inhalt)) {
            @unlink($tmp);
            throw new RuntimeException('Keine Schreibrechte für ' . basename($pfad) . '.');
        }
        $rechte = is_file($pfad) ? (fileperms($pfad) & 0777) : 0644;
        @chmod($tmp, $rechte);
        if (!@rename($tmp, $pfad)) {
            @unlink($tmp);
            throw new RuntimeException('Keine Schreibrechte für ' . basename($pfad) . '.');
        }
    }

    /** @return array<string,mixed> */
    public function site(): array
    {
        return Baukasten::ladeSite($this->root);
    }

    /** @param array<string,mixed> $site */
    public function siteSpeichern(array $site): void
    {
        $json = json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Zwei statt vier Leerzeichen Einzug, wie bisher in der Datei
        $json = (string) preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
        // Vor dem Schreiben prüfen, dass sich daraus Seiten bauen lassen
        (new Baukasten($this->root, $site))->alles(false);
        self::schreiben($this->root . '/build/site.json', $json);
    }

    /** @return list<string> Seitenvorlagen ohne Rechtstexte */
    public function bearbeitbareSeiten(): array
    {
        $liste = [];
        foreach ((new Baukasten($this->root))->seiten() as $rel) {
            if (!str_starts_with($rel, 'recht/') && !in_array($rel, ['404.html', 'kontakt-danke.html', 'kontakt-fehler.html'], true)) {
                $liste[] = $rel;
            }
        }
        return $liste;
    }

    /** @return list<string> */
    public function alleSeiten(): array
    {
        return (new Baukasten($this->root))->seiten();
    }

    public function vorlage(string $rel): string
    {
        $s = @file_get_contents($this->root . '/build/pages/' . $rel);
        if ($s === false) {
            throw new RuntimeException('Seite nicht gefunden.');
        }
        return $s;
    }

    // ------------------------------------------------------------------
    // Sicherungen
    // ------------------------------------------------------------------

    private function sicherungsDir(): string
    {
        $d = $this->speicher() . '/sicherungen';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        $this->security->guard($d);
        return $d;
    }

    /** Fingerabdruck der Quellen, um ungespeicherte Stände zu erkennen. */
    public function quellenHash(): string
    {
        $ctx = hash_init('sha256');
        hash_update($ctx, (string) @file_get_contents($this->root . '/build/site.json'));
        foreach ($this->alleSeiten() as $rel) {
            hash_update($ctx, $rel . "\0" . (string) @file_get_contents($this->root . '/build/pages/' . $rel));
        }
        return hash_final($ctx);
    }

    public function sichern(string $art, string $notiz = ''): string
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $d = $this->sicherungsDir() . '/' . $id;
        @mkdir($d . '/pages/recht', 0750, true);
        if (!copy($this->root . '/build/site.json', $d . '/site.json')) {
            throw new RuntimeException('Sicherung fehlgeschlagen. Ist api/storage beschreibbar?');
        }
        foreach ($this->alleSeiten() as $rel) {
            copy($this->root . '/build/pages/' . $rel, $d . '/pages/' . $rel);
        }
        file_put_contents($d . '/info.json', json_encode([
            'zeit' => time(), 'mt' => microtime(true), 'art' => $art, 'notiz' => $notiz, 'hash' => $this->quellenHash(),
        ], JSON_UNESCAPED_UNICODE));
        $this->aufraeumen();
        return $id;
    }

    private function aufraeumen(): void
    {
        $alle = $this->sicherungen();
        // Die jüngste Veröffentlichung bleibt immer erhalten
        $letzte = $this->letzteVeroeffentlichung();
        foreach (array_slice($alle, self::MAX_SICHERUNGEN) as $s) {
            if ($letzte !== null && $s['id'] === $letzte['id']) {
                continue;
            }
            self::loeschen($this->sicherungsDir() . '/' . $s['id']);
        }
    }

    private static function loeschen(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** @return list<array{id:string,zeit:int,mt:float,art:string,notiz:string,hash:string}> neueste zuerst */
    public function sicherungen(): array
    {
        $liste = [];
        foreach (glob($this->sicherungsDir() . '/*/info.json') ?: [] as $f) {
            $i = json_decode((string) file_get_contents($f), true);
            if (is_array($i)) {
                $liste[] = ['id' => basename(dirname($f)), 'zeit' => (int) ($i['zeit'] ?? 0), 'mt' => (float) ($i['mt'] ?? $i['zeit'] ?? 0), 'art' => (string) ($i['art'] ?? ''),
                            'notiz' => (string) ($i['notiz'] ?? ''), 'hash' => (string) ($i['hash'] ?? '')];
            }
        }
        usort($liste, fn($a, $b) => [$b['mt'], $b['id']] <=> [$a['mt'], $a['id']]);
        return $liste;
    }

    /** @return array{id:string,zeit:int,art:string,notiz:string,hash:string}|null */
    public function letzteVeroeffentlichung(): ?array
    {
        foreach ($this->sicherungen() as $s) {
            if ($s['art'] === 'veroeffentlicht') {
                return $s;
            }
        }
        return null;
    }

    public function wiederherstellen(string $id): void
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id) || !is_file($this->sicherungsDir() . '/' . $id . '/info.json')) {
            throw new InvalidArgumentException('Diese Sicherung gibt es nicht.');
        }
        $d = $this->sicherungsDir() . '/' . $id;
        $site = json_decode((string) file_get_contents($d . '/site.json'), true);
        if (!is_array($site)) {
            throw new RuntimeException('Die Sicherung ist beschädigt.');
        }
        if ($this->quellenHash() !== ($this->sicherungen()[0]['hash'] ?? '')) {
            $this->sichern('automatisch', 'Stand vor dem Wiederherstellen');
        }
        self::schreiben($this->root . '/build/site.json', (string) file_get_contents($d . '/site.json'));
        foreach ($this->alleSeiten() as $rel) {
            if (is_file($d . '/pages/' . $rel)) {
                self::schreiben($this->root . '/build/pages/' . $rel, (string) file_get_contents($d . '/pages/' . $rel));
            }
        }
        $this->protokoll('Wiederhergestellt: ' . $id);
    }

    // ------------------------------------------------------------------
    // Veröffentlichen
    // ------------------------------------------------------------------

    /** @return list<string> Felder, ohne die nicht veröffentlicht werden kann */
    public function fehlend(): array
    {
        return (new Baukasten($this->root))->alles(true)['fehlend'];
    }

    /** @return array{seiten:int, geaendert:int, hinweise:list<string>} */
    public function veroeffentlichen(): array
    {
        $b = new Baukasten($this->root);
        $r = $b->alles(true);
        if ($r['fehlend'] !== []) {
            throw new DomainException('Es fehlen noch Angaben.');
        }
        foreach ($r['seiten'] as $rel => $html) {
            if (preg_match('/class="ph"|\[\[|<!--editor-->/', $html)) {
                throw new DomainException('In ' . $rel . ' steht noch ein Platzhalter.');
            }
        }
        $geaendert = 0;
        $hinweise = [];
        foreach ($r['seiten'] as $rel => $html) {
            $ziel = $this->root . '/' . $rel;
            if (@file_get_contents($ziel) !== $html) {
                self::schreiben($ziel, $html);
                $geaendert++;
            }
        }
        if (@file_get_contents($this->root . '/sitemap.xml') !== $r['sitemap']) {
            self::schreiben($this->root . '/sitemap.xml', $r['sitemap']);
        }
        foreach (['.htaccess', 'nginx.conf.example'] as $cfg) {
            $p = $this->root . '/' . $cfg;
            if (!is_file($p)) {
                continue;
            }
            $alt = (string) file_get_contents($p);
            $neu = Baukasten::cspEintragen($alt, $r['hashes']);
            if ($neu !== $alt) {
                try {
                    self::schreiben($p, $neu);
                } catch (RuntimeException) {
                    $hinweise[] = $cfg . ' ist schreibgeschützt; der Sicherheits-Hash darin ist veraltet. Bitte dem Betreuer melden.';
                }
            }
        }
        $this->sichern('veroeffentlicht');
        $this->protokoll('Veröffentlicht (' . $geaendert . ' Seiten geändert)');
        return ['seiten' => count($r['seiten']), 'geaendert' => $geaendert, 'hinweise' => $hinweise];
    }

    /** Ungespeichert im Sinne von: Quellen weichen von der letzten Veröffentlichung ab. */
    public function entwurfOffen(): ?bool
    {
        $l = $this->letzteVeroeffentlichung();
        return $l === null ? null : $l['hash'] !== $this->quellenHash();
    }

    /** @return list<string> Pfade, in die nicht geschrieben werden kann */
    public function schreibschutz(): array
    {
        $pruefen = ['build/site.json', 'build/pages', 'build/pages/recht', '.', 'recht', 'assets/img', 'sitemap.xml'];
        foreach ($this->alleSeiten() as $rel) {
            $pruefen[] = 'build/pages/' . $rel;
            $pruefen[] = $rel;
        }
        $fehlt = [];
        foreach ($pruefen as $p) {
            $voll = $this->root . '/' . $p;
            if (file_exists($voll) && !is_writable($voll)) {
                $fehlt[] = $p === '.' ? 'Webverzeichnis' : $p;
            }
        }
        return $fehlt;
    }

    public function protokoll(string $text): void
    {
        $zeile = date('Y-m-d H:i:s') . "\t" . $text . "\n";
        @file_put_contents($this->speicher() . '/verwaltung.log', $zeile, FILE_APPEND | LOCK_EX);
    }

    // ------------------------------------------------------------------
    // Eingaben
    // ------------------------------------------------------------------

    /**
     * Bereinigt eine Texteingabe für site.json.
     * Wirft eine Ausnahme mit verständlicher Meldung, wenn etwas nicht passt.
     */
    public static function eingabe(mixed $wert, string $label, int $max = 500, bool $mehrzeilig = false): string
    {
        if (!is_string($wert)) {
            return '';
        }
        $wert = str_replace(["\r\n", "\r"], "\n", $wert);
        $wert = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $wert) ?? '';
        if (!mb_check_encoding($wert, 'UTF-8')) {
            throw new InvalidArgumentException($label . ': ungültige Zeichen.');
        }
        $wert = $mehrzeilig ? trim(preg_replace("/[ \t]+/u", ' ', $wert) ?? '') : trim(preg_replace('/\s+/u', ' ', $wert) ?? '');
        $wert = $mehrzeilig ? (preg_replace('/\s*\n\s*/u', ' ', $wert) ?? '') : $wert;
        if (mb_strlen($wert) > $max) {
            throw new InvalidArgumentException($label . ': höchstens ' . $max . ' Zeichen.');
        }
        if (str_contains($wert, '{{') || str_contains($wert, '}}')) {
            throw new InvalidArgumentException($label . ': doppelte geschweifte Klammern sind nicht erlaubt.');
        }
        if (str_contains($wert, '\\')) {
            throw new InvalidArgumentException($label . ': der umgekehrte Schrägstrich \\ ist nicht erlaubt.');
        }
        if (str_starts_with($wert, '<')) {
            throw new InvalidArgumentException($label . ': darf nicht mit < beginnen.');
        }
        return $wert;
    }

    /** +49-Format ohne Leerzeichen für tel:-Links. */
    public static function telLink(string $nummer): string
    {
        $n = trim($nummer);
        if ($n === '') {
            return '';
        }
        $plus = str_starts_with($n, '+');
        $ziffern = preg_replace('/\D+/', '', $n) ?? '';
        if ($plus) {
            return '+' . $ziffern;
        }
        if (str_starts_with($ziffern, '00')) {
            return '+' . substr($ziffern, 2);
        }
        if (str_starts_with($ziffern, '0')) {
            return '+49' . substr($ziffern, 1);
        }
        return $ziffern;
    }

    public static function slug(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
        return $s === '' ? 'standort' : substr($s, 0, 40);
    }
}
