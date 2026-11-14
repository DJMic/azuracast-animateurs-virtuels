<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use RuntimeException;

/**
 * Minimal HTTPS client (curl), used for the DeepSeek and ElevenLabs APIs.
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 60
    ): array {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('curl indisponible.');
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'AzuraCast-AnimateursVirtuels/' . Plugin::version(),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        if ($result === false) {
            throw new RuntimeException('Connexion impossible à ' . parse_url($url, PHP_URL_HOST) . ' : ' . $error);
        }

        return ['status' => $status, 'body' => (string)$result, 'headers' => $responseHeaders];
    }

    /** Error message from a JSON API answer (several formats). */
    public static function errorMessage(string $body, int $status): string
    {
        $data = json_decode($body, true);
        $message = '';
        if (is_array($data)) {
            $detail = $data['detail'] ?? $data['error'] ?? null;
            if (is_array($detail)) {
                $message = (string)($detail['message'] ?? $detail['msg'] ?? ($detail[0]['msg'] ?? ''));
            } elseif (is_string($detail)) {
                $message = $detail;
            }
            $message = $message !== '' ? $message : (string)($data['message'] ?? '');
        }
        if ($message === '') {
            $message = trim(mb_substr(strip_tags($body), 0, 200));
        }

        $hint = match ($status) {
            401 => ' (clé API refusée)',
            402 => ' (crédit insuffisant)',
            403 => ' (accès refusé : vérifiez les permissions de la clé)',
            404 => ' (introuvable)',
            429 => ' (trop de requêtes ou quota atteint)',
            default => '',
        };

        return 'HTTP ' . $status . $hint . ($message !== '' ? ' : ' . $message : '');
    }
}
