<?php
declare(strict_types=1);

namespace App;

/** Small HTTP client for vendor APIs (stream wrapper, no curl extension needed). */
final class Http
{
	/**
	 * One request. Returns the HTTP status (0 when nothing answered), response headers, raw body,
	 * decoded JSON (or null), elapsed milliseconds and an error message when the request failed.
	 *
	 * @param array<int, string> $headers "Name: value" lines
	 * @return array{status:int,headers:array<int,string>,body:string,json:mixed,ms:int,error:?string}
	 */
	public static function request(string $method, string $url, array $headers = [], string $body = '', int $timeout = 20): array
	{
		$headers[] = 'Accept: application/json';
		$headers[] = 'User-Agent: TrifectaTracker/1.0';
		if ($method !== 'GET') {
			$headers[] = 'Content-Length: ' . strlen($body);
		}
		$ctx = stream_context_create(['http' => [
			'method' => $method,
			'timeout' => $timeout,
			'ignore_errors' => true, // keep the body of 4xx/5xx responses
			'header' => implode("\r\n", $headers) . "\r\n",
			'content' => $body,
		]]);
		$start = microtime(true);
		$raw = @file_get_contents($url, false, $ctx);
		$ms = (int) round((microtime(true) - $start) * 1000);
		$respHeaders = $http_response_header ?? [];
		$status = 0;
		if ($respHeaders && preg_match('#^HTTP/\S+\s+(\d{3})#', $respHeaders[0], $m)) {
			$status = (int) $m[1];
		}
		$error = null;
		if ($raw === false) {
			$raw = '';
			$msg = html_entity_decode((string) (error_get_last()['message'] ?? 'Request failed'));
			$error = preg_replace('/^file_get_contents\(.*?\):\s*/s', '', $msg) ?? $msg; // drop the echoed URL
		}
		return [
			'status' => $status,
			'headers' => $respHeaders,
			'body' => $raw,
			'json' => $raw !== '' ? json_decode($raw, true) : null,
			'ms' => $ms,
			'error' => $error,
		];
	}
}
