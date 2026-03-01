<?php
// new_adventure.php
// Streamlined extractor for GSAK macros: outputs Adventure + Stages + UserNote
// Columns separated by "||", rows separated by "|@|"
// Last row = userNote (only when user=clan-wallace)
//
// Updated: robust helper-path discovery so this file can live in project root
// (where prepare_plain_field.php also lives) or in a subfolder/tools/.
// Also: buffers output and converts to CP1252 for GSAK consumption.
// ------------------------------------------------------------------

error_reporting(E_ALL);
ini_set("display_errors", 1);

// Buffer entire output so we can convert to CP1252 at the end
ob_start();

// ---------------- CONFIG ----------------
$ALPHABET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
$BASE = strlen($ALPHABET);
$LENGTH = 4;

// ---------------- UTILITIES ----------------
function id_to_corecode62(int $id): string {
    global $ALPHABET, $BASE, $LENGTH;
    if ($id <= 0) return str_repeat("0", $LENGTH);
    $out = "";
    while ($id > 0) {
        $r = $id % $BASE;
        $out = $ALPHABET[$r] . $out;
        $id = intdiv($id, $BASE);
    }
    while (strlen($out) < $LENGTH) $out = "0" . $out;
    return $out;
}

function uni_ord($ch) {
    $u = mb_convert_encoding($ch, "UCS-4BE", "UTF-8");
    $val = @unpack("N", $u);
    return $val ? $val[1] : 0;
}

function strip_gc_a_tags(string $html): string {
    return preg_replace_callback(
        '/<a\b[^>]*href=(["\']?)([^"\'>\s]+)\1[^>]*>(.*?)<\/a>/is',
        function ($m) {
            $href = $m[2];
            $inner = $m[3];
            if (preg_match("/^https?:\/\//i", $href) &&
                preg_match("/(?:coord\.info|geocaching\.com\/geocache)/i", $href)) {
                return $inner; // keep text only
            }
            return $m[0];
        },
        $html
    );
}

function make_gc_links(string $text): string {
    if ($text === "") return "";
    $text = preg_replace("/<a[^>]*>(\s*(GC[A-Z0-9-]{1,10})\s*)<\/a>/i", '$2', $text);
    $text = preg_replace_callback(
        "/\b(GC[A-Z0-9-]{1,10})\b/i",
        function ($m) {
            $gc = strtoupper($m[1]);
            return '<a href="gsak://%FF/open/https://coord.info/' . $gc . '">' . $gc . '</a>';
        },
        $text
    );
    return $text;
}

function prepare_html_field(string $s): string {
    if ($s === "") return "";
    $s = strip_gc_a_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, "UTF-8");
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = trim($s);
    $placeholder = "___SEMICOLON_PLACEHOLDER___";
    $s = str_replace(";", $placeholder, $s);
    $s = nl2br($s);
    $s = str_replace("&", "&amp;", $s);
    $out = "";
    $len = mb_strlen($s, "UTF-8");
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($s, $i, 1, "UTF-8");
        $cp = uni_ord($ch);
        if ($cp > 127) {
            $out .= "&#x" . strtoupper(dechex($cp)) . ";";
        } else {
            $out .= $ch;
        }
    }
    $out = str_replace($placeholder, "&#59;", $out);
    return $out;
}

// ---------- include shared prepare_plain_field helper (project root or parent) ----------
// Try a few sensible candidate locations so the script works whether it's placed
// in the project root or in tools/ (like test_adventure.php).
$helper = __DIR__ . DIRECTORY_SEPARATOR . 'prepare_plain_field.php';
if (!file_exists($helper)) {
    $parent = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') ?: (__DIR__ . DIRECTORY_SEPARATOR . '..');
    $helper = $parent . DIRECTORY_SEPARATOR . 'prepare_plain_field.php';
}
if (!file_exists($helper)) {
    // final fallback: relative sibling (handles odd setups)
    $helper = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'prepare_plain_field.php';
}
if (!file_exists($helper)) {
    // helper missing — clean buffer and show readable UTF-8 error
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    die("ERROR: Missing helper file: {$helper}\nPlace prepare_plain_field.php in project root (next to new_adventure.php) or one level up.\n");
}
require_once $helper; // provides prepare_plain_field(), emoji_annotation(), load_emoji_cache(), write_emoji_cache() etc.

