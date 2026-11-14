<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use RuntimeException;

/**
 * DeepSeek chat completions (OpenAI-compatible API).
 */
final class DeepSeek
{
    public const string BASE_URL = 'https://api.deepseek.com';

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array{text: string, tokens: int}
     */
    public function complete(array $config, array $messages): array
    {
        $payload = [
            'model' => $config['model'],
            'messages' => $messages,
            'temperature' => (float)$config['temperature'],
            'max_tokens' => (int)$config['max_tokens'],
            'stream' => false,
            'thinking' => ['type' => !empty($config['thinking']) ? 'enabled' : 'disabled'],
        ];

        $response = $this->post($config['api_key'], $payload);
        // Models that do not know the "thinking" option: retry without it.
        if ($response['status'] === 400 && stripos($response['body'], 'thinking') !== false) {
            unset($payload['thinking']);
            $response = $this->post($config['api_key'], $payload);
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('DeepSeek : ' . Http::errorMessage($response['body'], $response['status']));
        }

        $data = json_decode($response['body'], true);
        $text = is_array($data) ? (string)($data['choices'][0]['message']['content'] ?? '') : '';
        if (trim($text) === '') {
            $reason = is_array($data) ? (string)($data['choices'][0]['finish_reason'] ?? '') : '';
            throw new RuntimeException(
                'DeepSeek n\'a renvoyé aucun texte' . ($reason === 'length' ? ' (limite de jetons trop basse)' : '') . '.'
            );
        }

        return ['text' => $text, 'tokens' => (int)($data['usage']['total_tokens'] ?? 0)];
    }

    /**
     * Checks the key: balance and available models.
     *
     * @return array{balance: string, models: string[]}
     */
    public function check(string $apiKey): array
    {
        $headers = ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'];
        $models = Http::request('GET', self::BASE_URL . '/models', $headers, null, 20);
        if ($models['status'] !== 200) {
            throw new RuntimeException('DeepSeek : ' . Http::errorMessage($models['body'], $models['status']));
        }
        $ids = array_values(array_filter(array_map(
            static fn(mixed $m): string => is_array($m) ? (string)($m['id'] ?? '') : '',
            json_decode($models['body'], true)['data'] ?? []
        )));

        $balance = '';
        try {
            $answer = Http::request('GET', self::BASE_URL . '/user/balance', $headers, null, 20);
            $data = json_decode($answer['body'], true);
            foreach (is_array($data) ? ($data['balance_infos'] ?? []) : [] as $info) {
                $balance .= ($balance !== '' ? ', ' : '') . ($info['total_balance'] ?? '?') . ' ' . ($info['currency'] ?? '');
            }
        } catch (RuntimeException) {
            // The balance is only informative.
        }

        return ['balance' => trim($balance), 'models' => $ids];
    }

    private function post(string $apiKey, array $payload): array
    {
        return Http::request(
            'POST',
            self::BASE_URL . '/chat/completions',
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            90
        );
    }
}
