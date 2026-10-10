<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal client for the SolarEdge Monitoring API V2 (monitoringapi.solaredge.com/v2).
 * The Fleet Access key comes from .env (SOLAREDGE_API_KEY) and is sent only in the X-API-Key header,
 * never in a URL, so it does not end up in logs or on any page. Every call costs 1 credit.
 */
final class SolarEdge
{
	public const BASE = 'https://monitoringapi.solaredge.com/v2';

	public static function configured(): bool
	{
		return trim((string) Config::get('SOLAREDGE_API_KEY')) !== '';
	}

	/**
	 * One GET request; see Http::request for the result, plus the URL called (which holds no key).
	 *
	 * @return array{url:string,status:int,headers:array<int,string>,body:string,json:mixed,ms:int,error:?string}
	 */
	public static function get(string $path, array $query = []): array
	{
		$url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
		$key = trim((string) Config::get('SOLAREDGE_API_KEY'));
		return ['url' => $url] + Http::request('GET', $url, ['X-API-Key: ' . $key]);
	}
}
