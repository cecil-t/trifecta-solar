<?php
declare(strict_types=1);

namespace App;

/**
 * Two-day forecast for the dashboard from Open-Meteo (free, no API key).
 * Cached in data/weather.json for 30 minutes; failures just hide the widget.
 */
final class Weather
{
    public const PLACE = 'Manheim, PA';
    private const LAT = 40.1634;
    private const LON = -76.3950;
    private const TTL = 1800;

    /** @return array<int, array{date:string,label:string,code:int,hi:int,lo:int,pop:?int,desc:string,icon:string}>|null */
    public static function forecast(): ?array
    {
        $cacheFile = dirname(Db::path()) . '/weather.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        $fresh = $cached && ($cached['fetched'] ?? 0) > time() - self::TTL && ($cached['days'][0]['date'] ?? '') === date('Y-m-d');
        if (!$fresh) {
            $days = self::fetch();
            if ($days) {
                @file_put_contents($cacheFile, json_encode(['fetched' => time(), 'days' => $days]));
                $cached = ['days' => $days];
            }
        }
        return $cached['days'] ?? null;
    }

    private static function fetch(): ?array
    {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => self::LAT, 'longitude' => self::LON,
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'temperature_unit' => 'fahrenheit', 'timezone' => 'America/New_York', 'forecast_days' => 2,
        ]);
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: TrifectaTracker/1.0\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw === false ? null : self::parse($raw);
    }

    /** Parse an Open-Meteo daily response. Public so it can be tested with a fixture. */
    public static function parse(string $raw): ?array
    {
        $d = json_decode($raw, true)['daily'] ?? null;
        if (!is_array($d) || empty($d['time'])) {
            return null;
        }
        $out = [];
        foreach ($d['time'] as $i => $date) {
            $code = (int) ($d['weather_code'][$i] ?? 0);
            [$desc, $icon] = self::describe($code);
            $out[] = [
                'date' => $date,
                'label' => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : date('D', strtotime($date))),
                'code' => $code,
                'hi' => (int) round((float) ($d['temperature_2m_max'][$i] ?? 0)),
                'lo' => (int) round((float) ($d['temperature_2m_min'][$i] ?? 0)),
                'pop' => isset($d['precipitation_probability_max'][$i]) ? (int) $d['precipitation_probability_max'][$i] : null,
                'desc' => $desc,
                'icon' => $icon,
            ];
        }
        return $out;
    }

    /** WMO weather code -> [description, icon key] */
    public static function describe(int $code): array
    {
        return match (true) {
            $code === 0 => ['Sunny', 'sun'],
            $code === 1 => ['Mostly sunny', 'sun'],
            $code === 2 => ['Partly cloudy', 'partly'],
            $code === 3 => ['Cloudy', 'cloud'],
            in_array($code, [45, 48], true) => ['Fog', 'fog'],
            $code >= 51 && $code <= 57 => ['Drizzle', 'rain'],
            $code >= 61 && $code <= 67 => ['Rain', 'rain'],
            $code >= 71 && $code <= 77 => ['Snow', 'snow'],
            $code >= 80 && $code <= 82 => ['Showers', 'rain'],
            $code >= 85 && $code <= 86 => ['Snow showers', 'snow'],
            $code >= 95 => ['Thunderstorms', 'storm'],
            default => ['', 'cloud'],
        };
    }

    /** Inline SVG icons in the brand palette. */
    public static function icon(string $key): string
    {
        $sun = '<circle cx="24" cy="24" r="8" fill="#F9A11B"/><g stroke="#F9A11B" stroke-width="3" stroke-linecap="round"><path d="M24 6v5M24 37v5M6 24h5M37 24h5M11.3 11.3l3.5 3.5M33.2 33.2l3.5 3.5M11.3 36.7l3.5-3.5M33.2 14.8l3.5-3.5"/></g>';
        $cloud = '<path d="M15 38h20a8 8 0 0 0 0-16 11 11 0 0 0-21-2A9 9 0 0 0 15 38z" fill="#c9d3da" stroke="#8a9aa5" stroke-width="2"/>';
        $smallSun = '<circle cx="17" cy="17" r="7" fill="#F9A11B"/><g stroke="#F9A11B" stroke-width="2.5" stroke-linecap="round"><path d="M17 4v3M4 17h3M7.8 7.8l2.1 2.1M26.2 7.8l-2.1 2.1"/></g>';
        $cloudLow = '<path d="M16 34h19a7 7 0 0 0 0-14 10 10 0 0 0-19-2 8 8 0 0 0 0 16z" fill="#c9d3da" stroke="#8a9aa5" stroke-width="2"/>';
        $body = match ($key) {
            'sun' => $sun,
            'partly' => $smallSun . '<path d="M18 40h19a8 8 0 0 0 0-16 11 11 0 0 0-20-1 8.5 8.5 0 0 0 1 17z" fill="#dfe6eb" stroke="#8a9aa5" stroke-width="2"/>',
            'rain' => $cloudLow . '<g stroke="#025586" stroke-width="2.5" stroke-linecap="round"><path d="M18 38l-2 5M26 38l-2 5M34 38l-2 5"/></g>',
            'snow' => $cloudLow . '<g fill="#025586"><circle cx="18" cy="41" r="2"/><circle cx="26" cy="43" r="2"/><circle cx="34" cy="41" r="2"/></g>',
            'storm' => $cloudLow . '<path d="M26 35l-5 8h5l-3 7 9-10h-5l3-5z" fill="#F9A11B"/>',
            'fog' => '<g stroke="#8a9aa5" stroke-width="3" stroke-linecap="round"><path d="M8 18h32M12 25h28M8 32h30M14 39h22"/></g>',
            default => $cloud,
        };
        return '<svg viewBox="0 0 48 48" width="44" height="44" aria-hidden="true">' . $body . '</svg>';
    }
}
