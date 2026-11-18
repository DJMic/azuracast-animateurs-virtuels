<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * Weather from Open-Meteo (free, no key; non-commercial use): place search and forecast,
 * turned into a short French report that DeepSeek rewrites for the air.
 */
final class Weather
{
    public const string GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    public const string FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    /** WMO weather codes. */
    private const array CODES = [
        0 => 'ciel dégagé', 1 => 'plutôt ensoleillé', 2 => 'partiellement nuageux', 3 => 'couvert',
        45 => 'brouillard', 48 => 'brouillard givrant',
        51 => 'bruine légère', 53 => 'bruine', 55 => 'bruine dense', 56 => 'bruine verglaçante', 57 => 'bruine verglaçante dense',
        61 => 'pluie faible', 63 => 'pluie', 65 => 'forte pluie', 66 => 'pluie verglaçante', 67 => 'forte pluie verglaçante',
        71 => 'neige faible', 73 => 'neige', 75 => 'fortes chutes de neige', 77 => 'grains de neige',
        80 => 'averses faibles', 81 => 'averses', 82 => 'violentes averses',
        85 => 'averses de neige', 86 => 'fortes averses de neige',
        95 => 'orages', 96 => 'orages avec grêle', 99 => 'orages avec forte grêle',
    ];

    /**
     * Finds a place by name.
     *
     * @return array{latitude: float, longitude: float, label: string}
     */
    public function geocode(string $place): array
    {
        $url = self::GEOCODING_URL . '?' . http_build_query(['name' => $place, 'count' => 1, 'language' => 'fr', 'format' => 'json']);
        $response = Http::request('GET', $url, ['Accept' => 'application/json'], null, 20, true);
        $data = json_decode($response['body'], true);
        $result = is_array($data) ? ($data['results'][0] ?? null) : null;
        if ($response['status'] !== 200 || !is_array($result)) {
            throw new RuntimeException('Lieu introuvable pour la météo : « ' . $place . ' ».');
        }

        return [
            'latitude' => round((float)$result['latitude'], 4),
            'longitude' => round((float)$result['longitude'], 4),
            'label' => implode(', ', array_filter([
                (string)($result['name'] ?? ''),
                (string)($result['admin1'] ?? ''),
                (string)($result['country'] ?? ''),
            ])),
        ];
    }

    /** Text report for a weather source (place already located). */
    public function report(array $source): string
    {
        if (!is_numeric($source['latitude'] ?? null) || !is_numeric($source['longitude'] ?? null)) {
            $place = $this->geocode((string)$source['location']);
            $source['latitude'] = $place['latitude'];
            $source['longitude'] = $place['longitude'];
            $source['location_label'] = $place['label'];
        }
        $url = self::FORECAST_URL . '?' . http_build_query([
            'latitude' => $source['latitude'],
            'longitude' => $source['longitude'],
            'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,'
                . 'precipitation_sum,wind_speed_10m_max,sunrise,sunset',
            'timezone' => 'auto',
            'forecast_days' => 3,
        ]);
        $response = Http::request('GET', $url, ['Accept' => 'application/json'], null, 20, true);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data) || !isset($data['daily']['time'])) {
            throw new RuntimeException('Open-Meteo : ' . Http::errorMessage($response['body'], $response['status']));
        }

        return self::describe($data, (string)($source['location_label'] ?: $source['location']));
    }

    /** Pure function (tested). */
    public static function describe(array $data, string $place): string
    {
        $lines = ['Prévisions météo pour ' . $place . ' (source : Open-Meteo).'];
        $current = $data['current'] ?? null;
        if (is_array($current) && isset($current['temperature_2m'])) {
            $lines[] = 'En ce moment : ' . self::code($current['weather_code'] ?? null) . ', '
                . self::deg($current['temperature_2m']) . ' (ressenti ' . self::deg($current['apparent_temperature'] ?? $current['temperature_2m']) . ')'
                . (isset($current['wind_speed_10m']) ? ', vent ' . (int)round((float)$current['wind_speed_10m']) . ' km/h' : '') . '.';
        }
        $daily = $data['daily'];
        $names = ['Aujourd\'hui', 'Demain', 'Après-demain'];
        foreach ($daily['time'] as $i => $day) {
            if ($i > 2) {
                break;
            }
            $date = new DateTimeImmutable((string)$day);
            $parts = [
                self::code($daily['weather_code'][$i] ?? null),
                'minimum ' . self::deg($daily['temperature_2m_min'][$i] ?? null),
                'maximum ' . self::deg($daily['temperature_2m_max'][$i] ?? null),
            ];
            if (isset($daily['precipitation_probability_max'][$i])) {
                $parts[] = 'risque de précipitations ' . (int)$daily['precipitation_probability_max'][$i] . ' %';
            }
            if (!empty($daily['precipitation_sum'][$i])) {
                $parts[] = 'cumul ' . str_replace('.', ',', (string)round((float)$daily['precipitation_sum'][$i], 1)) . ' mm';
            }
            if (isset($daily['wind_speed_10m_max'][$i])) {
                $parts[] = 'vent jusqu\'à ' . (int)round((float)$daily['wind_speed_10m_max'][$i]) . ' km/h';
            }
            if ($i === 0 && isset($daily['sunrise'][0], $daily['sunset'][0])) {
                $parts[] = 'lever du soleil ' . substr((string)$daily['sunrise'][0], 11, 5)
                    . ', coucher ' . substr((string)$daily['sunset'][0], 11, 5);
            }
            $lines[] = $names[$i] . ' (' . $date->format('d/m') . ') : ' . implode(', ', $parts) . '.';
        }

        return implode("\n", $lines);
    }

    private static function code(mixed $code): string
    {
        return self::CODES[(int)$code] ?? 'temps variable';
    }

    private static function deg(mixed $value): string
    {
        return $value === null ? '?' : (int)round((float)$value) . ' °C';
    }
}
