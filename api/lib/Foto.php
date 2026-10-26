<?php
declare(strict_types=1);

if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Nimmt ein hochgeladenes Foto an und speichert es neu kodiert als WebP.
 *
 * Das Bild wird vollständig dekodiert und neu geschrieben. Dabei fallen
 * Metadaten (Standort, Kamera, Vorschaubilder) und alles weg, was kein
 * Bildpunkt ist — auch eingeschleuster Code. Der Dateiname wird zufällig
 * vergeben; nichts vom Namen oder Typ der hochgeladenen Datei wird übernommen.
 */
final class Foto
{
    public const BREITE = 600;
    public const HOEHE = 750;          // Hochformat 4:5
    private const MAX_BYTES = 12 * 1024 * 1024;
    private const MAX_PIXEL = 50_000_000;

    /**
     * @param array<string,mixed> $datei Eintrag aus $_FILES
     * @return string Pfad relativ zum Webverzeichnis, z. B. assets/img/team/3f…c1.webp
     */
    public static function speichern(array $datei, string $root): string
    {
        $fehler = (int) ($datei['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fehler === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Bitte zuerst ein Foto auswählen.');
        }
        if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Das Foto ist zu groß für den Server. Bitte ein kleineres Foto wählen.');
        }
        if ($fehler !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Das Hochladen hat nicht geklappt. Bitte noch einmal versuchen.');
        }
        $tmp = (string) ($datei['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Das Hochladen hat nicht geklappt.');
        }
        $groesse = (int) @filesize($tmp);
        if ($groesse <= 0 || $groesse > self::MAX_BYTES) {
            throw new InvalidArgumentException('Das Foto ist zu groß. Bitte höchstens 12 MB.');
        }
        return self::verarbeiten($tmp, $root);
    }

    /** Auch für Tests ohne Upload nutzbar. */
    public static function verarbeiten(string $pfad, string $root): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new RuntimeException('Auf dem Server fehlt die Bildbearbeitung (PHP-Erweiterung GD mit WebP).');
        }
        $info = @getimagesize($pfad);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Bitte ein Foto als JPG, PNG oder WebP hochladen.');
        }
        [$w, $h] = $info;
        if ($w < 200 || $h < 200) {
            throw new InvalidArgumentException('Das Foto ist zu klein. Bitte mindestens 600 × 750 Pixel.');
        }
        if ($w * $h > self::MAX_PIXEL) {
            throw new InvalidArgumentException('Das Foto hat zu viele Bildpunkte. Bitte vorher verkleinern.');
        }
        $roh = file_get_contents($pfad);
        $bild = $roh === false ? false : @imagecreatefromstring($roh);
        if ($bild === false) {
            throw new InvalidArgumentException('Das Foto ließ sich nicht öffnen.');
        }

        // Ausrichtung aus der Kamera berücksichtigen (Hochkant-Fotos vom Handy)
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($pfad);
            $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $winkel = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($winkel !== 0) {
                $gedreht = imagerotate($bild, $winkel, 0);
                if ($gedreht !== false) {
                    imagedestroy($bild);
                    $bild = $gedreht;
                }
            }
        }
        $w = imagesx($bild);
        $h = imagesy($bild);

        // Zuschnitt 4:5. Senkrecht etwas oberhalb der Mitte, damit bei
        // Porträts der Kopf nicht abgeschnitten wird.
        $ziel = self::BREITE / self::HOEHE;
        if ($w / $h > $ziel) {
            $cw = (int) round($h * $ziel); $ch = $h;
            $cx = (int) (($w - $cw) / 2); $cy = 0;
        } else {
            $cw = $w; $ch = (int) round($w / $ziel);
            $cx = 0; $cy = (int) (($h - $ch) * 0.25);
        }
        $aus = imagecreatetruecolor(self::BREITE, self::HOEHE);
        imagefill($aus, 0, 0, imagecolorallocate($aus, 11, 11, 15));
        imagecopyresampled($aus, $bild, 0, 0, $cx, $cy, self::BREITE, self::HOEHE, $cw, $ch);
        imagedestroy($bild);

        $dir = rtrim($root, '/') . '/assets/img/team';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Der Ordner assets/img/team lässt sich nicht anlegen.');
        }
        $name = bin2hex(random_bytes(10)) . '.webp';
        $ok = imagewebp($aus, $dir . '/' . $name, 82);
        imagedestroy($aus);
        if (!$ok) {
            throw new RuntimeException('Das Foto ließ sich nicht speichern. Ist assets/img/team beschreibbar?');
        }
        @chmod($dir . '/' . $name, 0644);
        return 'assets/img/team/' . $name;
    }

    /** Erlaubt nur Pfade, die von speichern() stammen. */
    public static function gueltig(string $pfad): bool
    {
        return preg_match('#^assets/img/team/[a-f0-9]{20}\.webp$#', $pfad) === 1;
    }
}
