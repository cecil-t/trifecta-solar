<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal client for the SolarEdge Monitoring API V2 (monitoringapi.solaredge.com/v2).
 * The Fleet Access key comes from .env (SOLAREDGE_API_KEY) and is sent only in the X-API-Key header,
 * never in a URL, so it does not end up in logs or on any page.
 */
final class SolarEdge
{
	public const BASE = 'https://monitoringapi.solaredge.com/v2';

	public static function configured(): bool
	{
		return trim((string) Config::get('SOLAREDGE_API_KEY')) !== '';
	}

	/**
	 * One GET request. Returns the HTTP status, response headers, raw body, decoded JSON (or null),
	 * elapsed milliseconds and the URL called (which holds no key).
	 *
	 * @return array{url:string,status:int,headers:array<int,string>,body:string,json:mixed,ms:int,error:?string}
	 */
	public static function get(string $path, array $query = []): array
	{
		$url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
		$key = trim((string) Config::get('SOLAREDGE_API_KEY'));
		$ctx = stream_context_create(['http' => [
			'method' => 'GET',
			'timeout' => 20,
			'ignore_errors' => true, // keep the body of 4xx/5xx responses (problem details)
			'header' => "X-API-Key: " . $key . "\r\nAccept: application/json\r\nUser-Agent: TrifectaTracker/1.0\r\n",
		]]);
		$start = microtime(true);
		$body = @file_get_contents($url, false, $ctx);
		$ms = (int) round((microtime(true) - $start) * 1000);
		$headers = $http_response_header ?? [];
		$status = 0;
		if ($headers && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
			$status = (int) $m[1];
		}
		$error = null;
		if ($body === false) {
			$body = '';
			$msg = html_entity_decode((string) (error_get_last()['message'] ?? 'Request failed'));
			$error = preg_replace('/^file_get_contents\(.*?\):\s*/s', '', $msg) ?? $msg; // drop the echoed URL
		}
		return [
			'url' => $url,
			'status' => $status,
			'headers' => $headers,
			'body' => $body,
			'json' => $body !== '' ? json_decode($body, true) : null,
			'ms' => $ms,
			'error' => $error,
		];
	}
}
