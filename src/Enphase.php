<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal client for the Enphase Enlighten API v4 (developer "Watt" plan: OAuth 2.0 with the
 * system owner's approval). App credentials come from .env (ENPHASE_API_KEY, ENPHASE_CLIENT_ID,
 * ENPHASE_CLIENT_SECRET). The OAuth tokens rotate (access token 1 day, refresh token 1 month), so
 * they are kept in a private file next to the database, never in .env or git. Nothing here shows a
 * token or secret on a page.
 */
final class Enphase
{
	public const AUTH_URL = 'https://api.enphaseenergy.com/oauth/authorize';
	public const TOKEN_URL = 'https://api.enphaseenergy.com/oauth/token';
	public const API = 'https://api.enphaseenergy.com/api/v4';
	/** Enphase's own landing page for the code, used when our callback cannot be reached. */
	public const DEFAULT_REDIRECT = 'https://api.enphaseenergy.com/oauth/redirect_uri';

	public static function configured(): bool
	{
		foreach (['ENPHASE_API_KEY', 'ENPHASE_CLIENT_ID', 'ENPHASE_CLIENT_SECRET'] as $key) {
			if (trim((string) Config::get($key)) === '') {
				return false;
			}
		}
		return true;
	}

	public static function authorizeUrl(string $redirectUri, string $state): string
	{
		return self::AUTH_URL . '?' . http_build_query([
			'response_type' => 'code',
			'client_id' => trim((string) Config::get('ENPHASE_CLIENT_ID')),
			'redirect_uri' => $redirectUri,
			'state' => $state,
		]);
	}

	/** Trade an authorization code for tokens and save them. The result never includes the tokens. */
	public static function exchange(string $code, string $redirectUri): array
	{
		return self::tokenRequest(['grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri, 'code' => $code]);
	}

	/** Use the saved refresh token for a new access token (and a new refresh token). */
	public static function refresh(): array
	{
		$tokens = self::tokens();
		if (!$tokens || empty($tokens['refresh_token'])) {
			return self::fail('Not connected to Enphase yet.');
		}
		return self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
	}

	/**
	 * One GET against the v4 API with the app key and a valid access token (refreshed first when it is
	 * within 5 minutes of expiring). The URL in the result leaves out the key.
	 */
	public static function get(string $path, array $query = []): array
	{
		$display = self::API . $path . ($query ? '?' . http_build_query($query) : '');
		$tokens = self::tokens();
		if (!$tokens) {
			return ['url' => $display] + self::fail('Not connected to Enphase yet.');
		}
		if (($tokens['expires_at'] ?? 0) < time() + 300) {
			$r = self::refresh();
			if ($r['status'] !== 200) {
				return ['url' => $display] + $r;
			}
			$tokens = self::tokens();
		}
		$url = self::API . $path . '?' . http_build_query($query + ['key' => trim((string) Config::get('ENPHASE_API_KEY'))]);
		return ['url' => $display] + Http::request('GET', $url, ['Authorization: Bearer ' . $tokens['access_token']]);
	}

	/** Saved token details without the tokens: when they were issued and when they expire. */
	public static function status(): ?array
	{
		$t = self::tokens();
		if (!$t) {
			return null;
		}
		return [
			'expires_at' => (int) ($t['expires_at'] ?? 0),
			'refreshed_at' => (int) ($t['refreshed_at'] ?? 0),
			'connected_at' => (int) ($t['connected_at'] ?? 0),
			'scope' => (string) ($t['scope'] ?? ''),
		];
	}

	public static function disconnect(): void
	{
		@unlink(self::file());
	}

	private static function tokenRequest(array $params): array
	{
		$basic = base64_encode(trim((string) Config::get('ENPHASE_CLIENT_ID')) . ':' . trim((string) Config::get('ENPHASE_CLIENT_SECRET')));
		// Parameters in the query string, as in Enphase's own samples for developer apps.
		$r = Http::request('POST', self::TOKEN_URL . '?' . http_build_query($params), ['Authorization: Basic ' . $basic]);
		$json = is_array($r['json']) ? $r['json'] : [];
		if ($r['status'] === 200 && !empty($json['access_token']) && !empty($json['refresh_token'])) {
			$old = self::tokens() ?? [];
			self::save([
				'access_token' => $json['access_token'],
				'refresh_token' => $json['refresh_token'],
				'expires_at' => time() + (int) ($json['expires_in'] ?? 86400),
				'refreshed_at' => time(),
				'connected_at' => $params['grant_type'] === 'authorization_code' ? time() : (int) ($old['connected_at'] ?? time()),
				'scope' => (string) ($json['scope'] ?? ''),
			]);
		}
		// Never pass tokens on to a page: keep only the non-secret fields of the reply.
		$safe = array_diff_key($json, ['access_token' => 1, 'refresh_token' => 1, 'jti' => 1]);
		$body = $safe ? (json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') : '';
		return ['url' => self::TOKEN_URL, 'json' => $safe, 'body' => $body] + $r;
	}

	private static function fail(string $message): array
	{
		return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'ms' => 0, 'error' => $message];
	}

	private static function file(): string
	{
		return dirname(Db::path()) . '/enphase-tokens.json';
	}

	private static function tokens(): ?array
	{
		$file = self::file();
		$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
		return is_array($data) && !empty($data['access_token']) ? $data : null;
	}

	private static function save(array $tokens): void
	{
		$file = self::file();
		file_put_contents($file, json_encode($tokens), LOCK_EX);
		@chmod($file, 0600);
	}
}
