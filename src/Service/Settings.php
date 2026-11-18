<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use InvalidArgumentException;

/**
 * Station settings: defaults, validation and the masking of API keys for the browser.
 * Pure functions, no AzuraCast dependency (tested by tests/run.php).
 */
final class Settings
{
    public const string MASK = '••••••••';

    public const string DEFAULT_SYSTEM_PROMPT = <<<'TXT'
        Tu es {animateur}, animateur ou animatrice de la radio {station}. Tu parles en direct à l'antenne, en {langue}.

        Règles à respecter :
        - Écris uniquement le texte qui sera lu à voix haute, rien d'autre : pas de titre, pas d'indication de ton, pas de texte entre crochets ou entre parenthèses, pas d'emoji, pas de guillemets autour du texte.
        - Environ {mots} mots, soit à peu près {duree} secondes de parole.
        - Un ton naturel et chaleureux, des phrases courtes, comme un vrai animateur radio.
        - N'invente aucune information vérifiable : pas de météo, pas d'actualité, pas de date de concert.
        - Écris les heures et les nombres comme on les prononce (par exemple « sept heures et demie »).
        TXT;

    public const array PROMPT_USAGES = ['all', 'between', 'fixed'];

    public const int MAX_ACCOUNTS = 5;

    /** Id given to the key of versions before 1.3.0. */
    public const string LEGACY_ACCOUNT_ID = 'compte1';

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'language' => 'français',
            'deepseek' => [
                'api_key' => '',
                'model' => 'deepseek-flash',
                'temperature' => 1.0,
                'max_tokens' => 600,
                'thinking' => false,
            ],
            'elevenlabs' => [
                'accounts' => [],
                'model_id' => 'eleven_v4',
                'language_code' => 'fr',
                'low_credits' => 500,
            ],
            'system_prompt' => self::DEFAULT_SYSTEM_PROMPT,
            'radio_style' => true,
            'avoid_repeat' => 3,
            'bed' => [
                'enabled' => true,
                'playlist_id' => null,
                'duck_db' => 15.0,
                'bed_gain_db' => 0.0,
                'intro' => 3.0,
                'outro' => 3.0,
                'fade_in' => 1.0,
                'fade_out' => 2.0,
                'ramp' => 0.8,
                'random_start' => false,
            ],
            'output' => [
                'normalize' => true,
                'lufs' => -14.0,
                'bitrate' => 192,
                'meta_title' => '{animateur}',
                'meta_artist' => '{station}',
                'hide_metadata' => false,
            ],
            'limits' => [
                'daily_enabled' => true,
                'daily_max' => 100,
            ],
            'retention_days' => 7,
            'hosts' => [],
            'chronicles' => [],
        ];
    }

    public static function defaultHost(): array
    {
        return [
            'id' => '',
            'name' => '',
            'enabled' => true,
            'voice_id' => '',
            'personality' => '',
            'target_seconds' => 20,
            'voice' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.0,
                'speed' => 1.0,
                'speaker_boost' => true,
            ],
            'bed_playlist_id' => null,
            'account_id' => '',
            'prompts' => [
                [
                    'id' => '',
                    'name' => 'Enchaînement',
                    'usage' => 'between',
                    'enabled' => true,
                    'timeless' => false,
                    'text' => 'Fais une courte transition : on vient d\'écouter « {titre_precedent} » de '
                        . '{artiste_precedent}, et tu annonces « {titre_suivant} » de {artiste_suivant}.',
                ],
                [
                    'id' => '',
                    'name' => 'Heure',
                    'usage' => 'fixed',
                    'enabled' => true,
                    'timeless' => false,
                    'text' => 'Donne l\'heure ({heure}), salue les auditeurs en ce {jour} {moment} et rappelle '
                        . 'qu\'ils écoutent {station}.',
                ],
                [
                    'id' => '',
                    'name' => 'Ambiance (réutilisable)',
                    'usage' => 'between',
                    'enabled' => true,
                    'timeless' => true,
                    'text' => 'Dis une courte phrase chaleureuse pour rappeler aux auditeurs qu\'ils écoutent {station} '
                        . 'et leur donner envie de rester à l\'écoute. Varie le ton et les idées à chaque fois.',
                ],
            ],
            'library' => Library::DEFAULTS,
            'slots' => [
                [
                    'id' => '',
                    'days' => [1, 2, 3, 4, 5],
                    'start' => '06:00',
                    'end' => '09:00',
                    'every_n' => 4,
                    'times' => ['07:00', '08:00'],
                ],
            ],
        ];
    }

    /**
     * Merges stored data over the defaults (keeps working after new options are added).
     */
    public static function withDefaults(array $stored): array
    {
        $settings = self::mergeDeep(self::defaults(), $stored);

        // Before version 1.3.0: a single ElevenLabs key, which becomes "Compte 1".
        $legacyKey = (string)($settings['elevenlabs']['api_key'] ?? '');
        unset($settings['elevenlabs']['api_key']);
        if (!is_array($settings['elevenlabs']['accounts'])) {
            $settings['elevenlabs']['accounts'] = [];
        }
        if ($settings['elevenlabs']['accounts'] === [] && $legacyKey !== '') {
            $settings['elevenlabs']['accounts'][] = ['id' => self::LEGACY_ACCOUNT_ID, 'name' => 'Compte 1', 'api_key' => $legacyKey];
        }

        $settings['hosts'] = array_values(array_map(
            static fn(mixed $host): array => self::mergeDeep(
                array_diff_key(self::defaultHost(), ['prompts' => 1, 'slots' => 1]) + ['prompts' => [], 'slots' => []],
                is_array($host) ? $host : []
            ),
            is_array($stored['hosts'] ?? null) ? $stored['hosts'] : []
        ));

        $settings['chronicles'] = array_values(array_map(
            static fn(mixed $c): array => self::mergeDeep(ChronicleSettings::defaults(), is_array($c) ? $c : []),
            is_array($stored['chronicles'] ?? null) ? $stored['chronicles'] : []
        ));

        return $settings;
    }

    /**
     * Validates the settings sent by the browser. Masked or empty API keys keep the stored value
     * unless "clear" was requested.
     */
    public static function fromInput(array $input, array $current): array
    {
        $current = self::withDefaults($current);
        $d = self::defaults();
        $out = $d;

        $out['enabled'] = self::bool($input['enabled'] ?? false);
        $out['language'] = self::text($input['language'] ?? $d['language'], 40) ?: $d['language'];

        $ds = is_array($input['deepseek'] ?? null) ? $input['deepseek'] : [];
        $out['deepseek'] = [
            'api_key' => self::secret($ds, $current['deepseek']['api_key']),
            'model' => self::text($ds['model'] ?? '', 80) ?: $d['deepseek']['model'],
            'temperature' => self::float($ds['temperature'] ?? 1.0, 0.0, 2.0),
            'max_tokens' => self::int($ds['max_tokens'] ?? 600, 50, 4000),
            'thinking' => self::bool($ds['thinking'] ?? false),
        ];

        $el = is_array($input['elevenlabs'] ?? null) ? $input['elevenlabs'] : [];
        $currentKeys = array_column($current['elevenlabs']['accounts'], 'api_key', 'id');
        $accounts = [];
        $accountNames = [];
        foreach (is_array($el['accounts'] ?? null) ? $el['accounts'] : [] as $account) {
            if (!is_array($account)) {
                continue;
            }
            $id = self::uid($account['id'] ?? '');
            $accountName = self::text($account['name'] ?? '', 60) ?: 'Compte ' . (count($accounts) + 1);
            if (isset($accountNames[mb_strtolower($accountName)])) {
                throw new InvalidArgumentException('Deux comptes ElevenLabs portent le nom « ' . $accountName . ' ».');
            }
            $accountNames[mb_strtolower($accountName)] = true;
            $accounts[] = [
                'id' => $id,
                'name' => $accountName,
                'api_key' => self::secret($account, (string)($currentKeys[$id] ?? '')),
            ];
        }
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new InvalidArgumentException('Au maximum ' . self::MAX_ACCOUNTS . ' comptes ElevenLabs par station.');
        }
        foreach ($accounts as $account) {
            if ($account['api_key'] === '') {
                throw new InvalidArgumentException('Renseignez la clé API du compte ElevenLabs « ' . $account['name'] . ' ».');
            }
        }
        $out['elevenlabs'] = [
            'accounts' => $accounts,
            'model_id' => self::text($el['model_id'] ?? '', 80) ?: $d['elevenlabs']['model_id'],
            'language_code' => strtolower(self::text($el['language_code'] ?? '', 5)),
            'low_credits' => self::int($el['low_credits'] ?? 500, 0, 1000000),
        ];
        if ($out['elevenlabs']['language_code'] !== '' && !preg_match('/^[a-z]{2,3}$/', $out['elevenlabs']['language_code'])) {
            throw new InvalidArgumentException('Code de langue ElevenLabs invalide (exemple : fr).');
        }

        $out['system_prompt'] = self::text($input['system_prompt'] ?? '', 6000, true) ?: self::DEFAULT_SYSTEM_PROMPT;
        $out['avoid_repeat'] = self::int($input['avoid_repeat'] ?? 3, 0, 10);
        $out['radio_style'] = self::bool($input['radio_style'] ?? true);

        $bed = is_array($input['bed'] ?? null) ? $input['bed'] : [];
        $out['bed'] = [
            'enabled' => self::bool($bed['enabled'] ?? true),
            'playlist_id' => self::id($bed['playlist_id'] ?? null),
            'duck_db' => self::float($bed['duck_db'] ?? 15, 0.0, 60.0),
            'bed_gain_db' => self::float($bed['bed_gain_db'] ?? 0, -40.0, 12.0),
            'intro' => self::float($bed['intro'] ?? 3, 0.0, 30.0),
            'outro' => self::float($bed['outro'] ?? 3, 0.0, 30.0),
            'fade_in' => self::float($bed['fade_in'] ?? 1, 0.0, 10.0),
            'fade_out' => self::float($bed['fade_out'] ?? 2, 0.0, 15.0),
            'ramp' => self::float($bed['ramp'] ?? 0.8, 0.05, 5.0),
            'random_start' => self::bool($bed['random_start'] ?? false),
        ];

        $o = is_array($input['output'] ?? null) ? $input['output'] : [];
        $out['output'] = [
            'normalize' => self::bool($o['normalize'] ?? true),
            'lufs' => self::float($o['lufs'] ?? -14, -30.0, -6.0),
            'bitrate' => in_array((int)($o['bitrate'] ?? 192), [128, 160, 192, 256, 320], true) ? (int)$o['bitrate'] : 192,
            'meta_title' => self::text($o['meta_title'] ?? '', 120) ?: $d['output']['meta_title'],
            'meta_artist' => self::text($o['meta_artist'] ?? '', 120),
            'hide_metadata' => self::bool($o['hide_metadata'] ?? false),
        ];

        $l = is_array($input['limits'] ?? null) ? $input['limits'] : [];
        $out['limits'] = [
            'daily_enabled' => self::bool($l['daily_enabled'] ?? true),
            'daily_max' => self::int($l['daily_max'] ?? 100, 1, 5000),
        ];
        $out['retention_days'] = self::int($input['retention_days'] ?? 7, 1, 365);

        $out['hosts'] = [];
        $names = [];
        foreach (is_array($input['hosts'] ?? null) ? $input['hosts'] : [] as $host) {
            $host = self::host(is_array($host) ? $host : []);
            $key = mb_strtolower($host['name']);
            if (isset($names[$key])) {
                throw new InvalidArgumentException('Deux animateurs portent le nom « ' . $host['name'] . ' ».');
            }
            $names[$key] = true;
            $out['hosts'][] = $host;
        }

        // Each host uses one of the accounts (the first one if none is chosen).
        $accountIds = array_column($accounts, 'id');
        foreach ($out['hosts'] as &$host) {
            if (!in_array($host['account_id'], $accountIds, true)) {
                if ($host['account_id'] !== '' && $host['enabled']) {
                    throw new InvalidArgumentException(
                        'Le compte ElevenLabs choisi pour « ' . $host['name'] . ' » n\'existe plus : choisissez-en un autre.'
                    );
                }
                $host['account_id'] = $accountIds[0] ?? '';
            }
        }
        unset($host);

        $out['chronicles'] = [];
        $chronicleNames = [];
        $hostIds = array_column(array_filter($out['hosts'], static fn(array $h): bool => $h['voice_id'] !== ''), 'id');
        foreach (is_array($input['chronicles'] ?? null) ? $input['chronicles'] : [] as $chronicle) {
            $chronicle = ChronicleSettings::validate(is_array($chronicle) ? $chronicle : [], $hostIds, $accountIds);
            $key = mb_strtolower($chronicle['name']);
            if (isset($chronicleNames[$key])) {
                throw new InvalidArgumentException('Deux chroniques portent le nom « ' . $chronicle['name'] . ' ».');
            }
            $chronicleNames[$key] = true;
            $out['chronicles'][] = $chronicle;
        }

        if ($out['enabled']) {
            if ($out['deepseek']['api_key'] === '' || $accounts === []) {
                throw new InvalidArgumentException(
                    'Pour activer les animateurs virtuels, renseignez la clé API DeepSeek et au moins un compte ElevenLabs.'
                );
            }
        }

        return $out;
    }

    public static function host(array $in): array
    {
        $d = self::defaultHost();
        $name = self::text($in['name'] ?? '', 80);
        if ($name === '') {
            throw new InvalidArgumentException('Chaque animateur doit avoir un nom.');
        }
        $voiceId = self::text($in['voice_id'] ?? '', 64);
        if ($voiceId !== '' && !preg_match('/^[A-Za-z0-9_-]{5,64}$/', $voiceId)) {
            throw new InvalidArgumentException('Voice ID invalide pour « ' . $name . ' ».');
        }
        $voice = is_array($in['voice'] ?? null) ? $in['voice'] : [];

        $host = [
            'id' => self::uid($in['id'] ?? ''),
            'name' => $name,
            'enabled' => self::bool($in['enabled'] ?? true),
            'voice_id' => $voiceId,
            'personality' => self::text($in['personality'] ?? '', 4000, true),
            'target_seconds' => self::int($in['target_seconds'] ?? 20, 3, 180),
            'voice' => [
                'stability' => self::float($voice['stability'] ?? 0.5, 0.0, 1.0),
                'similarity_boost' => self::float($voice['similarity_boost'] ?? 0.75, 0.0, 1.0),
                'style' => self::float($voice['style'] ?? 0.0, 0.0, 1.0),
                'speed' => self::float($voice['speed'] ?? 1.0, 0.7, 1.2),
                'speaker_boost' => self::bool($voice['speaker_boost'] ?? true),
            ],
            'bed_playlist_id' => self::id($in['bed_playlist_id'] ?? null),
            'account_id' => preg_match('/^[a-z0-9]{6,32}$/', (string)($in['account_id'] ?? '')) ? (string)$in['account_id'] : '',
            'prompts' => [],
            'slots' => [],
        ];

        $library = is_array($in['library'] ?? null) ? $in['library'] : [];
        $host['library'] = [
            'enabled' => self::bool($library['enabled'] ?? false),
            'min_size' => self::int($library['min_size'] ?? 20, 1, 1000),
            'max_share' => self::int($library['max_share'] ?? 60, 0, 100),
            'min_gap_days' => self::int($library['min_gap_days'] ?? 3, 0, 365),
            'max_uses' => self::int($library['max_uses'] ?? 0, 0, 1000),
            'max_size' => self::int($library['max_size'] ?? 200, 5, 2000),
            'fallback' => self::bool($library['fallback'] ?? true),
        ];
        if ($host['library']['max_size'] < $host['library']['min_size']) {
            throw new InvalidArgumentException(
                '« ' . $name . ' » : la taille maximale de la bibliothèque doit être au moins égale à la taille minimale.'
            );
        }

        foreach (is_array($in['prompts'] ?? null) ? $in['prompts'] : [] as $prompt) {
            if (!is_array($prompt)) {
                continue;
            }
            $text = self::text($prompt['text'] ?? '', 4000, true);
            if ($text === '') {
                continue;
            }
            $usage = (string)($prompt['usage'] ?? 'all');
            $timeless = self::bool($prompt['timeless'] ?? false);
            $promptName = self::text($prompt['name'] ?? '', 80) ?: 'Prompt ' . (count($host['prompts']) + 1);
            if ($timeless && ($vars = Library::contextVariables($text)) !== []) {
                throw new InvalidArgumentException(
                    '« ' . $name . ' », prompt « ' . $promptName . ' » : un prompt réutilisable ne peut pas contenir '
                    . implode(', ', array_map(static fn(string $v): string => '{' . $v . '}', $vars)) . '.'
                );
            }
            $host['prompts'][] = [
                'id' => self::uid($prompt['id'] ?? ''),
                'name' => $promptName,
                'usage' => in_array($usage, self::PROMPT_USAGES, true) ? $usage : 'all',
                'enabled' => self::bool($prompt['enabled'] ?? true),
                'timeless' => $timeless,
                'text' => $text,
            ];
        }

        foreach (is_array($in['slots'] ?? null) ? $in['slots'] : [] as $i => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $label = '« ' . $name . ' », tranche ' . ((int)$i + 1);
            $days = array_values(array_unique(array_filter(
                array_map('intval', is_array($slot['days'] ?? null) ? $slot['days'] : []),
                static fn(int $day): bool => $day >= 1 && $day <= 7
            )));
            sort($days);
            if ($days === []) {
                throw new InvalidArgumentException($label . ' : choisissez au moins un jour.');
            }
            $start = self::time($slot['start'] ?? '', $label . ' : heure de début');
            $end = self::time($slot['end'] ?? '', $label . ' : heure de fin');
            $everyN = self::int($slot['every_n'] ?? 0, 0, 100);
            $times = [];
            foreach (is_array($slot['times'] ?? null) ? $slot['times'] : [] as $time) {
                if (trim((string)$time) === '') {
                    continue;
                }
                $time = self::time($time, $label . ' : heure fixe');
                if (!Planner::timeInSlot($time, $start, $end)) {
                    throw new InvalidArgumentException(
                        $label . ' : l\'heure fixe ' . $time . ' est en dehors de la tranche ' . $start . '–' . $end . '.'
                    );
                }
                $times[] = $time;
            }
            $times = array_values(array_unique($times));
            usort($times, static fn(string $a, string $b): int => Planner::minutesFromStart($a, $start)
                <=> Planner::minutesFromStart($b, $start));
            if ($everyN === 0 && $times === []) {
                throw new InvalidArgumentException(
                    $label . ' : indiquez un nombre de morceaux entre deux interventions ou au moins une heure fixe.'
                );
            }
            $host['slots'][] = [
                'id' => self::uid($slot['id'] ?? ''),
                'days' => $days,
                'start' => $start,
                'end' => $end,
                'every_n' => $everyN,
                'times' => $times,
            ];
        }

        if ($host['enabled']) {
            if ($host['voice_id'] === '') {
                throw new InvalidArgumentException('Indiquez le Voice ID ElevenLabs de « ' . $name . ' ».');
            }
            if (!array_filter($host['prompts'], static fn(array $p): bool => $p['enabled'])) {
                throw new InvalidArgumentException('« ' . $name . ' » doit avoir au moins un prompt actif.');
            }
        }

        return $host;
    }

    /** Settings sent to the browser: API keys are never sent back, only whether they are set. */
    public static function forDisplay(array $settings): array
    {
        $settings = self::withDefaults($settings);
        $key = $settings['deepseek']['api_key'];
        $settings['deepseek']['api_key'] = $key !== '' ? self::MASK : '';
        $settings['deepseek']['api_key_hint'] = $key !== '' ? '…' . mb_substr($key, -4) : '';
        foreach ($settings['elevenlabs']['accounts'] as &$account) {
            $key = (string)$account['api_key'];
            $account['api_key'] = $key !== '' ? self::MASK : '';
            $account['api_key_hint'] = $key !== '' ? '…' . mb_substr($key, -4) : '';
        }
        unset($account);

        return $settings;
    }

    public static function findHost(array $settings, string $hostId): ?array
    {
        foreach ($settings['hosts'] ?? [] as $host) {
            if (($host['id'] ?? '') === $hostId) {
                return $host;
            }
        }

        return null;
    }

    public static function isReady(array $settings): bool
    {
        return !empty($settings['enabled'])
            && ($settings['deepseek']['api_key'] ?? '') !== ''
            && ($settings['elevenlabs']['accounts'] ?? []) !== [];
    }

    /** ElevenLabs account of a host (its own, else the first one), or null. */
    public static function account(array $settings, array $host): ?array
    {
        $accounts = $settings['elevenlabs']['accounts'] ?? [];
        foreach ($accounts as $account) {
            if ($account['id'] === ($host['account_id'] ?? '')) {
                return $account;
            }
        }

        return $accounts[0] ?? null;
    }

    public static function findAccount(array $settings, string $accountId): ?array
    {
        foreach ($settings['elevenlabs']['accounts'] ?? [] as $account) {
            if ($account['id'] === $accountId) {
                return $account;
            }
        }

        return null;
    }

    /** ElevenLabs request settings for a host: model, language and the key of its account. */
    public static function voiceConfig(array $settings, array $host): array
    {
        $account = self::account($settings, $host);

        return [
            'api_key' => (string)($account['api_key'] ?? ''),
            'model_id' => $settings['elevenlabs']['model_id'],
            'language_code' => $settings['elevenlabs']['language_code'],
        ];
    }

    // --- helpers --------------------------------------------------------------------------------

    private static function secret(array $in, string $current): string
    {
        if (self::bool($in['api_key_clear'] ?? false)) {
            return '';
        }
        $value = trim((string)($in['api_key'] ?? ''));
        if ($value === '' || $value === self::MASK) {
            return $current;
        }
        if (strlen($value) > 300 || preg_match('/\s/', $value)) {
            throw new InvalidArgumentException('Clé API invalide.');
        }

        return $value;
    }

    public static function uid(mixed $value): string
    {
        $value = (string)$value;

        return preg_match('/^[a-z0-9]{6,32}$/', $value) ? $value : bin2hex(random_bytes(6));
    }

    private static function time(mixed $value, string $label): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) || (int)$m[1] > 24 || (int)$m[2] > 59
            || ((int)$m[1] === 24 && (int)$m[2] !== 0)) {
            throw new InvalidArgumentException($label . ' invalide (format HH:MM).');
        }

        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        $value = $multiline
            ? trim(str_replace("\r\n", "\n", $value))
            : trim((string)preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $max);
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    private static function int(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int)$value : $min));
    }

    private static function float(mixed $value, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($value) ? (float)$value : $min)), 2);
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    private static function mergeDeep(array $defaults, array $stored): array
    {
        foreach ($stored as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key])) {
                $defaults[$key] = self::mergeDeep($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}
