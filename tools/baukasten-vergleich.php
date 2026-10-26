<?php
// Baut alle Seiten mit dem PHP-Baukasten in ein Zielverzeichnis.
// Aufruf: php tools/baukasten-vergleich.php <ziel> [--live]
// Wird von tools/baukasten-vergleich.sh benutzt.
declare(strict_types=1);
define('FW_ENTRYPOINT', true);
require __DIR__ . '/../api/lib/Baukasten.php';
$ziel = $argv[1] ?? '';
if ($ziel === '') { fwrite(STDERR, "Ziel fehlt\n"); exit(2); }
$root = dirname(__DIR__);
$b = new Baukasten($root);
$r = $b->alles(in_array('--live', $argv, true));
foreach ($r['seiten'] as $rel => $html) {
    @mkdir(dirname("$ziel/$rel"), 0777, true);
    file_put_contents("$ziel/$rel", $html);
}
file_put_contents("$ziel/sitemap.xml", $r['sitemap']);
foreach (['.htaccess', 'nginx.conf.example'] as $cfg) {
    file_put_contents("$ziel/$cfg", Baukasten::cspEintragen(file_get_contents("$root/$cfg"), $r['hashes']));
}
echo count($r['seiten']) . " Seiten, fehlend: " . implode(', ', $r['fehlend']) . "\n";
