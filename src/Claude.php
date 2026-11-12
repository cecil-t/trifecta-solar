<?php
declare(strict_types=1);

namespace App;

/**
 * Claude (Anthropic's Messages API), called from the server only. The key is ANTHROPIC_API_KEY in
 * .env; the model is a setting on About > Website and AI. Every call is logged in ai_calls with its
 * tokens and cost (never the documents sent). Answers come back as JSON matching a schema
 * (structured outputs), so callers get fields, not prose.
 */
final class Claude
{
	public const URL = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION = '2026-10-21';
	public const DEFAULT_MODEL = 'claude-sonnet-5-5';

	/** Models offered, with list prices in dollars per million tokens (input, output). */
	public const MODELS = [
		'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 2.0, 10.0],
		'claude-haiku-5-5' => ['Claude Haiku 5.5', 0.10, 0.50],
		'claude-opus-5-5' => ['Claude Opus 5.5', 4.0, 20.0],
	];

	/** What each logged call was for. */
	public const PURPOSES = ['bill' => 'Electric bill', 'bill_test' => 'Electric bill (test)', 'check' => 'Connection check'];

	public static function configured(): bool
	{
		return (string) Config::get('ANTHROPIC_API_KEY', '') !== '';
	}

	public static function model(): string
	{
		$m = Presales::setting('ai_model');
		return isset(self::MODELS[$m]) ? $m : self::DEFAULT_MODEL;
	}

	public static function cost(string $model, int $in, int $out): float
	{
		[, $pin, $pout] = self::MODELS[$model] ?? self::MODELS[self::DEFAULT_MODEL];
		return ($in * $pin + $out * $pout) / 1_000_000;
	}