// ---------------- MAIN ----------------
$action = $_GET["action"] ?? "";
$uuid = $_GET["uuid"] ?? "";
$reqUser = $_GET["user"] ?? "";

// define URL early to avoid notices
$url = $uuid !== '' ? 'https://labs.geocaching.com/goto/' . rawurlencode($uuid) : '';

if ($action !== "adventure" || $uuid === "") {
    echo "Usage: new_adventure.php?action=adventure&uuid=UUID\n";
    // proceed to conversion at end so output is consistent
}

// Global UserNote
$userNote = "";

/**
 * Fetches the Adventure JSON block from labs.geocaching.com/goto/<uuid>
 */
function fetch_adventure_json_block(string $uuid): string {
    if (empty($uuid)) return "";
    $url = "https://labs.geocaching.com/goto/" . rawurlencode($uuid);
    $html = @file_get_contents($url);
    if ($html === false) die("ERROR: Could not fetch page: $url\n");
    $startPos = strpos($html, '{"AdventureBasicInfo"');
    if ($startPos === false) die("ERROR: Could not find start of Adventure JSON block.\n");
    $endMarker = '"AdventureJson":null}';
    $endPos = strpos($html, $endMarker, $startPos);
    if ($endPos !== false) {
        $jsonRaw = substr($html, $startPos, $endPos + strlen($endMarker) - $startPos);
    } elseif (preg_match('/(\{.*"AdventureBasicInfo".*"AdventureJson"\s*:\s*null\s*\})/s', $html, $m)) {
        $jsonRaw = $m[1];
    } else {
        die("ERROR: Could not locate end of Adventure JSON block.\n");
    }
    $jsonClean = preg_replace('/"SmartLinkUrlQrCode"\s*:\s*"[^"]*"\s*,?/s', "", $jsonRaw);
    return $jsonClean;
}

// ---------- MAIN processing ----------
$jsonClean = fetch_adventure_json_block($uuid);
$js = json_decode($jsonClean, true);
if ($js === null) {
    echo "ERROR: Invalid JSON after extraction. " . json_last_error_msg() . "\n";
    // continue to conversion (so error is visible)
}
$basic = $js["AdventureBasicInfo"] ?? null;
if (!$basic) {
    echo "ERROR: AdventureBasicInfo missing in JSON.\n";
    // continue to conversion
}

$showPrivate = ($reqUser !== "" && strcasecmp($reqUser, "clan-wallace") === 0);

// ---------- Parent / Adventure ----------
$cacheId = intval($basic["id"] ?? 0);
$coreCode = id_to_corecode62($cacheId);
$guid = $basic["adventureGuid"] ?? "";
$ALname_raw = $basic["title"] ?? "";
$ALname = prepare_plain_field($ALname_raw, $coreCode);
$ALnameHtml = prepare_html_field($ALname_raw);
$visibility = $basic["visibility"] ?? "";
$isArchived = isset($basic["isArchived"]) && $basic["isArchived"] ? "true" : "false";
$ownerId = $basic["ownerGeoAccountId"] ?? "";
$ownerName_raw = $basic["ownerUsername"] ?? "";
$ownerName = prepare_plain_field($ownerName_raw);
$ownerNameHtml = prepare_html_field($ownerName_raw);
$favPoints = $basic["ratingsAverage"] ?? 0;
$users = $basic["ratingsTotalCount"] ?? 0;
$created = substr($basic["createdUtc"] ?? "", 0, 10);
$placedDate = substr($basic["publishedUtc"] ?? "", 0, 10);
$duration = $basic["medianTimeToComplete"] ?? "";
$ALlongDescription_raw = $basic["description"] ?? "";
$ALlongDescriptionHtml = prepare_html_field(make_gc_links($ALlongDescription_raw));
$lat = $basic["location"]["latitude"] ?? "";
$lon = $basic["location"]["longitude"] ?? "";
$ALimage = $basic["keyImageUrl"] ?? "";
$cacheType = "A";
$theme = (is_array($basic["adventureThemes"] ?? null) && count($basic["adventureThemes"])) ? $basic["adventureThemes"][0] : "";
$adventureType = $basic["adventureType"] ?? "";

