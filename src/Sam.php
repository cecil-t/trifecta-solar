<?php
declare(strict_types=1);

namespace App;

/**
 * SAM (System Advisor Model) through PySAM. The Docker image installs PySAM in /opt/sam with SAM's
 * CEC libraries in /opt/sam/lib (see the Dockerfile). Each call starts bin/sam.py with that Python,
 * sends one JSON request and reads one JSON reply, so SAM uses memory only while it runs.
 *
 * SAM_PYTHON and SAM_LIB in .env override the paths (for development outside Docker).
 */
final class Sam
{
	public const TIMEOUT = 60;

	public static function python(): string
	{
		return Config::get('SAM_PYTHON', '/opt/sam/bin/python');
	}

	public static function libFolder(): string
	{
		return Config::get('SAM_LIB', '/opt/sam/lib');
	}

	public static function installed(): bool
	{
		return is_executable(self::python());
	}

	/**
	 * Run one request. Always returns an array with ok (bool), and error (string) when not ok,
	 * plus ms (wall time seen from PHP).
	 */
	public static function call(array $request, int $timeout = self::TIMEOUT): array
	{
		$start = microtime(true);
		$done = static fn (array $r): array => $r + ['ms' => (int) round((microtime(true) - $start) * 1000)];
		if (!self::installed()) {
			return $done(['ok' => false, 'error' => 'SAM is not installed on this server (no ' . self::python() . '). Rebuild the Docker image.']);
		}
		$env = ['SAM_LIB' => self::libFolder(), 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8'];
		// -I: isolated mode, so nothing in the working folder or environment can change what Python loads
		$proc = proc_open([self::python(), '-I', dirname(__DIR__) . '/bin/sam.py'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env);
		if (!is_resource($proc)) {
			return $done(['ok' => false, 'error' => 'Could not start SAM.']);
		}
		fwrite($pipes[0], (string) json_encode($request));
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$out = '';
		$err = '';
		$deadline = $start + $timeout;
		while (true) {
			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			$left = $deadline - microtime(true);
			if ($left <= 0) {
				proc_terminate($proc, 9);
				proc_close($proc);
				return $done(['ok' => false, 'error' => 'SAM did not finish within ' . $timeout . ' seconds.']);
			}
			if (stream_select($read, $write, $except, (int) $left, 200000) === false) {
				break;
			}
			foreach ($read as $p) {
				$chunk = (string) fread($p, 65536);
				if ($p === $pipes[1]) {
					$out .= $chunk;
				} else {
					$err .= $chunk;
				}
			}
			if (feof($pipes[1]) && feof($pipes[2])) {
				break;
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		$reply = json_decode($out, true);
		if (!is_array($reply)) {
			$why = trim($err) !== '' ? trim(substr($err, -1000)) : 'no reply';
			return $done(['ok' => false, 'error' => 'SAM failed (exit ' . $code . '): ' . $why]);
		}
		$reply['ok'] = !empty($reply['ok']);
		if (!$reply['ok'] && empty($reply['error'])) {
			$reply['error'] = 'SAM reported a failure.';
		}
		return $done($reply);
	}
}