	/**
	 * One request: $content is the user message's content blocks (text, image, document). With a
	 * $schema the answer is JSON matching it. Returns ok, json (decoded answer) or error, plus
	 * usage, cost and ms. Logged in ai_calls either way.
	 *
	 * @param list<array> $content
	 * @return array{ok:bool, json?:mixed, text?:string, error?:string, input_tokens:int, output_tokens:int, cost:float, ms:int, model:string}
	 */
	public static function ask(string $purpose, string $system, array $content, ?array $schema = null, int $maxTokens = 2000, int $timeout = 120): array
	{
		$model = self::model();
		$base = ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'ms' => 0, 'model' => $model];
		if (!self::configured()) {
			return ['ok' => false, 'error' => 'The Claude API key is not set (ANTHROPIC_API_KEY in the server .env file).'] + $base;
		}
		$body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]];
		if ($schema !== null) {
			$body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
		}
		$r = Http::request('POST', self::URL, [
			'Authorization: Bearer ' . Config::get('ANTHROPIC_API_KEY'),
			'anthropic-version: ' . self::API_VERSION,
			'Content-Type: application/json',
		], (string) json_encode($body, JSON_UNESCAPED_SLASHES), $timeout);
		$j = is_array($r['json']) ? $r['json'] : [];
		$in = (int) ($j['usage']['input_tokens'] ?? 0) + (int) ($j['usage']['cache_read_input_tokens'] ?? 0) + (int) ($j['usage']['cache_creation_input_tokens'] ?? 0);
		$out = (int) ($j['usage']['output_tokens'] ?? 0);
		$res = ['input_tokens' => $in, 'output_tokens' => $out, 'cost' => self::cost($model, $in, $out), 'ms' => $r['ms'], 'model' => $model];
		$error = null;
		$text = '';
		if ($r['error'] !== null && $r['status'] === 0) {
			$error = 'Could not reach the Claude API: ' . $r['error'];
		} elseif ($r['status'] !== 200) {
			$msg = (string) ($j['error']['message'] ?? '');
			$error = match (true) {
				$r['status'] === 401 => 'The Claude API key was refused (401). Check ANTHROPIC_API_KEY in .env.',
				$r['status'] === 429 => 'The Claude API is busy or the spend limit was reached (429)' . ($msg !== '' ? ': ' . $msg : '.'),
				default => 'The Claude API answered ' . $r['status'] . ($msg !== '' ? ': ' . $msg : '.'),
			};
		} else {
			foreach ($j['content'] ?? [] as $block) {
				if (($block['type'] ?? '') === 'text') {
					$text .= (string) $block['text'];
				}
			}
			$stop = (string) ($j['stop_reason'] ?? '');
			if ($stop === 'max_tokens') {
				$error = 'The answer was cut off (too long).';
			} elseif ($stop === 'refusal') {
				$error = 'Claude declined to read this.';
			}
		}
		$json = null;
		if ($error === null && $schema !== null) {
			$json = json_decode($text, true);
			if (!is_array($json)) {
				$error = 'The answer was not in the expected form.';
			}
		}
		self::log($purpose, $res, $error);
		return $error === null ? ['ok' => true, 'json' => $json, 'text' => $text] + $res : ['ok' => false, 'error' => $error] + $res;
	}

	private static function log(string $purpose, array $res, ?string $error): void
	{
		try {
			Db::insert('ai_calls', [
				'purpose' => $purpose, 'model' => $res['model'], 'input_tokens' => $res['input_tokens'], 'output_tokens' => $res['output_tokens'],
				'cost_usd' => round($res['cost'], 6), 'ms' => $res['ms'], 'ok' => $error === null ? 1 : 0,
				'error' => $error === null ? null : mb_substr($error, 0, 500), 'user_id' => Auth::id(),
			]);
		} catch (\Throwable) {
			// Logging never stops the answer
		}
	}

	/**
	 * What one bill read costs on each model, in dollars: the size of the bills read so far (the
	 * average of the successful reads), priced at each model's list price. Until there are reads,
	 * a typical two-page bill is assumed.
	 *
	 * @return array<string, float> model id => dollars per bill
	 */
	public static function perBill(): array
	{
		$avg = Db::one("SELECT AVG(input_tokens) AS tin, AVG(output_tokens) AS tout, COUNT(*) AS n FROM ai_calls WHERE ok = 1 AND purpose IN ('bill', 'bill_test')");
		[$in, $out] = $avg && (int) $avg['n'] > 0 ? [(float) $avg['tin'], (float) $avg['tout']] : [9000.0, 600.0];
		$out2 = [];
		foreach (self::MODELS as $id => [, $pin, $pout]) {
			$out2[$id] = ($in * $pin + $out * $pout) / 1_000_000;
		}
		return $out2;
	}

	/** "about 2 cents", "about 0.1 cent", "about 12 cents" for a cost in dollars. */
	public static function cents(float $dollars): string
	{
		$c = $dollars * 100;
		if ($c < 1) {
			return 'about ' . rtrim(rtrim(number_format(max($c, 0.01), $c < 0.1 ? 2 : 1), '0'), '.') . ' cent';
		}
		$r = $c < 10 ? round($c, 1) : round($c);
		$t = rtrim(rtrim(number_format($r, 1), '0'), '.');
		return 'about ' . $t . ($t === '1' ? ' cent' : ' cents');
	}

	/** Calls and cost over the last N days, by purpose: purpose => [calls, failed, cost, input, output]. */
	public static function usage(int $days): array
	{
		$out = [];
		foreach (Db::all('SELECT purpose, COUNT(*) AS n, SUM(ok = 0) AS failed, SUM(cost_usd) AS cost, SUM(input_tokens) AS tin, SUM(output_tokens) AS tout
			FROM ai_calls WHERE created_at >= ? GROUP BY purpose ORDER BY purpose', [gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400)]) as $r) {
			$out[$r['purpose']] = ['calls' => (int) $r['n'], 'failed' => (int) $r['failed'], 'cost' => (float) $r['cost'], 'input' => (int) $r['tin'], 'output' => (int) $r['tout']];
		}
		return $out;
	}
}
