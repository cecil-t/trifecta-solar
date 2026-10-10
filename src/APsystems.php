<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal client for the APsystems OpenAPI, installer endpoints (api.apsystemsema.com:9282,
 * /installer/api/v2). Credentials come from .env (APSYSTEMS_APP_ID, APSYSTEMS_APP_SECRET).
 * Every request is signed: HMAC-SHA256 with the App Secret over
 * "timestamp/nonce/appId/requestPath/METHOD/HmacSHA256", Base64-encoded.
 *
 * The manual leaves two details open; this uses the common reading: the timestamp is in
 * milliseconds and "requestPath" is the last segment of the URL path (the sid for per-system
 * calls, "systems" for the list). Replies carry their own "code": 0 is success, 2001 to 2004 are
 * credential or permission problems, 2005 means the monthly allowance is used up.
 */
final class APsystems
{
	public const BASE = 'https://api.apsystemsema.com:9282/installer/api/v2';

	public static function configured(): bool
	{
		return trim((string) Config::get('APSYSTEMS_APP_ID')) !== '' && trim((string) Config::get('APSYSTEMS_APP_SECRET')) !== '';
	}

	/** One signed request; see Http::request for the result, plus the URL called. */
	public static function request(string $method, string $path, array $query = [], ?array $json = null): array
	{
		$url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
		$appId = trim((string) Config::get('APSYSTEMS_APP_ID'));
		$secret = trim((string) Config::get('APSYSTEMS_APP_SECRET'));
		$timestamp = (string) (int) round(microtime(true) * 1000);
		$nonce = bin2hex(random_bytes(16));
		$segments = explode('/', trim($path, '/'));
		$requestPath = (string) end($segments);
		$toSign = implode('/', [$timestamp, $nonce, $appId, $requestPath, $method, 'HmacSHA256']);
		$signature = base64_encode(hash_hmac('sha256', $toSign, $secret, true));
		$headers = [
			'X-CA-AppId: ' . $appId,
			'X-CA-Timestamp: ' . $timestamp,
			'X-CA-Nonce: ' . $nonce,
			'X-CA-Signature-Method: HmacSHA256',
			'X-CA-Signature: ' . $signature,
		];
		$body = '';
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json';
			$body = (string) json_encode($json);
		}
		return ['url' => $url] + Http::request($method, $url, $headers, $body);
	}
}
