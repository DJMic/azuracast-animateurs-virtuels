<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use DateTimeImmutable;

/**
 * Builds the messages sent to DeepSeek and cleans the text it returns.
 * Pure functions, no AzuraCast dependency (tested by tests/run.php).
 */
final class PromptBuilder
{
    /** About 2.5 spoken words per second in French radio speech. */
    public const float WORDS_PER_SECOND = 2.5;

    public const string MISSING = '(information non disponible)';

    private const array DAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const array MONTHS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /** Variables offered in prompts, with their description (shown in the interface). */
    public const array VARIABLES = [
        'animateur' => 'Nom de l\'animateur',
        'station' => 'Nom de la station',
        'heure' => 'Heure de diffusion prévue (ex. 7h30)',
        'jour' => 'Jour de la semaine (ex. lundi)',
        'date' => 'Date (ex. 14 novembre)',
        'moment' => 'matin, midi, après-midi, soirée ou nuit',
        'titre_precedent' => 'Titre du morceau diffusé juste avant',
        'artiste_precedent' => 'Artiste du morceau diffusé juste avant',
        'titre_suivant' => 'Titre du morceau diffusé juste après',
        'artiste_suivant' => 'Artiste du morceau diffusé juste après',
        'duree' => 'Durée de parole visée, en secondes',
        'mots' => 'Nombre de mots visé',
        'langue' => 'Langue de la station',
    ];

    /**
     * @param array{title?: ?string, artist?: ?string} $previous
     * @param array{title?: ?string, artist?: ?string} $next
     */
    public static function variables(
        array $host,
        string $stationName,
        string $language,
        DateTimeImmutable $playAt,
        array $previous = [],
        array $next = []
    ): array {
        $hour = (int)$playAt->format('G');
        $minute = (int)$playAt->format('i');
        $seconds = (int)($host['target_seconds'] ?? 20);

        return [
            'animateur' => (string)($host['name'] ?? ''),
            'station' => $stationName,
            'heure' => $hour . 'h' . ($minute > 0 ? sprintf('%02d', $minute) : ''),
            'jour' => self::DAYS[(int)$playAt->format('N')],
            'date' => (int)$playAt->format('j') . ' ' . self::MONTHS[(int)$playAt->format('n')],
            'moment' => self::moment($hour),
            'titre_precedent' => trim((string)($previous['title'] ?? '')),
            'artiste_precedent' => trim((string)($previous['artist'] ?? '')),
            'titre_suivant' => trim((string)($next['title'] ?? '')),
            'artiste_suivant' => trim((string)($next['artist'] ?? '')),
            'duree' => (string)$seconds,
            'mots' => (string)max(5, (int)round($seconds * self::WORDS_PER_SECOND)),
            'langue' => $language,
        ];
    }

    public static function moment(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour < 12 => 'matin',
            $hour >= 12 && $hour < 14 => 'midi',
            $hour >= 14 && $hour < 18 => 'après-midi',
            $hour >= 18 && $hour < 23 => 'soirée',
            default => 'nuit',
        };
    }

    /** Replaces {variable} (case-insensitive); unknown variables are left untouched. */
    public static function fill(string $template, array $variables): string
    {
        return (string)preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $m) use ($variables): string {
                $key = strtolower($m[1]);
                if (!array_key_exists($key, $variables)) {
                    return $m[0];
                }
                $value = trim((string)$variables[$key]);

                return $value === '' ? self::MISSING : $value;
            },
            $template
        );
    }

    /**
     * @param string[] $recent last texts of this host, most recent first
     *
     * @return array<int, array{role: string, content: string}>
     */
    public static function messages(array $settings, array $host, array $prompt, array $variables, array $recent = []): array
    {
        $system = self::fill((string)$settings['system_prompt'], $variables);

        $personality = trim((string)($host['personality'] ?? ''));
        if ($personality !== '') {
            $system .= "\n\nTa personnalité et ton style :\n" . self::fill($personality, $variables);
        }

        $recent = array_values(array_filter(array_map('trim', $recent)));
        if ($recent !== []) {
            $system .= "\n\nTes dernières interventions à l'antenne (ne les répète pas, varie les formulations) :";
            foreach ($recent as $text) {
                $system .= "\n- " . mb_substr($text, 0, 400);
            }
        }

        $user = self::fill((string)$prompt['text'], $variables);
        if (!empty($prompt['timeless'])) {
            $user .= "\n\n" . Library::TIMELESS_INSTRUCTION;
        }
        if (str_contains($system . $user, self::MISSING)) {
            $system .= "\n\nSi une information est indiquée comme non disponible, n'en parle pas.";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Keeps only what should be spoken: removes reasoning blocks, Markdown, surrounding quotes
     * and stage directions. Square brackets are kept for Eleven v3 audio tags if $keepTags.
     */
    public static function clean(string $text, bool $keepTags = false, int $max = 2500): string
    {
        $text = (string)preg_replace('~<think>.*?</think>~is', '', $text);
        $text = (string)preg_replace('~^\s*(#+|[-*]\s+|>\s*)~m', '', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string)preg_replace('~\*([^*\n]+)\*~u', '$1', $text);
        $text = (string)preg_replace('~\((?:[^()]*?(?:rire|sourire|musique|pause|jingle|ton|voix|soupir)[^()]*)\)~iu', '', $text);
        if (!$keepTags) {
            $text = (string)preg_replace('~\[[^\]]*\]~u', '', $text);
        }
        $text = (string)preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $text = trim($text, " \t\"'«»“”");

        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '! ') ?: 0, mb_strrpos($cut, '? ') ?: 0);
            $text = $end > $max / 2 ? mb_substr($cut, 0, $end + 1) : $cut;
        }

        return $text;
    }
}
