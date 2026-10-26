<?php
declare(strict_types=1);

// Schutz vor direktem Aufruf. Wirkt unabhängig von der Serverkonfiguration.
if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Baukasten auf dem Server: setzt die Seiten aus build/pages, build/partials,
 * build/extra und build/site.json zusammen.
 *
 * Das Gegenstück zu build/build.py. Beide erzeugen Byte für Byte dieselben
 * Seiten (geprüft mit tools/baukasten-vergleich.sh). Die Regeln für die
 * Platzhalter stehen ausführlich in build/build.py.
 *
 * Diese Klasse schreibt selbst nichts. Sie liefert die fertigen Seiten als
 * Zeichenketten; was davon auf die Platte kommt, entscheidet der Aufrufer.
 */
final class Baukasten
{
    /** Silbentrennung in Überschriften. Reihenfolge wie in build.py. */
    private const TRENN = [
        'Kleingedrucktes' => 'Klein&shy;gedrucktes',
        'Berufshaftpflichtversicherung' => 'Berufs&shy;haftpflicht&shy;versicherung',
        'Verbraucherstreitbeilegung' => 'Verbraucher&shy;streit&shy;beilegung',
        'Versicherungsvermittlung' => 'Versicherungs&shy;vermittlung',
        'Datenschutzbeauftragter' => 'Datenschutz&shy;beauftragter',
        'Schlichtungsverfahren' => 'Schlichtungs&shy;verfahren',
        'Datenschutzrechtliche' => 'Datenschutz&shy;rechtliche',
        'Datenschutzerklärung' => 'Datenschutz&shy;erklärung',
        'Außergerichtliche' => 'Außer&shy;gerichtliche',
        'außergerichtliche' => 'außer&shy;gerichtliche',
        'Widerspruchsrecht' => 'Widerspruchs&shy;recht',
        'Barrierefreiheit' => 'Barriere&shy;freiheit',
        'Aufsichtsbehörde' => 'Aufsichts&shy;behörde',
        'Verantwortlicher' => 'Verantwort&shy;licher',
        'Gesundheitsdaten' => 'Gesundheits&shy;daten',
        'Berufsrechtliche' => 'Berufs&shy;rechtliche',
        'Registerbehörden' => 'Register&shy;behörden',
        'Geltungsbereich' => 'Geltungs&shy;bereich',
        'Kontaktformular' => 'Kontakt&shy;formular',
        'Datensicherheit' => 'Daten&shy;sicherheit',
        'Erstinformation' => 'Erst&shy;information',
        'Registereintrag' => 'Register&shy;eintrag',
        'Streitbeilegung' => 'Streit&shy;beilegung',
        'miteinander' => 'mit&shy;einander',
    ];

    private const COND = '/\{\{\?([\w.]+)\}\}((?:(?!\{\{[?!][\w.]+\}\}).)*?)\{\{\/\?\}\}/su';
    private const NOT  = '/\{\{!([\w.]+)\}\}((?:(?!\{\{[?!][\w.]+\}\}).)*?)\{\{\/!\}\}/su';

    private string $root;
    private string $b;

    /** @var array<string,mixed> */
    private array $site;

    /** @var list<string> */
    private array $missing = [];

    /** @param array<string,mixed>|null $site Abweichende Daten, z. B. für die Vorschau */
    public function __construct(string $root, ?array $site = null)
    {
        $this->root = rtrim($root, '/');
        $this->b = $this->root . '/build';
        ini_set('pcre.backtrack_limit', '20000000');
        $this->site = self::ableiten($site ?? self::ladeSite($this->root));
    }

