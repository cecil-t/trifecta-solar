<?php
declare(strict_types=1);

namespace App;

/**
 * Re-indents rendered HTML with tabs by tag nesting, and drops the blank lines that
 * PHP-only template lines leave behind, so "view source" reads cleanly.
 * Leading/trailing whitespace between tags renders the same either way. The contents of
 * <textarea>, <pre>, <script> and <style> are passed through untouched.
 */
final class HtmlIndent
{
	private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
	private const RAW = ['textarea', 'pre', 'script', 'style'];

	public static function tidy(string $html): string
	{
		$out = [];
		$depth = 0;
		$raw = null; // inside a raw element: its tag name
		foreach (explode("\n", $html) as $line) {
			if ($raw !== null) {
				$out[] = $line;
				if (stripos($line, '</' . $raw) !== false) {
					$raw = null;
					$depth = max(0, $depth - 1);
				}
				continue;
			}
			$t = ltrim($line);
			if (trim($t) === '') {
				continue;
			}
			// Closing tags at the start of the line pull this line back out.
			$lead = preg_match('#^(?:</[a-zA-Z][\w-]*\s*>\s*)+#', $t, $m) ? substr_count($m[0], '</') : 0;
			$indent = max(0, $depth - $lead);

			// Track nesting through every tag on the line (comments and doctype ignored).
			$scan = preg_replace('/<!--.*?-->|<!doctype[^>]*>/is', '', $t);
			preg_match_all('#<(/?)([a-zA-Z][\w-]*)\b[^>]*?(/?)>#', (string) $scan, $tags, PREG_SET_ORDER);
			foreach ($tags as $tag) {
				$name = strtolower($tag[2]);
				if ($tag[1] === '/') {
					$depth = max(0, $depth - 1);
				} elseif ($tag[3] !== '/' && !in_array($name, self::VOID, true)) {
					$depth++;
					if (in_array($name, self::RAW, true) && stripos($scan, '</' . $name, (int) stripos($scan, '<' . $name)) === false) {
						$raw = $name; // the element runs past this line
					}
				}
			}
			$out[] = str_repeat("\t", $indent) . ($raw === null ? rtrim($t) : $t);
		}
		return implode("\n", $out) . "\n";
	}
}
