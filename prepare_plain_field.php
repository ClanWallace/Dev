<?php
// prepare_plain_field.php
// Shared emoji/annotation and plain-field helpers for gsaktest project root.
// Place this in project root: B:\Hosts\clanwallace\gsaktest\prepare_plain_field.php
//
// By default this file will NOT persist cache changes from web requests.
// Use the admin tool (tools/admin_update_cache.php) to update emoji_cache.json.
// -------------------------------------------------------------------------

if (!defined('PREPARE_PLAIN_FIELD_INCLUDED')) {
    define('PREPARE_PLAIN_FIELD_INCLUDED', true);

    // CONFIG (edit as needed)
    // Path to the emoji cache file (project root)
    $EMOJI_CACHE_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'emoji_cache.json';

    // When false (default), web requests WILL NOT persist cache changes.
    // Set to true temporarily if you really want web requests to persist.
    $EMOJI_CACHE_PERSIST_FROM_WEB = false;

    // Admin key for admin_update_cache.php. Change this to a secret string before
    // placing the admin tool in a publicly accessible site.
    $EMOJI_CACHE_ADMIN_KEY = 'replace_this_with_a_strong_secret';

    // Backup rotation: number of rotating backups to keep.
    // 0 = no backup. Example: 3 will keep emoji_cache.json.bak.1 .. .bak.3 (bak.1 = newest).
    $EMOJI_CACHE_BACKUP_ROTATE = 3;

    // ---------- internal helper: safe writer used by admin tool ----------
    /**
     * Persist the emoji cache safely to disk. This is intended to be called by administrative tools only.
     * Options:
     *   - 'rotate' => bool (default true if $EMOJI_CACHE_BACKUP_ROTATE > 0) : keep rotating backups
     *   - 'rotate_count' => int : override default rotate count
     *   - 'file' => path to write (default $EMOJI_CACHE_FILE)
     *
     * Returns true on success, false on failure.
     */
    function write_emoji_cache(array $cache, array $opts = []): bool {
        global $EMOJI_CACHE_FILE, $EMOJI_CACHE_BACKUP_ROTATE;

        $path = $opts['file'] ?? $EMOJI_CACHE_FILE;
        $rotate = array_key_exists('rotate', $opts) ? (bool)$opts['rotate'] : ($EMOJI_CACHE_BACKUP_ROTATE > 0);
        $rotate_count = isset($opts['rotate_count']) ? intval($opts['rotate_count']) : intval($EMOJI_CACHE_BACKUP_ROTATE);

        $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                // cannot create dir
            }
        }

        // Before writing, rotate backups if requested (rotate older first)
        if ($rotate && $rotate_count > 0 && file_exists($path)) {
            // rotate: bak.(N-1) -> bak.N, ..., bak.1 -> bak.2
            for ($i = $rotate_count - 1; $i >= 1; $i--) {
                $from = $dir . DIRECTORY_SEPARATOR . "emoji_cache.json.bak.$i";
                $to = $dir . DIRECTORY_SEPARATOR . "emoji_cache.json.bak." . ($i + 1);
                if (file_exists($from)) {
                    @rename($from, $to);
                }
            }
            // write current file to bak.1
            $first = $dir . DIRECTORY_SEPARATOR . 'emoji_cache.json.bak.1';
            @copy($path, $first);
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            // Try direct write (rare)
            if (@file_put_contents($path, $json) === false) return false;
            // direct write succeeded
        } else {
            // Attempt atomic rename
            if (!@rename($tmp, $path)) {
                // Fallback copy for Windows oddities
                if (!@copy($tmp, $path)) {
                    @unlink($tmp);
                    return false;
                }
                @unlink($tmp);
            }
        }

        return true;
    }

    // ---------- emoji cache loader (in-memory per request) ----------
    /**
     * Load emoji cache into an in-memory static variable.
     * Returns the array reference to the cache (by copy).
     */
    function &load_emoji_cache(): array {
        static $cache = null;
        static $cache_path = null;
        if ($cache_path === null) {
            global $EMOJI_CACHE_FILE;
            $cache_path = $EMOJI_CACHE_FILE;
        }
        if ($cache === null) {
            if (file_exists($cache_path)) {
                $json = @file_get_contents($cache_path);
                $decoded = $json !== false ? json_decode($json, true) : null;
                $cache = is_array($decoded) ? $decoded : [];
            } else {
                $cache = [];
            }
        }
        return $cache;
    }

    /**
     * Return a human-friendly annotation for a single emoji grapheme.
     * Uses the local cache first; if missing, creates a local fallback and stores it in memory only.
     * Persistence to disk is only done by admin tools (via write_emoji_cache) or if persistence from web is enabled.
     */
    function emoji_annotation(string $emoji): string {
        if ($emoji === '') return 'emoji';

        // Load into local static for this request
        $cache = &load_emoji_cache();

        if (isset($cache[$emoji]) && $cache[$emoji] !== '') {
            return $cache[$emoji];
        }

        // No cache hit. Use a local fallback generator (no network call).
        $fallback = local_fallback_annotation($emoji);
        if ($fallback === '') $fallback = 'emoji';

        // update in-memory cache for this request
        $cache[$emoji] = $fallback;

        // Persist only if allowed (CLI or explicit config)
        global $EMOJI_CACHE_PERSIST_FROM_WEB;
        if (php_sapi_name() === 'cli' || !empty($EMOJI_CACHE_PERSIST_FROM_WEB)) {
            // Use write_emoji_cache to perform safe atomic write
            try {
                write_emoji_cache($cache);
            } catch (Exception $e) {
                // non-fatal; just continue using in-memory cache
            }
        }

        return $cache[$emoji];
    }

    /**
     * Create a readable fallback annotation for an emoji glyph sequence.
     * - strips variation selectors (FE0F), skin tones, gender signs, ZWJ joiners are split into components.
     * - uses IntlChar::charName when available.
     * - returns a lowercase, simple label.
     */
    function local_fallback_annotation(string $emoji): string {
        static $overrides = [
            '❤' => 'red heart',
            '✈' => 'airplane',
            '🏎' => 'racing car',
            '🖥' => 'desktop computer',
            '🖨' => 'printer',
            '❄' => 'snowflake',
            '⭐' => 'star',
        ];
        if (isset($overrides[$emoji])) return $overrides[$emoji];

        // Remove Variation Selector-16
        $clean = preg_replace('/\x{FE0F}/u', '', $emoji);
        // Split on ZWJ (200D) so we name components
        $parts = preg_split('/\x{200D}/u', $clean);

        $component_labels = [];
        foreach ($parts as $part) {
            $utf32 = @mb_convert_encoding($part, 'UTF-32BE', 'UTF-8');
            if ($utf32 === false) continue;
            $len = strlen($utf32) / 4;
            $names = [];
            for ($i = 0; $i < $len; $i++) {
                $data = substr($utf32, $i * 4, 4);
                $ord = unpack('N', $data)[1];

                // skip skin-tone modifiers
                if ($ord >= 0x1F3FB && $ord <= 0x1F3FF) continue;
                if (in_array($ord, [0xFE0F, 0x200D, 0x2640, 0x2642], true)) continue;

                $name = '';
                if (class_exists('IntlChar') && method_exists('IntlChar', 'charName')) {
                    $name = @IntlChar::charName($ord);
                }
                if (!$name) {
                    $name = sprintf('U+%04X', $ord);
                }
                $names[] = $name;
            }
            if (!empty($names)) {
                $component_labels[] = implode(' ', $names);
            }
        }

        if (empty($component_labels)) return '';

        $res = implode(' ', $component_labels);
        // Cleanup and humanize
        $res = strtolower($res);
        $res = str_replace('_', ' ', $res);
        $res = preg_replace('/\b(sign|with|symbol|ornament)\b/', ' ', $res);
        $res = preg_replace('/[^a-z0-9 ]+/', ' ', $res);
        $res = preg_replace('/\s+/', ' ', trim($res));

        return $res;
    }

    /**
     * Prepare a plain (ANSI-safe) field:
     * - groups consecutive emoji into single bracketed, comma-separated lists
     * - transliterates/sanitizes text to ASCII presentation (keeps behaviour matching your existing workflow)
     */
		function prepare_plain_field(string $s, ?string $coreCode = null): string {
				if ($s === "") return "";

				// Iterate in grapheme clusters when available (keeps emoji grouping correct)
				$clusters = null;
				if (function_exists('grapheme_strlen') && function_exists('grapheme_substr')) {
						$len = grapheme_strlen($s);
						$clusters = [];
						for ($i = 0; $i < $len; $i++) {
								$clusters[] = grapheme_substr($s, $i, 1);
						}
				} else {
						// fallback: simple UTF-8 split (may split some ZWJ emoji)
						$clusters = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
				}

				$out = '';
				$emoji_group = [];

				$is_emoji = function(string $cluster): bool {
						if ($cluster === '') return false;
						$ok = @preg_match('/\p{Extended_Pictographic}/u', $cluster) === 1;
						if ($ok) return true;
						return (bool) preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', $cluster);
				};

				$flush_group = function() use (&$emoji_group, &$out) {
						if (empty($emoji_group)) return;
						$labels = [];
						foreach ($emoji_group as $e) {
								$ann = emoji_annotation($e);
								$labels[] = $ann ?: 'emoji';
						}
						$joined = implode(', ', $labels);
						$out .= '[' . $joined . ']';
						$emoji_group = [];
				};

				foreach ($clusters as $cluster) {
						if ($is_emoji($cluster)) {
								$emoji_group[] = $cluster;
								continue;
						}

						// whitespace between emoji counts as separator to keep grouping
						if (!empty($emoji_group) && preg_match('/^\s+$/u', $cluster)) {
								continue;
						}

						if (!empty($emoji_group)) {
								$flush_group();
								$isSpace = preg_match('/^\s$/u', $cluster);
								$isPunct = preg_match('/^[\p{Pc}\p{Pd}\p{Pe}\p{Pf}\p{Pi}\p{Po}\p{Ps}]/u', $cluster);
								if (!$isSpace && !$isPunct && substr($out, -1) !== ' ') {
										$out .= ' ';
								}
						}

						$out .= $cluster;
				}

				if (!empty($emoji_group)) $flush_group();

				// Sanitisation pipeline (keep Unicode; do NOT transliterate to ASCII here)
				$out = strip_tags($out);
				$out = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, "UTF-8");

				if (extension_loaded('intl') && class_exists('Normalizer')) {
						$out = Normalizer::normalize($out, Normalizer::FORM_C);
				}

				// Remove double quotes that could break CSV-like uses
				$out = str_replace('"', '', $out);

				// Collapse whitespace (preserve UTF-8)
				$out = trim(preg_replace('/\s+/u', ' ', $out));

				// Remove C0 control characters and DEL (keep printable Unicode, including Latin-1 / accented)
				$out = preg_replace('/[\x00-\x1F\x7F]/u', '', $out);

				if ($out === '') {
						return $coreCode
								? "Core code '" . $coreCode . "': See Splitscreen Full Display for Adventure Name"
								: "[Emoji-only Adventure Name]";
				}
				return $out;
		}
}