if (strcasecmp($adventureType, "Nonsequential") === 0) {
    $sequenceText = "Sequence: Random" . ($theme ? " - {$theme} themed" : "");
    $hasCorrected = "True";
    $dnf = "False";
} else {
    $sequenceText = "Sequence: Linear" . ($theme ? " - {$theme} themed" : "");
    $hasCorrected = "False";
    $dnf = "True";
}

$stagesArr = $basic["stages"] ?? [];
$stagesCount = count($stagesArr);

$gcNote = "Ave: {$favPoints} rating from {$users} Users for {$stagesCount} Lab Stages";
if ($duration) $gcNote .= ". Duration about {$duration} mins";

$ALshortDescriptionHtml = "<h2 style=\"text-align: center\">{$ALnameHtml}<br />by<br />{$ownerNameHtml}</h2>";
$ALsplitScreen = "<div style='font-family:verdana,arial,sans-serif'><div style='box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;padding:1em;margin:2em 0;'><img src='https://labs.geocaching.com/Content/images/al-logo-balloon.svg' align='absmiddle' alt='Adventure' title='Adventure' style='float:right;width:6em;'/><p><span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>$gcNote</span>&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>$sequenceText</span></p><p>{$ALlongDescriptionHtml}</p></div><p style='text-align: center;'><img src='$ALimage' style='max-width:80%; max-height:80%; border:6px solid white; border-radius:20px; box-shadow:5px 5px 7px rgba(0,0,0,0.5); '/></p></div>";

$parent_row = [
    $coreCode,$cacheId,$guid,$ALname,$ALnameHtml,$visibility,$isArchived,$ownerId,$ownerName,
    $ownerNameHtml,$favPoints,$users,$created,$placedDate,$duration,$ALlongDescriptionHtml,$lat,$lon,$ALimage,
    $cacheType,$theme,$adventureType,$sequenceText,$hasCorrected,$dnf,$stagesCount,$gcNote,$ALshortDescriptionHtml,$ALsplitScreen
];

if ($showPrivate) {
    $userNote .= "\t\t\t" . ($ALname ?? "") . "\t\t\t\t\t\t" . ($url ?? "") . "\n";
}

// ---------- Stages ----------
$output_blocks = [];
$output_blocks[] = implode("||", $parent_row);
$lab = 0;

