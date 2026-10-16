<?php
declare(strict_types=1);

namespace App;

/** Run a command (no shell) with input on stdin, collecting stdout and stderr, with a time limit. */
final class Process
{
	/**
	 * @param list<string> $command program and arguments
	 * @param array<string,string> $env the whole environment for the command
	 * @return array{code:int, out:string, err:string, timed_out:bool}
	 */
	public static function run(array $command, string $input, int $timeout, array $env): array
	{
		$proc = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env);
		if (!is_resource($proc)) {
			return ['code' => -1, 'out' => '', 'err' => 'Could not start ' . basename($command[0]) . '.', 'timed_out' => false];
		}
		stream_set_blocking($pipes[0], false);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$out = '';
		$err = '';
		$deadline = microtime(true) + $timeout;
		$pending = $input;
		$stdinOpen = true;
		if ($pending === '') {
			fclose($pipes[0]);
			$stdinOpen = false;
		}
		while (true) {
			$left = $deadline - microtime(true);
			if ($left <= 0) {
				foreach ($stdinOpen ? [0, 1, 2] : [1, 2] as $i) {
					fclose($pipes[$i]);
				}
				proc_terminate($proc, 9);
				proc_close($proc);
				return ['code' => -1, 'out' => $out, 'err' => $err, 'timed_out' => true];
			}
			$read = [];
			foreach ([1, 2] as $i) {
				if (!feof($pipes[$i])) {
					$read[] = $pipes[$i];
				}
			}
			$write = $stdinOpen ? [$pipes[0]] : null;
			$except = null;
			if (!$read && !$stdinOpen) {
				break;
			}
			if (stream_select($read, $write, $except, (int) $left, 200000) === false) {
				break;
			}
			if ($stdinOpen && $write) {
				$n = fwrite($pipes[0], $pending);
				if ($n === false) {
					$pending = '';
				} else {
					$pending = (string) substr($pending, $n);
				}
				if ($pending === '') {
					fclose($pipes[0]);
					$stdinOpen = false;
				}
			}
			foreach ($read as $p) {
				$chunk = (string) fread($p, 65536);
				if ($p === $pipes[1]) {
					$out .= $chunk;
				} else {
					$err .= $chunk;
				}
			}
		}
		if ($stdinOpen) {
			fclose($pipes[0]);
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		return ['code' => proc_close($proc), 'out' => $out, 'err' => $err, 'timed_out' => false];
	}
}
