<?php
declare(strict_types=1);

// Schutz vor direktem Aufruf. Wirkt unabhängig von der Serverkonfiguration
// und greift auch dann, wenn .htaccess oder die nginx-Regeln nicht aktiv sind.
if (!defined('FW_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Prüft und normalisiert die Formulareingaben.
 *
 * Grundsatz: nur ausdrücklich erlaubte Werte werden übernommen. Alles andere
 * wird verworfen, nicht repariert.
 */
final class Validator
{
    /** @var array<string,string> Feldname => Fehlermeldung */
    private array $errors = [];

    /** @var array<string,string> Geprüfte und normalisierte Werte */
    private array $clean = [];

    /** Zulässige Werte des Auswahlfelds — muss zum Markup in kontakt.html passen. */
    /**
     * Zulässige Werte des Auswahlfelds und ihre lesbare Bezeichnung.
     * Der Schlüssel steht im Formular (bewusst ohne Umlaute), die
     * Bezeichnung erscheint in der Benachrichtigung.
     */
    private const THEMEN = [
        'Schutz'        => 'Schutz',
        'Vorsorge'      => 'Vorsorge',
        'Vermoegen'     => 'Vermögen',
        'Unternehmen'   => 'Unternehmen',
        'Vertragscheck' => 'Prüfung bestehender Verträge',
        'Schadensfall'  => 'Schadensfall',
        'Sonstiges'     => 'Sonstiges',
    ];

    /** @param array<string,mixed> $input */
    public function validate(array $input): bool
    {
        $this->errors = [];
        $this->clean  = [];

        $this->text($input, 'vorname',  'Vorname',  2, 60, true);
        $this->text($input, 'nachname', 'Nachname', 2, 60, true);
        $this->email($input);
        $this->phone($input);
        $this->choice($input);
        $this->message($input);
        $this->consent($input);

        $this->clean['rueckruf'] = $this->flag($input, 'rueckruf') ? 'ja' : 'nein';

        // Zusatzangaben des Erstgespräch-Assistenten — nur feste Werte
        $this->enum($input, 'quelle',      ['funnel', 'formular']);
        $this->enum($input, 'kontaktweg',  ['telefon', 'video', 'vor-ort']);
        $this->enum($input, 'zeitfenster', ['vormittag', 'nachmittag', 'abend']);
        $antworten = $this->normalize($input['antworten'] ?? '');
        if ($antworten !== '' && preg_match('/^[a-z+|-]{1,80}$/', $antworten) === 1) {
            $this->clean['antworten'] = $antworten;
        }

        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,string> */
    public function clean(): array
    {
        return $this->clean;
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, string $label, int $min, int $max, bool $required): void
    {
        $value = $this->normalize($input[$key] ?? '');

        if ($value === '') {
            if ($required) {
                $this->errors[$key] = $label . ' wird benötigt.';
            }
            return;
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min) {
            $this->errors[$key] = $label . ' ist zu kurz.';
            return;
        }
        if ($length > $max) {
            $this->errors[$key] = $label . ' darf höchstens ' . $max . ' Zeichen lang sein.';
            return;
        }

        // Namensfelder: keine Steuerzeichen, keine typischen Spam-Muster
        if (preg_match('/(https?:|www\.|\[url|<[a-z])/iu', $value) === 1) {
            $this->errors[$key] = $label . ' enthält unzulässige Zeichen.';
            return;
        }

        $this->clean[$key] = $value;
    }

    /** @param array<string,mixed> $input */
    private function email(array $input): void
    {
        $value = $this->normalize($input['email'] ?? '');

        if ($value === '') {
            $this->errors['email'] = 'Die E-Mail-Adresse wird benötigt.';
            return;
        }
        if (mb_strlen($value, 'UTF-8') > 180) {
            $this->errors['email'] = 'Die E-Mail-Adresse ist zu lang.';
            return;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
        if ($filtered === false) {
            $this->errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
            return;
        }

        // Doppelte Absicherung gegen Header-Injection
        if (preg_match('/[\r\n\t]/', $filtered) === 1) {
            $this->errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
            return;
        }

        $this->clean['email'] = $filtered;
    }

    /** @param array<string,mixed> $input */
    private function phone(array $input): void
    {
        $value = $this->normalize($input['telefon'] ?? '');
        if ($value === '') {
            return; // optionales Feld
        }
        if (mb_strlen($value, 'UTF-8') > 40) {
            $this->errors['telefon'] = 'Die Telefonnummer ist zu lang.';
            return;
        }
        if (preg_match('/^[0-9+()\/\.\-\s]{5,40}$/', $value) !== 1) {
            $this->errors['telefon'] = 'Bitte geben Sie eine gültige Telefonnummer an.';
            return;
        }
        $this->clean['telefon'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function choice(array $input): void
    {
        $value = $this->normalize($input['thema'] ?? '');
        if ($value === '') {
            $this->errors['thema'] = 'Bitte wählen Sie ein Thema aus.';
            return;
        }
        if (!array_key_exists($value, self::THEMEN)) {
            $this->errors['thema'] = 'Bitte wählen Sie ein gültiges Thema aus.';
            return;
        }
        $this->clean['thema'] = self::THEMEN[$value];
    }

    /** @param array<string,mixed> $input */
    private function message(array $input): void
    {
        $raw = $input['nachricht'] ?? '';
        if (!is_string($raw)) {
            $this->errors['nachricht'] = 'Die Nachricht wird benötigt.';
            return;
        }

        // Zeilenumbrüche bleiben erhalten, andere Steuerzeichen nicht
        $value = trim(preg_replace('/[^\P{C}\n]+/u', '', $raw) ?? '');
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if ($value === '') {
            $this->errors['nachricht'] = 'Die Nachricht wird benötigt.';
            return;
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < 20) {
            $this->errors['nachricht'] = 'Bitte beschreiben Sie Ihr Anliegen in mindestens 20 Zeichen.';
            return;
        }
        if ($length > 3000) {
            $this->errors['nachricht'] = 'Die Nachricht darf höchstens 3000 Zeichen lang sein.';
            return;
        }

        // Grober Spamfilter: sehr viele Links deuten auf automatisierte Einsendungen hin
        if (preg_match_all('/https?:\/\//i', $value) > 3) {
            $this->errors['nachricht'] = 'Die Nachricht enthält zu viele Links.';
            return;
        }

        $this->clean['nachricht'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function consent(array $input): void
    {
        if (!$this->flag($input, 'datenschutz')) {
            $this->errors['datenschutz'] = 'Bitte bestätigen Sie die Kenntnisnahme der Datenschutzerklärung.';
            return;
        }
        $this->clean['datenschutz'] = 'ja';
    }

    /**
     * Übernimmt ein optionales Feld nur, wenn es exakt einem erlaubten Wert entspricht.
     * @param array<string,mixed> $input
     * @param list<string> $allowed
     */
    private function enum(array $input, string $key, array $allowed): void
    {
        $value = $this->normalize($input[$key] ?? '');
        if ($value !== '' && in_array($value, $allowed, true)) {
            $this->clean[$key] = $value;
        }
    }

    /** @param array<string,mixed> $input */
    private function flag(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        return $value === '1' || $value === 1 || $value === 'on' || $value === true;
    }

    /**
     * Vereinheitlicht einen Einzeilen-Wert: Unicode-Normalisierung der
     * Leerzeichen, Entfernung aller Steuerzeichen inklusive CR und LF.
     * Damit ist eine Header-Injection über diese Felder ausgeschlossen.
     */
    private function normalize(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/\p{C}+/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }
}