foreach ($stagesArr as $stage) {
    $lab++;
    $suf = str_pad((string)$lab,2,"0",STR_PAD_LEFT);
    $guid = $stage["id"] ?? "";
    $stage_title_raw = $stage["title"] ?? "";
    $stage_title_plain = prepare_plain_field($stage_title_raw);
    $LBname = $ALname . " : S" . $lab . " " . $stage_title_plain;
    $LBnameHtml = prepare_html_field($ALnameHtml . " : " . $stage_title_raw);
    $slat = $stage["location"]["latitude"] ?? "";
    $slon = $stage["location"]["longitude"] ?? "";
    $LBimage = $stage["keyImage"]["url"] ?? ($stage["keyImageUrl"] ?? ($stage["keyImage"] ?? ""));
    $geofence = $stage["geofencingRadius"] ?? "";
    $challengeType = $stage["challengeType"] ?? ($stage["challenge"]["challengeType"] ?? "");
    $q = $stage["questionToAnswer"]["question"] ?? ($stage["questionText"] ?? ($stage["question"] ?? ""));
    $a = $stage["questionToAnswer"]["answer"] ?? ($stage["answer"] ?? "");
    if (strcasecmp($challengeType,"MultiChoice")===0 || strcasecmp($challengeType,"MultipleChoice")===0) {
        $a_plain = "MultiChoice";
    } else {
        $a_plain = prepare_plain_field($a);
    }
    $question = prepare_plain_field($q);
    $questionHtml = prepare_html_field(make_gc_links($q));
    $answer = ($a_plain==="MultiChoice") ? "MultiChoice" : prepare_html_field(make_gc_links($a));
    $LBdescriptionHtml = prepare_html_field(make_gc_links($stage["description"] ?? ""));
    $LBdescriptionPlain = prepare_plain_field($stage["description"] ?? "");
    $LBshortDescriptionHtml = "<h2 style=\"text-align: center\">{$LBnameHtml}</h2>";
    if ($q) $LBshortDescriptionHtml .= "<p style=\"text-align: center\"><b>Question</b>: {$questionHtml}</p>";
    $LBsplitScreen = "<div style='font-family:verdana,arial,sans-serif'><div style='box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;padding:1em;margin:2em 0;'><img src='file:///C:/PROGRA~2/gsak/images/cacheQ.gif' align='absmiddle' alt='Lab Stage' title='Lab Stage' style='float:right;'/><p><span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>Stage {$lab} of {$stagesCount}</span>&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>{$sequenceText}</span>&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>Geofence: {$geofence} metres</span></p><div>{$LBdescriptionHtml}</div></div><p style='text-align: center;'><img src='{$LBimage}' style='max-width:80%; max-height:80%; border:6px solid white; border-radius:20px; box-shadow:5px 5px 7px rgba(0,0,0,0.5);'/></p>{$ALshortDescriptionHtml}{$ALsplitScreen}</div>";
    $stage_row = [
        $lab,$suf,$guid,$LBname,$LBnameHtml,$slat,$slon,$LBimage,$geofence,$challengeType,$question,$LBdescriptionHtml,$LBshortDescriptionHtml,$LBdescriptionHtml,"False","False","",$LBsplitScreen
    ];
    if ($showPrivate) {
        $awardImage = $stage["journal"]["image"]["url"] ?? ($stage["journal"]["image"] ?? "");
        $message_raw = $stage["journal"]["message"] ?? "";
        $message_html = ($message_raw!=="") ? prepare_html_field($message_raw) : "";
        $message_plain = html_entity_decode($message_raw, ENT_QUOTES | ENT_HTML5, "UTF-8");
        $message_plain = strip_tags($message_plain);
        $message_plain = str_replace(["\r","\n"], " ", $message_plain);
        $message_plain = preg_replace("/\s+/", " ", $message_plain);
        $message_plain = trim($message_plain);
        $message_plain = preg_replace('/[^\x20-\x7E]/', "", $message_plain);
        $stage_row[] = $answer;
        $stage_row[] = $awardImage;
        $stage_row[] = $message_plain;

        $userNote .= ($slat??"")."\t".($slon??"")."\t".($answer??"")."\t".($LBname??"")."\t".($message_plain??"")."\t".($coreCode??"")."\t".($cacheId??"")."\t\t\t\t\t".($awardImage??"")."\n";
    }
    $output_blocks[] = implode("||", $stage_row);
}

// ---------- Append userNote ----------
$output_blocks[] = $userNote;
echo implode("|@|", $output_blocks);

// ---------- At this point we have produced all output into the buffer ----------
	$full_output = (string) ob_get_clean(); // get the UTF-8 internal buffer

	// Attempt conversion to CP1252 (Windows-1252)
	$cp1252 = false;
	$conversion_note = '';
	if (function_exists('iconv')) {
			// Try transliteration (keeps readable approximations) first
			$cp1252 = @iconv('UTF-8', 'CP1252//TRANSLIT', $full_output);
			if ($cp1252 === false) {
					// Fallback: drop unmappable chars (should still map accents)
					$cp1252 = @iconv('UTF-8', 'CP1252//IGNORE', $full_output);
					$conversion_note = '[iconv TRANSLIT failed; used IGNORE]';
			} else {
					$conversion_note = '[iconv TRANSLIT OK]';
			}
	}

	// If iconv not available or conversion failed completely, fall back to UTF-8 output
	if ($cp1252 === false || $cp1252 === null) {
			// Provide useful debug header and output as UTF-8 so browser shows characters,
			// but this branch will not be used by GSAK (we want CP1252 bytes for GSAK).
			if (function_exists('header_remove')) header_remove();
			header('Content-Type: text/plain; charset=utf-8');
			echo $full_output;
			// Optional place to log or echo $conversion_note for debugging:
			// echo "\n\n[DEBUG] conversion_note: $conversion_note\n";
			exit();
	}

	// At this point $cp1252 contains the exact bytes to send to GSAK.
	// Ensure no other headers are in the way and send explicit binary bytes.
	if (function_exists('header_remove')) header_remove();
	header('Content-Type: text/plain; charset=Windows-1252');
	header('Content-Transfer-Encoding: binary');
	header('Content-Length: ' . strlen($cp1252));
	// Prevent caching (optional)
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');

	// Echo exact bytes (binary-safe)
	echo $cp1252;
	exit();

?>