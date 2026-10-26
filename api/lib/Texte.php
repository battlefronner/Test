<?php
declare(strict_types=1);

if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Findet die bearbeitbaren Texte einer Seitenvorlage und schreibt
 * geänderte Texte zurück.
 *
 * Bearbeitbar ist ein Element (Überschrift, Absatz, Listenpunkt …), wenn es
 * nur Text und einfache Auszeichnung enthält. Alles, was die Seite technisch
 * trägt — Skripte, Grafiken, Formulare, Schleifen und Bedingungen des
 * Baukastens, Elemente mit data-Attributen — bleibt unangetastet.
 *
 * Platzhalter wie {{hauptsitz.ort}} und Links werden im Editor als feste
 * Bausteine gezeigt. Sie lassen sich verschieben oder löschen, aber nicht
 * verändern; beim Speichern wird der Originalcode wieder eingesetzt.
 */
final class Texte
{
    private const TAGS = 'h1|h2|h3|h4|p|li|summary|dt|dd|figcaption|blockquote|td|th';

    public const ART = [
        'h1' => 'Hauptüberschrift', 'h2' => 'Überschrift', 'h3' => 'Zwischenüberschrift', 'h4' => 'Zwischenüberschrift',
        'p' => 'Absatz', 'li' => 'Listenpunkt', 'summary' => 'Frage', 'dt' => 'Begriff', 'dd' => 'Erläuterung',
        'figcaption' => 'Bildunterschrift', 'blockquote' => 'Zitat', 'td' => 'Tabellenzelle', 'th' => 'Tabellenkopf',
    ];

