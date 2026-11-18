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
        if (!empty($settings['radio_style'])) {
            $system .= "\n\n" . self::radioGuidelines((string)($settings['elevenlabs']['model_id'] ?? ''));
        }

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

    /** Answer DeepSeek must give when the sources hold nothing usable. */
    public const string NO_CONTENT = 'AUCUN_CONTENU';

    public const string CHRONICLE_SYSTEM_PROMPT = <<<'TXT'
        Tu es {animateur}, chroniqueur ou chroniqueuse de la radio {station}. Tu présentes la chronique « {chronique} », en {langue}. Nous sommes le {jour} {date}, il est {heure}.

        Règles à respecter :
        - Utilise uniquement les informations des sources fournies. N'invente rien : aucun fait, chiffre, nom ou date absent des sources.
        - Reformule avec tes propres mots, ne recopie pas les phrases des sources.
        - Tiens compte de la date du jour : une information datée qui est dépassée ne doit pas être présentée comme à venir.
        - Écris uniquement le texte qui sera lu à voix haute : pas de titre, pas de liste, pas d'adresse web, pas d'emoji.
        - Environ {mots} mots, soit à peu près {duree} secondes de parole. Commence par une courte introduction et termine par une phrase de conclusion.
        - Si les sources ne contiennent aucune information utile pour cette chronique, réponds uniquement : AUCUN_CONTENU
        TXT;

    /**
     * Messages for a chronicle: the rules, the style, the request and the sources' text.
     *
     * @param array<int, array{label: string, url: string, ok: bool, text: string}> $sources
     */
    public static function chronicleMessages(array $settings, array $chronicle, array $voice, array $variables, array $sources): array
    {
        $system = self::fill(self::CHRONICLE_SYSTEM_PROMPT, $variables);
        if (!empty($settings['radio_style'])) {
            $system .= "\n\n" . self::radioGuidelines((string)($settings['elevenlabs']['model_id'] ?? ''));
        }
        $style = trim(implode("\n", array_filter([
            trim((string)($voice['personality'] ?? '')),
            trim((string)($chronicle['style'] ?? '')),
        ])));
        if ($style !== '') {
            $system .= "\n\nTon style :\n" . self::fill($style, $variables);
        }

        $user = self::fill((string)$chronicle['prompt'], $variables) . "\n\nSources :";
        $n = 0;
        foreach ($sources as $source) {
            if (empty($source['ok'])) {
                continue;
            }
            $n++;
            $user .= "\n\n### Source " . $n . ' : ' . $source['label'] . ($source['url'] !== '' ? ' (' . $source['url'] . ')' : '')
                . "\n" . trim((string)$source['text']);
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /** Eleven v3 and v4 (and their variants) read [audio tags] and ignore SSML break tags. */
    public static function supportsAudioTags(string $model): bool
    {
        return (bool)preg_match('/^eleven_v[34]/', $model);
    }

    /**
     * Writing rules for the ear, added to the system prompt ("Écriture radio" option).
     * The part about pauses and tone depends on the voice model.
     */
    public static function radioGuidelines(string $model): string
    {
        $text = <<<'TXT'
            Écris pour l'oreille, pas pour la lecture : ton texte sera dit à voix haute par un animateur de radio généraliste.
            - Syntaxe orale : phrases courtes, parfois sans verbe (« Une belle pépite, ce titre ! »), mots d'appui naturels (« allez », « bon », « et là »), tu t'adresses directement aux auditeurs.
            - Garde l'élan de la voix : n'enchaîne pas une série de phrases courtes terminées par un point. Relie les idées avec des virgules, des tirets (—) ou des points de suspension, et termine plutôt sur une exclamation ou une question qui relance.
            - Varie le relief : des exclamations et des questions aux auditeurs, sans en abuser.
            - Pas de liste, pas de parenthèses, pas d'abréviation : écris tout comme cela se prononce.
            TXT;

        if (self::supportsAudioTags($model)) {
            $text .= "\n" . <<<'TXT'
                - Indications de jeu : tu peux placer une ou deux indications en anglais entre crochets, juste avant le passage concerné, par exemple [warmly], [cheerfully], [excited], [softly], [chuckles] ou [laughs]. Jamais plus de deux, jamais deux indications contradictoires, et aucun autre texte entre crochets.
                - Pour une courte pause, utilise des points de suspension (…) ou un tiret (—).
                TXT;
        } else {
            $text .= "\n" . <<<'TXT'
                - Pour une vraie pause (par exemple juste avant d'annoncer un titre ou le nom de la radio), tu peux insérer au maximum deux fois la balise <break time="0.5s" />, avec une durée entre 0.3s et 1.2s. Aucune autre balise. Pour une pause plus légère, utilise des points de suspension (…) ou un tiret (—).
                TXT;
        }

        return $text;
    }

    /**
     * Keeps only what should be spoken: removes reasoning blocks, Markdown, surrounding quotes
     * and stage directions. Depending on the voice model, keeps up to 3 [audio tags]
     * (Eleven v3/v4) or up to 2 <break time="…" /> pauses (other models).
     */
    public static function clean(string $text, string $model = '', int $max = 2500): string
    {
        $tags = self::supportsAudioTags($model);

        $text = (string)preg_replace('~<think>.*?</think>~is', '', $text);

        // Pauses: normalized, at most 2 kept (only for models that understand them).
        $breaks = [];
        $text = (string)preg_replace_callback(
            '~<\s*break\b[^>]*?time\s*=\s*["\']?\s*(\d+(?:[.,]\d+)?)\s*(ms|s)?\s*["\']?[^>]*>~i',
            static function (array $m) use ($tags, &$breaks): string {
                if ($tags || count($breaks) >= 2) {
                    return ' ';
                }
                $seconds = (float)str_replace(',', '.', $m[1]);
                if (strtolower($m[2] ?? '') === 'ms') {
                    $seconds /= 1000;
                }
                $seconds = max(0.2, min(3.0, $seconds));
                $breaks[] = '<break time="' . rtrim(rtrim(sprintf('%.1F', $seconds), '0'), '.') . 's" />';

                return ' ' . "\x01" . (count($breaks) - 1) . "\x01" . ' ';
            },
            $text
        );
        $text = (string)preg_replace('~<[^>]*>~', ' ', $text);

        $text = (string)preg_replace('~^\s*(#+|[-*]\s+|>\s*)~m', '', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string)preg_replace('~\*([^*\n]+)\*~u', '$1', $text);
        $text = (string)preg_replace('~\((?:[^()]*?(?:rire|sourire|musique|pause|jingle|ton|voix|soupir)[^()]*)\)~iu', '', $text);

        // Audio tags: short English directions only, at most 3.
        $kept = 0;
        $text = (string)preg_replace_callback(
            '~\[([^\]]*)\]~u',
            static function (array $m) use ($tags, &$kept): string {
                $tag = trim($m[1]);
                if ($tags && $kept < 3 && preg_match('/^[a-z][a-z \'-]{1,30}$/i', $tag)) {
                    $kept++;

                    return '[' . strtolower($tag) . ']';
                }

                return ' ';
            },
            $text
        );

        $text = (string)preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $text = trim($text, " \t\"'«»“”");
        $text = (string)preg_replace('/\s+([,.…])/u', '$1', $text);

        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '! ') ?: 0, mb_strrpos($cut, '? ') ?: 0);
            $text = $end > $max / 2 ? mb_substr($cut, 0, $end + 1) : $cut;
        }

        return (string)preg_replace_callback("~\x01(\d)\x01~", static fn(array $m): string => $breaks[(int)$m[1]] ?? '', $text);
    }
}
