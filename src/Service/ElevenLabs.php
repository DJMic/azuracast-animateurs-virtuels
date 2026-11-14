<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use RuntimeException;

/**
 * ElevenLabs text-to-speech.
 */
final class ElevenLabs
{
    public const string BASE_URL = 'https://api.elevenlabs.io/v1';

    /**
     * Writes the speech as mp3 to $outPath.
     *
     * @return int characters billed (0 if unknown)
     */
    public function speak(array $config, string $voiceId, array $voice, string $text, string $outPath): int
    {
        $payload = self::payload($config, $voice, $text);
        $url = self::BASE_URL . '/text-to-speech/' . rawurlencode($voiceId) . '?output_format=mp3_44100_128';
        $response = Http::request(
            'POST',
            $url,
            [
                'xi-api-key' => $config['api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'audio/mpeg',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            120
        );

        // A language code refused by the model: retry without it.
        if ($response['status'] === 400 && isset($payload['language_code']) && stripos($response['body'], 'language') !== false) {
            unset($payload['language_code']);
            $response = Http::request(
                'POST',
                $url,
                ['xi-api-key' => $config['api_key'], 'Content-Type' => 'application/json', 'Accept' => 'audio/mpeg'],
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                120
            );
        }

        if ($response['status'] !== 200) {
            throw new RuntimeException('ElevenLabs : ' . Http::errorMessage($response['body'], $response['status']));
        }
        if (strlen($response['body']) < 500 || str_starts_with(ltrim($response['body']), '{')) {
            throw new RuntimeException('ElevenLabs a renvoyé une réponse qui n\'est pas un fichier audio.');
        }
        if (file_put_contents($outPath, $response['body']) === false) {
            throw new RuntimeException('Écriture impossible : ' . $outPath);
        }

        return (int)($response['headers']['x-character-count'] ?? 0);
    }

    /** Request body (pure function, tested). */
    public static function payload(array $config, array $voice, string $text): array
    {
        $model = (string)$config['model_id'];
        $stability = (float)$voice['stability'];
        if ($model === 'eleven_v3') {
            // Eleven v3 only accepts 0 (creative), 0.5 (natural) and 1 (robust).
            $stability = $stability < 0.25 ? 0.0 : ($stability < 0.75 ? 0.5 : 1.0);
        }

        $payload = [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => [
                'stability' => $stability,
                'similarity_boost' => (float)$voice['similarity_boost'],
                'style' => (float)$voice['style'],
                'use_speaker_boost' => (bool)$voice['speaker_boost'],
                'speed' => (float)$voice['speed'],
            ],
        ];
        $language = (string)($config['language_code'] ?? '');
        if ($language !== '' && $model !== 'eleven_multilingual_v2') {
            $payload['language_code'] = $language;
        }

        return $payload;
    }

    /**
     * Checks the key and the voice.
     *
     * @return array{name: string, category: string}
     */
    public function voice(string $apiKey, string $voiceId): array
    {
        $response = Http::request(
            'GET',
            self::BASE_URL . '/voices/' . rawurlencode($voiceId),
            ['xi-api-key' => $apiKey, 'Accept' => 'application/json'],
            null,
            20
        );
        if ($response['status'] !== 200) {
            throw new RuntimeException('ElevenLabs : ' . Http::errorMessage($response['body'], $response['status']));
        }
        $data = json_decode($response['body'], true);

        return [
            'name' => is_array($data) ? (string)($data['name'] ?? '') : '',
            'category' => is_array($data) ? (string)($data['category'] ?? '') : '',
        ];
    }

    /**
     * Remaining characters of the subscription (needs the "user_read" permission on the key).
     *
     * @return array{used: int, limit: int}|null
     */
    public function quota(string $apiKey): ?array
    {
        try {
            $response = Http::request(
                'GET',
                self::BASE_URL . '/user/subscription',
                ['xi-api-key' => $apiKey, 'Accept' => 'application/json'],
                null,
                20
            );
        } catch (RuntimeException) {
            return null;
        }
        if ($response['status'] !== 200) {
            return null;
        }
        $data = json_decode($response['body'], true);

        return is_array($data) && isset($data['character_limit'])
            ? ['used' => (int)($data['character_count'] ?? 0), 'limit' => (int)$data['character_limit']]
            : null;
    }
}
