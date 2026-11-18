<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use RuntimeException;

/**
 * Mistral Voxtral text-to-speech (POST /v1/audio/speech).
 * The answer is JSON with the audio in base64 (audio_data).
 */
final class Voxtral
{
    public const string BASE_URL = 'https://api.mistral.ai/v1';

    /**
     * Writes the speech as mp3 to $outPath.
     *
     * @param string|null $refAudio reference recording in base64 (voice cloned for this request)
     *
     * @return int characters billed
     */
    public function speak(string $apiKey, string $model, array $voice, ?string $refAudio, string $text, string $outPath): int
    {
        $payload = VoxtralVoice::payload($model, $voice, $text);
        if (($voice['mode'] ?? 'id') === 'sample') {
            if ($refAudio === null || $refAudio === '') {
                throw new RuntimeException('Voxtral : enregistrement de référence manquant.');
            }
            $payload['ref_audio'] = $refAudio;
        }
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = Http::request('POST', self::BASE_URL . '/audio/speech', $headers, $body, 180);

        // An expressiveness refused by the model: retry without it.
        if (in_array($response['status'], [400, 422], true) && isset($payload['expressiveness'])
            && stripos($response['body'], 'expressiveness') !== false) {
            unset($payload['expressiveness']);
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $response = Http::request('POST', self::BASE_URL . '/audio/speech', $headers, $body, 180);
        }

        if ($response['status'] !== 200) {
            throw new RuntimeException('Voxtral : ' . Http::errorMessage($response['body'], $response['status']));
        }
        $audio = self::audio($response['body'], (string)($response['headers']['content-type'] ?? ''));
        if (file_put_contents($outPath, $audio) === false) {
            throw new RuntimeException('Écriture impossible : ' . $outPath);
        }

        return mb_strlen($text);
    }

    /** Audio bytes of an answer (JSON with base64, or raw audio). Pure function, tested. */
    public static function audio(string $body, string $contentType = ''): string
    {
        if (!str_contains($contentType, 'json') && !str_starts_with(ltrim($body), '{')) {
            $audio = $body;
        } else {
            $data = json_decode($body, true);
            $encoded = is_array($data) ? ($data['audio_data'] ?? $data['data']['audio_data'] ?? null) : null;
            if (!is_string($encoded) || $encoded === '') {
                throw new RuntimeException('Voxtral a renvoyé une réponse sans audio.');
            }
            $audio = base64_decode($encoded, true);
            if ($audio === false) {
                throw new RuntimeException('Voxtral a renvoyé un audio illisible.');
            }
        }
        if (strlen($audio) < 500) {
            throw new RuntimeException('Voxtral a renvoyé un fichier audio vide.');
        }

        return $audio;
    }

    /**
     * Voices available to the key: Mistral's presets and the voices saved in the workspace.
     * Also used to check the key.
     *
     * @return array<int, array{id: string, name: string, type: string, languages: string[]}>
     */
    public function voices(string $apiKey): array
    {
        $headers = ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'];
        $response = Http::request('GET', self::BASE_URL . '/audio/voices?page_size=100', $headers, null, 20);
        if (in_array($response['status'], [404, 405], true)) {
            // No voice list for this account: the key is checked on the list of models instead.
            $models = Http::request('GET', self::BASE_URL . '/models', $headers, null, 20);
            if ($models['status'] !== 200) {
                throw new RuntimeException('Voxtral : ' . Http::errorMessage($models['body'], $models['status']));
            }

            return [];
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('Voxtral : ' . Http::errorMessage($response['body'], $response['status']));
        }
        $voices = self::parseVoices(json_decode($response['body'], true));

        // The saved voices may be on a later page: asked for separately (ignored if refused).
        $custom = Http::request('GET', self::BASE_URL . '/audio/voices?page_size=100&type=custom', $headers, null, 20);
        if ($custom['status'] === 200) {
            $known = array_column($voices, 'id');
            foreach (self::parseVoices(json_decode($custom['body'], true)) as $voice) {
                if (!in_array($voice['id'], $known, true)) {
                    $voices[] = $voice;
                }
            }
        }

        return $voices;
    }

    /** Pure function, tested. */
    public static function parseVoices(mixed $data): array
    {
        if (!is_array($data)) {
            throw new RuntimeException('réponse inattendue de Mistral');
        }
        $items = $data['data'] ?? $data['items'] ?? $data['voices'] ?? (array_is_list($data) ? $data : []);
        $voices = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['id'] ?? $item['slug'] ?? '');
            if ($id === '') {
                continue;
            }
            $languages = array_values(array_map(
                static fn(mixed $l): string => strtolower((string)(is_array($l) ? ($l['code'] ?? '') : $l)),
                is_array($item['languages'] ?? null) ? $item['languages'] : []
            ));
            $voices[] = [
                'id' => $id,
                'name' => (string)($item['name'] ?? $item['slug'] ?? $id),
                'type' => (string)($item['type'] ?? ''),
                'languages' => $languages,
            ];
        }

        return $voices;
    }
}
