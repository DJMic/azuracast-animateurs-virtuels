<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use InvalidArgumentException;

/**
 * Voice settings for Mistral Voxtral, used by hosts and by chronicles with their own voice.
 * Pure functions (tested by tests/run.php).
 *
 *   mode "id"     : a preset voice (e.g. voice_592) or a voice saved in the Mistral workspace
 *   mode "sample" : the voice is cloned at each request from a short extract of a media file
 *                   (nothing is stored at Mistral, so nothing can expire)
 */
final class VoxtralVoice
{
    public const string DEFAULT_MODEL = 'voxtral-mini-tts-3-0';

    /** Length of the extract sent as the reference recording (mode "sample"), in seconds. */
    public const int SAMPLE_MAX_SECONDS = 30;

    /** French preset voices of Voxtral 3 (Mistral documentation). */
    public const array FRENCH_PRESETS = [
        ['id' => 'voice_592', 'name' => 'Marie (féminine)'],
        ['id' => 'voice_3137', 'name' => 'Manon (féminine)'],
        ['id' => 'voice_589', 'name' => 'Léa (féminine)'],
        ['id' => 'voice_3141', 'name' => 'Camille (féminine)'],
    ];

    /** Emotions understood in [emotion=…] tags (Voxtral 3). */
    public const array EMOTIONS = [
        'amused', 'angry', 'anxious', 'apologetic', 'appreciative', 'assertive', 'bored', 'calm',
        'confident', 'confused', 'critical', 'curious', 'determined', 'disappointed', 'disgusted',
        'excited', 'frustrated', 'grateful', 'happy', 'hopeful', 'playful', 'proud', 'reflective',
        'resigned', 'sad', 'sarcastic', 'skeptical', 'somber', 'surprised', 'tense', 'tired',
        'urgent', 'warm', 'wistful',
    ];

    /** Paces understood in [pace=…] tags. */
    public const array PACES = ['very-slow', 'slow', 'moderate', 'fast', 'very-fast'];

    public static function defaults(): array
    {
        return [
            'mode' => 'id',
            'voice_id' => '',
            'sample_media' => '',
            'sample_start' => 0.0,
            'sample_seconds' => 15,
            // 0 = default of the service; otherwise 1.0 (stable) to 5.0 (very expressive).
            'expressiveness' => 0.0,
        ];
    }

    public static function validate(mixed $in, string $label): array
    {
        $in = is_array($in) ? $in : [];
        $voiceId = trim((string)($in['voice_id'] ?? ''));
        if ($voiceId !== '' && !preg_match('/^[A-Za-z0-9_-]{3,80}$/', $voiceId)) {
            throw new InvalidArgumentException($label . ' : identifiant de voix Voxtral invalide.');
        }
        $media = trim((string)($in['sample_media'] ?? ''));
        if ($media !== '' && (str_contains($media, '..') || str_starts_with($media, '/') || strlen($media) > 500)) {
            throw new InvalidArgumentException($label . ' : fichier de voix de référence invalide.');
        }
        $expressiveness = is_numeric($in['expressiveness'] ?? null) ? (float)$in['expressiveness'] : 0.0;
        $expressiveness = $expressiveness < 1.0 ? 0.0 : round(min(5.0, $expressiveness), 1);
        $start = is_numeric($in['sample_start'] ?? null) ? (float)$in['sample_start'] : 0.0;
        $seconds = is_numeric($in['sample_seconds'] ?? null) ? (int)$in['sample_seconds'] : 15;

        return [
            'mode' => ($in['mode'] ?? 'id') === 'sample' ? 'sample' : 'id',
            'voice_id' => $voiceId,
            'sample_media' => $media,
            'sample_start' => round(max(0.0, min(3600.0, $start)), 1),
            'sample_seconds' => max(3, min(self::SAMPLE_MAX_SECONDS, $seconds)),
            'expressiveness' => $expressiveness,
        ];
    }

    /** Whether the voice can be used for a request. */
    public static function isSet(array $voice): bool
    {
        return ($voice['mode'] ?? 'id') === 'sample'
            ? ($voice['sample_media'] ?? '') !== ''
            : ($voice['voice_id'] ?? '') !== '';
    }

    /** What the voice is, in a few words (history, errors). */
    public static function describe(array $voice): string
    {
        if (($voice['mode'] ?? 'id') === 'sample') {
            return 'voix clonée de « ' . basename((string)($voice['sample_media'] ?? '')) . ' »';
        }
        foreach (self::FRENCH_PRESETS as $preset) {
            if ($preset['id'] === ($voice['voice_id'] ?? '')) {
                return 'voix ' . $preset['name'];
            }
        }

        return 'voix ' . (string)($voice['voice_id'] ?? '');
    }

    /**
     * Request body, without the reference recording (added by the caller in mode "sample").
     * Pure function, tested.
     */
    public static function payload(string $model, array $voice, string $text): array
    {
        $payload = [
            'model' => $model !== '' ? $model : self::DEFAULT_MODEL,
            'input' => $text,
            'response_format' => 'mp3',
        ];
        if (($voice['mode'] ?? 'id') !== 'sample') {
            $payload['voice_id'] = (string)$voice['voice_id'];
        }
        if ((float)($voice['expressiveness'] ?? 0) >= 1.0) {
            $payload['expressiveness'] = (float)$voice['expressiveness'];
        }

        return $payload;
    }

    /**
     * Splits a long text at sentence ends into parts of at most $max characters. An emotion or pace
     * tag in force at the end of a part is repeated at the start of the next one (a tag applies until
     * the next tag or the end of the request). Pure function, tested.
     *
     * @return string[]
     */
    public static function split(string $text, int $max = 1800): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return [$text];
        }
        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [$text];
        $parts = [];
        $current = '';
        foreach ($sentences as $sentence) {
            while (mb_strlen($sentence) > $max) {
                // A sentence longer than a part: cut at a space.
                $cut = mb_strrpos(mb_substr($sentence, 0, $max), ' ') ?: $max;
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                $parts[] = trim(mb_substr($sentence, 0, $cut));
                $sentence = trim(mb_substr($sentence, $cut));
            }
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($sentence) > $max) {
                $parts[] = $current;
                $current = $sentence;
            } else {
                $current = trim($current . ' ' . $sentence);
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        // Carry the tags in force over to the following part.
        $emotion = null;
        $pace = null;
        foreach ($parts as $i => $part) {
            $prefix = '';
            if ($emotion !== null && !preg_match('/^\[emotion=/', $part)) {
                $prefix .= '[emotion=' . $emotion . ']';
            }
            if ($pace !== null && !preg_match('/^(\[emotion=[^\]]*\])?\[pace=/', $part)) {
                $prefix .= '[pace=' . $pace . ']';
            }
            if (preg_match_all('/\[emotion=([a-z-]+)\]/', $part, $m)) {
                $emotion = end($m[1]);
            }
            if (preg_match_all('/\[pace=([a-z-]+)\]/', $part, $m)) {
                $pace = end($m[1]);
            }
            $parts[$i] = $prefix . $part;
        }

        return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    /** Identifies the recorded voice, for the library of reusable clips. */
    public static function signature(string $model, array $voice): string
    {
        $voice = array_replace(self::defaults(), $voice);
        ksort($voice);

        return substr(sha1(json_encode(['voxtral', $model, $voice], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