    /**
     * Bearbeitbare Elemente einer Vorlage.
     *
     * @return list<array{tag:string, start:int, ende:int, inner:string, hash:string, teile:list<array{art:string, code:string}>}>
     */
    public static function finde(string $src): array
    {
        $sperren = self::sperrbereiche($src);
        preg_match_all('/<(' . self::TAGS . ')(\s[^>]*)?>/u', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $liste = [];
        $belegtBis = -1;
        foreach ($m as $treffer) {
            $tag = $treffer[1][0];
            $attrs = $treffer[2][0] ?? '';
            $auf = $treffer[0][1];
            $start = $auf + strlen($treffer[0][0]);
            if ($auf < $belegtBis || self::gesperrt($auf, $sperren)) {
                continue;
            }
            if (preg_match('/data-|\bhidden\b|aria-hidden|\{\{|contenteditable/u', $attrs)) {
                continue;
            }
            $ende = strpos($src, '</' . $tag . '>', $start);
            if ($ende === false) {
                continue;
            }
            $inner = substr($src, $start, $ende - $start);
            if (preg_match('/<' . $tag . '[\s>]/u', $inner) || self::gesperrt($ende, $sperren)) {
                continue;
            }
            $teile = self::zerlege($inner);
            if ($teile === null) {
                continue;
            }
            $liste[] = [
                'tag' => $tag, 'start' => $start, 'ende' => $ende, 'inner' => $inner,
                'hash' => substr(hash('sha256', $inner), 0, 16), 'teile' => $teile,
            ];
            $belegtBis = $ende;
        }
        return $liste;
    }

    /** @return list<array{0:int,1:int}> Bereiche, in denen nichts bearbeitet wird */
    private static function sperrbereiche(string $src): array
    {
        $b = [];
        $muster = [
            '/<!--editor-->.*?<!--\/editor-->/su', '/<!--.*?-->/su', '/<script\b.*?<\/script>/su',
            '/<svg\b.*?<\/svg>/su', '/<template\b.*?<\/template>/su', '/<form\b.*?<\/form>/su',
            '/<select\b.*?<\/select>/su', '/<button\b.*?<\/button>/su',
            '/<nav\b.*?<\/nav>/su', '/<address\b.*?<\/address>/su',
            '/\{\{#([\w.]+)\}\}.*?\{\{\/\1\}\}/su',
        ];
        foreach ($muster as $mu) {
            preg_match_all($mu, $src, $t, PREG_OFFSET_CAPTURE);
            foreach ($t[0] as [$text, $pos]) {
                $b[] = [$pos, $pos + strlen($text)];
            }
        }
        return $b;
    }

    /** @param list<array{0:int,1:int}> $sperren */
    private static function gesperrt(int $pos, array $sperren): bool
    {
        foreach ($sperren as [$a, $e]) {
            if ($pos >= $a && $pos < $e) {
                return true;
            }
        }
        return false;
    }

    /**
     * Zerlegt den Inhalt in Text, erlaubte Auszeichnung und feste Bausteine.
     * Liefert null, wenn etwas darin steht, das nicht bearbeitet werden soll.
     *
     * @return list<array{art:string, code:string}>|null
     */
    private static function zerlege(string $inner): ?array
    {
        $stuecke = preg_split('/(<a\b[^>]*>[^<]*<\/a>|<[^>]+>|\{\{[^}]*\}\})/u', $inner, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($stuecke === false) {
            return null;
        }
        $teile = [];
        $offen = [];
        $buchstaben = false;
        foreach ($stuecke as $s) {
            if (str_starts_with($s, '{{')) {
                if (!preg_match('/^\{\{[\w][\w.]*(\|[^}]*)?\}\}$/u', $s)) {
                    return null; // Schleifen, Bedingungen, Attributwerte
                }
                $teile[] = ['art' => 'fest', 'code' => $s];
            } elseif (str_starts_with($s, '<a')) {
                $teile[] = ['art' => 'fest', 'code' => $s];
                $buchstaben = $buchstaben || preg_match('/\p{L}/u', strip_tags($s)) === 1;
            } elseif (str_starts_with($s, '<')) {
                $t = strtolower(preg_replace('/\s+/', ' ', trim($s)) ?? '');
                if (in_array($t, ['<br>', '<br/>', '<br />'], true)) {
                    $teile[] = ['art' => 'html', 'code' => '<br>'];
                } elseif (in_array($t, ['<strong>', '<em>', '<span class="gold">'], true)) {
                    $offen[] = substr($t, 1, strcspn($t, ' >', 1));
                    $teile[] = ['art' => 'html', 'code' => $t];
                } elseif (in_array($t, ['</strong>', '</em>', '</span>'], true)) {
                    if (array_pop($offen) !== substr($t, 2, -1)) {
                        return null;
                    }
                    $teile[] = ['art' => 'html', 'code' => $t];
                } else {
                    return null;
                }
            } else {
                $teile[] = ['art' => 'text', 'code' => $s];
                $buchstaben = $buchstaben || preg_match('/\p{L}/u', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1;
            }
        }
        return ($offen === [] && $buchstaben) ? $teile : null;
    }

    /**
     * HTML für das bearbeitbare Feld. Feste Bausteine werden zu Chips.
     *
     * @param list<array{art:string, code:string}> $teile
     * @param callable(string):string $anzeige Liefert die Anzeige eines Platzhalters
     */
    public static function editorHtml(array $teile, callable $anzeige): string
    {
        $out = '';
        $k = 0;
        foreach ($teile as $t) {
            if ($t['art'] === 'fest') {
                if (str_starts_with($t['code'], '<a')) {
                    $label = trim(html_entity_decode(strip_tags($t['code']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $out .= '<span class="chip chip--link" contenteditable="false" data-keep="' . $k . '" title="Link (fest)">'
                          . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                } else {
                    $out .= '<span class="chip" contenteditable="false" data-keep="' . $k . '" title="Wird automatisch eingesetzt">'
                          . htmlspecialchars($anzeige($t['code']), ENT_QUOTES, 'UTF-8') . '</span>';
                }
                $k++;
            } elseif ($t['art'] === 'text') {
                $out .= preg_replace('/\s+/u', ' ', $t['code']);
            } else {
                $out .= $t['code'];
            }
        }
        return trim($out);
    }

    /** @param list<array{art:string, code:string}> $teile @return list<string> */
    public static function festeBausteine(array $teile): array
    {
        $f = [];
        foreach ($teile as $t) {
            if ($t['art'] === 'fest') {
                $f[] = $t['code'];
            }
        }
        return $f;
    }

    /**
     * Bereinigt den Inhalt aus dem Editor. Übrig bleiben Text, <br>,
     * <strong>, <em>, <span class="gold"> und die festen Bausteine.
     *
     * @param list<string> $feste
     */
    public static function bereinige(string $html, array $feste, bool $ueberschrift): string
    {
        if (strlen($html) > 20000) {
            throw new InvalidArgumentException('Der Text ist zu lang.');
        }
        $dom = new DOMDocument();
        $alt = libxml_use_internal_errors(true);
        $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="w">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        $w = $dom->getElementById('w');
        if ($w === null) {
            throw new InvalidArgumentException('Der Text ließ sich nicht lesen.');
        }
        $benutzt = [];
        $out = self::knoten($w, $feste, $benutzt, $ueberschrift);
        // Leerraum und Umbrüche an den Rändern entfernen
        $out = preg_replace('/\s+/u', ' ', $out) ?? '';
        $out = preg_replace('/^(\s|<br>)+|(\s|<br>)+$/u', '', $out) ?? '';
        // Geschützte Leerzeichen, die der Browser beim Tippen an Rändern und
        // neben Bausteinen einfügt, werden zu normalen Leerzeichen.
        $out = preg_replace('/(?<=^|[>\s])&nbsp;|&nbsp;(?=[<\s]|$)/u', ' ', $out) ?? '';
        $out = preg_replace('/\s+/u', ' ', $out) ?? '';
        $out = preg_replace('/^(\s|<br>)+|(\s|<br>)+$/u', '', $out) ?? '';
        $out = preg_replace('/(<br>\s*){3,}/u', '<br><br>', $out) ?? '';
        $out = preg_replace('/\s*<br>\s*/u', '<br>', $out) ?? '';
        if (trim(strip_tags($out)) === '' && !preg_match('/\{\{/', $out)) {
            throw new InvalidArgumentException('Ein Text darf nicht leer sein.');
        }
        return $out;
    }

    /**
     * @param list<string> $feste
     * @param array<int,bool> $benutzt
     */
    private static function knoten(DOMNode $n, array $feste, array &$benutzt, bool $ueberschrift): string
    {
        $out = '';
        $erstes = true;
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $t = $c->data;
                $t = str_replace(['{{', '}}'], ['{', '}'], $t);
                $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? '';
                $t = htmlspecialchars($t, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $out .= str_replace(["\u{00A0}", "\u{00AD}"], ['&nbsp;', '&shy;'], $t);
            } elseif ($c instanceof DOMElement) {
                $name = strtolower($c->tagName);
                $keep = $c->getAttribute('data-keep');
                if ($keep !== '' && ctype_digit($keep) && isset($feste[(int) $keep]) && empty($benutzt[(int) $keep])) {
                    $benutzt[(int) $keep] = true;
                    $out .= $feste[(int) $keep];
                } elseif ($keep !== '') {
                    // Kopierter oder unbekannter Baustein: weglassen
                } elseif (in_array($name, ['script', 'style', 'img', 'svg', 'iframe', 'object', 'template', 'input', 'button', 'select', 'textarea'], true)) {
                    // Inhalt verwerfen
                } elseif ($name === 'br') {
                    $out .= $ueberschrift ? ' ' : '<br>';
                } elseif (in_array($name, ['strong', 'b'], true)) {
                    $out .= '<strong>' . self::knoten($c, $feste, $benutzt, $ueberschrift) . '</strong>';
                } elseif (in_array($name, ['em', 'i'], true)) {
                    $out .= '<em>' . self::knoten($c, $feste, $benutzt, $ueberschrift) . '</em>';
                } elseif ($name === 'span' && preg_match('/(^|\s)gold(\s|$)/', $c->getAttribute('class'))) {
                    $out .= '<span class="gold">' . self::knoten($c, $feste, $benutzt, $ueberschrift) . '</span>';
                } elseif (in_array($name, ['div', 'p', 'li'], true)) {
                    // Absatz aus dem Editor (Eingabetaste) wird zum Zeilenumbruch
                    $innen = self::knoten($c, $feste, $benutzt, $ueberschrift);
                    $out .= ($erstes ? '' : ($ueberschrift ? ' ' : '<br>')) . $innen;
                } else {
                    $out .= self::knoten($c, $feste, $benutzt, $ueberschrift);
                }
            }
            $erstes = false;
        }
        // Leere Auszeichnung entfernen
        return preg_replace('/<(strong|em|span class="gold")>\s*<\/(strong|em|span)>/u', '', $out) ?? $out;
    }

    /** Ersetzt den Inhalt eines Elements in der Vorlage. */
    public static function einsetzen(string $src, int $start, int $ende, string $neu): string
    {
        return substr($src, 0, $start) . $neu . substr($src, $ende);
    }

    /**
     * Setzt Titel und Beschreibung im Seitenkopf.
     */
    public static function metaSetzen(string $src, string $feld, string $wert): string
    {
        if (!preg_match('/\A\s*<!--\s*page\s+.*?-->/su', $src, $m)) {
            throw new RuntimeException('Seitenkopf fehlt.');
        }
        $kopf = $m[0];
        $wert = str_replace('"', '“', $wert);
        $neu = preg_match('/\b' . $feld . '="[^"]*"/u', $kopf)
            ? preg_replace_callback('/\b' . $feld . '="[^"]*"/u', fn() => $feld . '="' . $wert . '"', $kopf, 1)
            : preg_replace('/\s*-->$/u', ' ' . $feld . '="' . $wert . '" -->', $kopf, 1);
        return $neu . substr($src, strlen($kopf));
    }
}
