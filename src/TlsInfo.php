<?php
declare(strict_types=1);

namespace App;

/**
 * The HTTPS certificate visitors see. TLS ends at the reverse proxy in front of the container, so
 * the certificate isn't visible from here; instead this opens its own TLS connection to the site's
 * address and reads what comes back. It tries the Docker host first (the proxy usually listens on
 * every interface, and this avoids relying on the router looping back to the public address), then
 * the public host name. Short timeouts keep the About page quick if neither answers.
 */
final class TlsInfo
{
	private const TIMEOUT = 2.0;

	public static function probe(string $host, int $port): ?array
	{
		$targets = array_unique(array_filter([self::dockerGateway(), $host]));
		foreach ($targets as $addr) {
			$ctx = stream_context_create(['ssl' => [
				'capture_peer_cert' => true, 'capture_peer_cert_chain' => true,
				'verify_peer' => false, 'verify_peer_name' => false, // checked separately below
				'SNI_enabled' => true, 'peer_name' => $host,
			]]);
			$s = @stream_socket_client('ssl://' . $addr . ':' . $port, $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
			if (!$s) {
				continue;
			}
			$crypto = stream_get_meta_data($s)['crypto'] ?? [];
			$ssl = stream_context_get_params($s)['options']['ssl'] ?? [];
			fclose($s);
			if (empty($ssl['peer_certificate'])) {
				continue;
			}
			return self::describe($ssl['peer_certificate'], $ssl['peer_certificate_chain'] ?? [], $host, $crypto)
				+ ['checked_via' => $addr === $host ? $host : 'Docker host ' . $addr, 'port' => $port];
		}
		return null;
	}

	private static function describe($cert, array $chain, string $host, array $crypto): array
	{
		$x = openssl_x509_parse($cert) ?: [];
		$names = [];
		foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $san) {
			if (str_starts_with($san = trim($san), 'DNS:')) {
				$names[] = substr($san, 4);
			}
		}
		if (!$names && !empty($x['subject']['CN'])) {
			$names[] = $x['subject']['CN'];
		}

		$key = null;
		if (($pub = openssl_pkey_get_public($cert)) && ($d = openssl_pkey_get_details($pub))) {
			$key = match ($d['type']) {
				OPENSSL_KEYTYPE_EC  => 'ECDSA ' . ($d['ec']['curve_name'] ?? '') . ' (' . $d['bits'] . '-bit)',
				OPENSSL_KEYTYPE_RSA => 'RSA ' . $d['bits'] . '-bit',
				default             => $d['bits'] . '-bit',
			};
		}

		$issuer = $x['issuer'] ?? [];
		return [
			'names'      => $names,
			'name_match' => self::nameMatches($host, $names),
			'issuer'     => trim(($issuer['O'] ?? '') . ' ' . (($issuer['CN'] ?? '') !== ($issuer['O'] ?? '') ? ($issuer['CN'] ?? '') : '')),
			'valid_from' => $x['validFrom_time_t'] ?? null,
			'valid_to'   => $x['validTo_time_t'] ?? null,
			'self_signed' => ($x['subject'] ?? null) == ($x['issuer'] ?? null),
			'trusted'    => self::trusted($cert, $chain),
			'key'        => $key,
			'signature'  => $x['signatureTypeLN'] ?? null,
			'protocol'   => $crypto['protocol'] ?? null,
			'cipher'     => $crypto['cipher_name'] ?? null,
		];
	}

	/** Does the chain lead to a CA the container trusts (the same check a browser makes)? */
	private static function trusted($cert, array $chain): ?bool
	{
		$pem = '';
		foreach (array_slice($chain, 1) as $c) { // [0] is the site certificate itself
			openssl_x509_export($c, $out);
			$pem .= $out;
		}
		$file = null;
		if ($pem !== '') {
			$file = tempnam(sys_get_temp_dir(), 'chain');
			file_put_contents($file, $pem);
		}
		$cafile = openssl_get_cert_locations()['default_cert_file'] ?? '';
		$ok = @openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER, is_file($cafile) ? [$cafile] : [], $file);
		if ($file) {
			@unlink($file);
		}
		return is_bool($ok) ? $ok : null;
	}

	private static function nameMatches(string $host, array $names): bool
	{
		$host = strtolower($host);
		foreach ($names as $n) {
			$n = strtolower($n);
			if ($n === $host || (str_starts_with($n, '*.') && substr($host, strpos($host, '.') ?: 0) === substr($n, 1))) {
				return true;
			}
		}
		return false;
	}

	/** The container's default gateway (the Docker host), from the routing table. */
	private static function dockerGateway(): ?string
	{
		if (!is_file('/.dockerenv')) {
			return null;
		}
		foreach (array_slice(file('/proc/net/route') ?: [], 1) as $line) {
			$f = preg_split('/\s+/', trim($line));
			if (($f[1] ?? '') === '00000000' && isset($f[2]) && ctype_xdigit($f[2])) {
				return implode('.', array_reverse(array_map('hexdec', str_split($f[2], 2))));
			}
		}
		return null;
	}
}