    /** @return array<string,mixed> */
    public static function ladeSite(string $root): array
    {
        $raw = @file_get_contents(rtrim($root, '/') . '/build/site.json');
        if ($raw === false) {
            throw new RuntimeException('build/site.json fehlt.');
        }
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('build/site.json ist kein Objekt.');
        }
        return $data;
    }

    /**
     * Abgeleitete Angaben wie in build.py: Hauptsitz, Jahr und die beiden
     * Schalter aus der Rechtsgrundlage.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private static function ableiten(array $site): array
    {
        $standorte = is_array($site['standorte'] ?? null) ? $site['standorte'] : [];
        $haupt = null;
        foreach ($standorte as $s) {
            if (is_array($s) && self::wahr($s['hauptsitz'] ?? null)) {
                $haupt = $s;
                break;
            }
        }
        $site['hauptsitz'] = $haupt ?? ($standorte[0] ?? []);
        $site['jahr'] = date('Y');

        $g = mb_strtolower(trim((string) ($site['register']['grundlage'] ?? '')));
        if (!isset($site['register']) || !is_array($site['register'])) {
            $site['register'] = [];
        }
        $site['register']['gebunden'] = str_starts_with($g, 'gebunden') ? true : '';
        $site['register']['eigene_erlaubnis'] = ($g !== '' && !str_starts_with($g, 'gebunden')) ? true : '';
        return $site;
    }

    // ------------------------------------------------------------------
    // Python-Semantik
    // ------------------------------------------------------------------

    /** Wahrheitswert wie in Python. */
    private static function wahr(mixed $v): bool
    {
        return !($v === null || $v === false || $v === '' || $v === [] || $v === 0 || $v === 0.0);
    }

    /** html.escape(s, quote=True) */
    public static function esc(string $s): string
    {
        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
    }

    /** str(v) für die Werte, die in site.json vorkommen. */
    private static function text(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
        }
        return (string) $v;
    }

    /** Ist ein Wert ein Objekt (dict) und keine Liste? */
    private static function istObjekt(mixed $v): bool
    {
        return is_array($v) && $v !== [] && !array_is_list($v);
    }

    private function lookup(string $path, mixed $ctx, bool $ctxIstSite): mixed
    {
        if (str_starts_with($path, '.')) {
            $path = substr($path, 1);
            $scopes = [$ctx];
        } else {
            $scopes = $ctxIstSite ? [$this->site] : [$ctx, $this->site];
        }
        foreach ($scopes as $scope) {
            $cur = $scope;
            $ok = true;
            foreach (explode('.', $path) as $part) {
                if (self::istObjekt($cur) && array_key_exists($part, $cur)) {
                    $cur = $cur[$part];
                } else {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $cur;
            }
        }
        return null;
    }

    private function hinweis(string $path): ?string
    {
        $parts = explode('.', ltrim($path, '.'));
        $last = array_pop($parts);
        $parent = $parts ? $this->lookup(implode('.', $parts), $this->site, true) : $this->site;
        if (self::istObjekt($parent) && array_key_exists('_' . $last, $parent)) {
            $h = $parent['_' . $last];
            return $h === null ? null : self::text($h);
        }
        return null;
    }

    private function platzhalter(string $path, ?string $label): string
    {
        $text = ($label !== null && $label !== '') ? $label : null;
        if ($text === null) {
            $h = $this->hinweis($path);
            $text = ($h !== null && $h !== '') ? $h : strtoupper($path);
        }
        $this->missing[] = $path;
        return '<span class="ph">[[' . self::esc($text) . ']]</span>';
    }

    private static function ersetze(string $muster, callable $fn, string $s): string
    {
        $r = preg_replace_callback($muster, $fn, $s, -1, $n, PREG_UNMATCHED_AS_NULL);
        if ($r === null) {
            throw new RuntimeException('Vorlage nicht auswertbar: ' . preg_last_error_msg());
        }
        return $r;
    }

    // ------------------------------------------------------------------
    // Auswertung der Platzhalter
    // ------------------------------------------------------------------

    public function render(string $tpl, mixed $ctx = null, bool $ctxIstSite = true): string
    {
        if ($ctxIstSite) {
            $ctx = $this->site;
        }

        // Schleifen
        $tpl = self::ersetze('/\{\{#([\w.]+)\}\}(.*?)\{\{\/\1\}\}/su', function (array $m) use ($ctx, $ctxIstSite): string {
            $items = $this->lookup($m[1], $ctx, $ctxIstSite);
            if (!self::wahr($items)) {
                $items = [];
            }
            if (self::istObjekt($items)) {
                $items = array_map('strval', array_keys($items));
            }
            if (!is_array($items)) {
                $items = [];
            }
            $out = '';
            foreach ($items as $item) {
                $out .= $this->render($m[2], $item, false);
            }
            return $out;
        }, $tpl);

        // Bedingungen, innerste zuerst, bis keine mehr übrig ist
        $filled = fn(string $key): bool => !in_array($this->lookup($key, $ctx, $ctxIstSite), [null, '', [], false, 0], true);
        while (true) {
            $neu = self::ersetze(self::COND, fn(array $m): string => $filled($m[1]) ? $m[2] : '', $tpl);
            $neu = self::ersetze(self::NOT, fn(array $m): string => $filled($m[1]) ? '' : $m[2], $neu);
            if ($neu === $tpl) {
                break;
            }
            $tpl = $neu;
        }

        // Werte in Attributen
        $tpl = self::ersetze('/\{\{@([\w.]+)(?:\|([^}]*))?\}\}/u', function (array $m) use ($ctx, $ctxIstSite): string {
            $v = $this->lookup($m[1], $ctx, $ctxIstSite);
            if ($v === null || $v === '') {
                $this->missing[] = $m[1];
                return self::esc((string) ($m[2] ?? ''));
            }
            return self::esc(self::text($v));
        }, $tpl);

        // Werte
        $tpl = self::ersetze('/\{\{([\w.]+)(?:\|([^}]*))?\}\}/u', function (array $m) use ($ctx, $ctxIstSite): string {
            $v = $this->lookup($m[1], $ctx, $ctxIstSite);
            if ($v === null || $v === '') {
                return $this->platzhalter($m[1], $m[2]);
            }
            if (is_bool($v)) {
                return $v ? 'ja' : 'nein';
            }
            $s = self::text($v);
            return str_starts_with($s, '<') ? $s : self::esc($s);
        }, $tpl);

        return $tpl;
    }

    // ------------------------------------------------------------------
    // Seiten
    // ------------------------------------------------------------------

    private function lies(string $rel): string
    {
        $s = @file_get_contents($this->b . '/' . $rel);
        if ($s === false) {
            throw new RuntimeException('Baustein fehlt: build/' . $rel);
        }
        // Wie Python beim Lesen im Textmodus: Zeilenenden vereinheitlichen
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    private static function trennen(string $s): string
    {
        return self::ersetze('/<(h1|h2|summary)([^>]*)>(.*?)<\/\1>/su', function (array $m): string {
            $inner = $m[3];
            if (str_contains($inner, '&shy;')) {
                return $m[0];
            }
            foreach (self::TRENN as $w => $h) {
                $inner = str_replace($w, $h, $inner);
            }
            return '<' . $m[1] . $m[2] . '>' . $inner . '</' . $m[1] . '>';
        }, $s);
    }

    /**
     * Liest den Kopfkommentar einer Seite.
     *
     * @return array{0: array<string,string>, 1: string}
     */
    public static function meta(string $src): array
    {
        if (!preg_match('/\A\s*<!--\s*page\s+(.*?)-->\s*/su', $src, $m)) {
            throw new RuntimeException('Seite ohne <!-- page … --> Kopf');
        }
        $meta = [];
        preg_match_all('/(\w+)="([^"]*)"/u', $m[1], $all, PREG_SET_ORDER);
        foreach ($all as $a) {
            $meta[$a[1]] = $a[2];
        }
        return [$meta, substr($src, strlen($m[0]))];
    }

    /** @return list<string> Seiten in derselben Reihenfolge wie build.py */
    public function seiten(): array
    {
        $dir = $this->b . '/pages';
        $namen = array_merge(
            array_values(array_diff(scandir($dir) ?: [], ['.', '..'])),
            array_map(fn($f) => 'recht/' . $f, array_values(array_diff(scandir($dir . '/recht') ?: [], ['.', '..'])))
        );
        sort($namen, SORT_STRING);
        return array_values(array_filter($namen, fn($r) => is_file($dir . '/' . $r)));
    }

    /**
     * Baut eine Seite.
     *
     * @return array{html: string, meta: array<string,string>}
     */
    public function seite(string $rel, bool $live): array
    {
        [$meta, $body] = self::meta($this->lies('pages/' . $rel));

        $head = $this->lies('partials/head.html');
        foreach (['title', 'desc', 'path', 'robots'] as $k) {
            $head = str_replace('__' . strtoupper($k) . '__', self::esc($meta[$k] ?? ''), $head);
        }
        $extraHead = '';
        if (($meta['head'] ?? '') !== '') {
            $extraHead = $this->lies('extra/' . $meta['head'] . '.html');
        }
        $extraScripts = '';
        foreach (array_filter(explode(',', $meta['scripts'] ?? ''), fn($s) => $s !== '') as $s) {
            $extraScripts .= $this->lies('extra/' . trim($s) . '.html');
        }

        $header = $this->lies('partials/header.html');
        if (($meta['nav'] ?? '') !== '') {
            $header = (string) preg_replace(
                '/(<a class="nav__link" href="[^"]*" data-nav="' . preg_quote($meta['nav'], '/') . '")/u',
                '$1 aria-current="page"',
                $header
            );
        }

        $page = "<!DOCTYPE html>\n<html lang=\"de\" class=\"no-js\">\n<head>\n" . $head . $extraHead
              . "</head>\n<body>\n" . $header . $body . $this->lies('partials/footer.html') . $extraScripts
              . "</body>\n</html>\n";
        if ($live) {
            $page = (string) preg_replace('/<!--editor-->.*?<!--\/editor-->/su', '', $page);
        } else {
            $page = str_replace(['<!--editor-->', '<!--/editor-->'], '', $page);
        }

        $page = $this->render($page);
        return ['html' => self::trennen($page), 'meta' => $meta];
    }

    /**
     * Baut alle Seiten samt Sitemap und CSP-Hashes.
     *
     * @return array{seiten: array<string,string>, sitemap: string, fehlend: list<string>, hashes: string}
     */
    public function alles(bool $live): array
    {
        $this->missing = [];
        $seiten = [];
        $sitemap = [];
        foreach ($this->seiten() as $rel) {
            $s = $this->seite($rel, $live);
            $seiten[$rel] = $s['html'];
            if (!str_contains($s['meta']['robots'] ?? '', 'noindex')) {
                $sitemap[] = [$s['meta']['path'] ?? '/', $s['meta']['prio'] ?? '0.5', $s['meta']['freq'] ?? 'yearly'];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        $domain = self::text($this->site['web']['domain'] ?? '');
        foreach ($sitemap as [$p, $prio, $freq]) {
            $xml .= "  <url><loc>{$domain}{$p}</loc><changefreq>{$freq}</changefreq><priority>{$prio}</priority></url>\n";
        }
        $xml .= "</urlset>\n";

        $hashes = [];
        preg_match_all('/<script[^>]*>(.*?)<\/script>/su', $seiten['index.html'] ?? '', $bl);
        foreach ($bl[1] as $block) {
            if (trim($block) !== '') {
                $hashes[] = "'sha256-" . base64_encode(hash('sha256', $block, true)) . "'";
            }
        }

        $fehlend = array_values(array_unique($this->missing));
        sort($fehlend, SORT_STRING);
        return ['seiten' => $seiten, 'sitemap' => $xml, 'fehlend' => $fehlend, 'hashes' => implode(' ', $hashes)];
    }

    /** Trägt die Hashes in eine Serverkonfiguration ein (.htaccess, nginx). */
    public static function cspEintragen(string $cfg, string $hashes): string
    {
        $neu = "script-src 'self'" . ($hashes !== '' ? ' ' . $hashes : '') . ';';
        return (string) preg_replace_callback("/script-src 'self'(?: 'sha256-[^']*')*;/", fn() => $neu, $cfg);
    }

    /** @return list<string> Fehlende Felder des letzten Baus */
    public function fehlend(): array
    {
        $f = array_values(array_unique($this->missing));
        sort($f, SORT_STRING);
        return $f;
    }

    /** @return array<string,mixed> Die Daten samt abgeleiteter Angaben */
    public function daten(): array
    {
        return $this->site;
    }
}
