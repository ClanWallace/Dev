<?php
// test_adventure.php - updated
// Fetch Adventure JSON from https://labs.geocaching.com/goto/<UUID>
// Print human-readable indexed list (parent + stages).
// Private fields (answers/award images/message) only returned when &user=clan-wallace
// Also builds a private UserNote block (real tabs and newlines) at the end for copying into GSAK.
// ------------------------------------------------------------------

error_reporting(E_ALL);
ini_set("display_errors", 1);
header("Content-Type: text/plain; charset=utf-8");

// ---------- CONFIG ----------
$ALPHABET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
$BASE = strlen($ALPHABET);
$LENGTH = 4;

// ---------- UTILITIES ----------
function id_to_corecode62(int $id): string
{
    global $ALPHABET, $BASE, $LENGTH;
    if ($id <= 0) {
        return str_repeat("0", $LENGTH);
    }
    $v = $id;
    $out = "";
    while ($v > 0) {
        $r = $v % $BASE;
        $out = $ALPHABET[$r] . $out;
        $v = intdiv($v, $BASE);
    }
    while (strlen($out) < $LENGTH) {
        $out = "0" . $out;
    }
    return $out;
}

function uni_ord($ch)
{
    $u = mb_convert_encoding($ch, "UCS-4BE", "UTF-8");
    $val = @unpack("N", $u);
    return $val ? $val[1] : 0;
}

function strip_gc_a_tags(string $html): string
{
    return preg_replace_callback(
        '/<a\b[^>]*href=(["\']?)([^"\'>\s]+)\1[^>]*>(.*?)<\/a>/is',
        function ($m) {
            $href = $m[2];
            $inner = $m[3];
            if (
                preg_match("/^https?:\/\//i", $href) &&
                preg_match(
                    "/(?:coord\.info|geocaching\.com\/geocache)/i",
                    $href
                )
            ) {
                return $inner; // drop anchor, keep text only
            }
            return $m[0];
        },
        $html
    );
}

function make_gc_links(string $text): string
{
    if ($text === "") {
        return "";
    }
    // unwrap anchors around GC codes
    $text = preg_replace(
        "/<a[^>]*>(\s*(GC[A-Z0-9-]{1,10})\s*)<\/a>/i",
        '$2',
        $text
    );
    // convert bare GC codes -> gsak:// links
    $text = preg_replace_callback(
        "/\b(GC[A-Z0-9-]{1,10})\b/i",
        function ($m) {
            $gc = strtoupper($m[1]);
            return '<a href="gsak://%FF/open/https://coord.info/' .
                $gc .
                '">' .
                $gc .
                "</a>";
        },
        $text
    );
    return $text;
}

function prepare_html_field(string $s): string
{
    if ($s === "") {
        return "";
    }
    // Remove HTTP(S) coord.info anchors (leave our gsak:// links alone)
    $s = strip_gc_a_tags($s);
    // Decode entities to real tags
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, "UTF-8");
    // Normalize newlines
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = trim($s);
    // Protect user semicolons
    $placeholder = "___SEMICOLON_PLACEHOLDER___";
    $s = str_replace(";", $placeholder, $s);
    // Convert newlines to <br />
    $s = nl2br($s);
    // Escape ampersands
    $s = str_replace("&", "&amp;", $s);
    // Convert non-ASCII chars into numeric hex entities
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
    // Restore semicolons
    $out = str_replace($placeholder, "&#59;", $out);
    return $out;
}

function prepare_plain_field(string $s, ?string $coreCode = null): string {
    if ($s === "") {
        return "";
    }

    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, "UTF-8");

    if (extension_loaded('intl')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_C);
    }

    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = str_replace('"', '', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    $s = preg_replace('/[^[:print:]\x00-\x7F]+/', '', $s);

    if ($s === "") {
        if ($coreCode) {
            return "Core code '" . $coreCode . "': See Splitscreen Full Display for Adventure Name";
        } else {
            return "Unnamed Adventure (emoji-only name)";
        }
    }

    return $s;
}

function fetch_adventure_json_block(string $uuid): string
{
    $url = "https://labs.geocaching.com/goto/" . rawurlencode($uuid);
    $html = @file_get_contents($url);
    if ($html === false) {
        die("ERROR: Could not fetch page: $url\n");
    }
    $startPos = strpos($html, '{"AdventureBasicInfo"');
    if ($startPos === false) {
        die("ERROR: Could not find start of Adventure JSON block in page.\n");
    }
    $endMarker = '"AdventureJson":null}';
    $endPos = strpos($html, $endMarker, $startPos);
    if ($endPos !== false) {
        $jsonRaw = substr(
            $html,
            $startPos,
            $endPos + strlen($endMarker) - $startPos
        );
    } else {
        if (
            preg_match(
                '/(\{.*"AdventureBasicInfo".*"AdventureJson"\s*:\s*null\s*\})/s',
                $html,
                $m
            )
        ) {
            $jsonRaw = $m[1];
        } else {
            die("ERROR: Could not locate end of Adventure JSON block.\n");
        }
    }
    // Remove bulky SmartLinkUrlQrCode if present
    $jsonClean = preg_replace(
        '/"SmartLinkUrlQrCode"\s*:\s*"[^"]*"\s*,?/s',
        "",
        $jsonRaw
    );
    return $jsonClean;
}

// ---------- MAIN ----------
$action = $_GET["action"] ?? "";
$uuid = $_GET["uuid"] ?? "";
$reqUser = $_GET["user"] ?? "";

if ($action === "help") {
    echo "# Adventure (Parent) Reference List\n";
    echo "  1 | coreCode                | Adventure numeric ID\n";
    echo "  2 | cacheId                 | Original integer ID\n";
    echo "  3 | guid                    | GUID\n";
    echo "  4 | ALname                  | Plain Name if '' = 'Core code 'coreCode': See Splitscreen Full Display for Adventure Name'\n";
    echo "  5 | ALnameHtml              | HTML Name\n";
    echo "  6 | visibility              | Public / Private\n";
    echo "  7 | isArchived              | true / false\n";
    echo "  8 | ownerId                 | Owner numeric ID\n";
    echo "  9 | ownerName               | Owner username (plain)\n";
    echo " 10 | ownerNameHtml           | Owner username (HTML)\n";
    echo " 11 | favPoints               | Average rating value\n";
    echo " 12 | users                   | Number of ratings\n";
    echo " 13 | created                 | Created UTC (YYYY-MM-DD)\n";
    echo " 14 | placedDate              | Published UTC (YYYY-MM-DD)\n";
    echo " 15 | duration                | Median time to complete\n";
    echo " 16 | ALlongDescriptionHtml   | Adventure description (HTML)\n";
    echo " 17 | lat                     | Latitude\n";
    echo " 18 | lon                     | Longitude\n";
    echo " 19 | ALimage                 | Key image URL\n";
    echo " 20 | cacheType               | Always 'A'\n";
    echo " 21 | theme                   | Adventure theme\n";
    echo " 22 | adventureType           | Sequential / Nonsequential\n";
    echo " 23 | hints                   | SequenceText ('Sequence: ...')\n";
    echo " 24 | hasCorrected            | True/False\n";
    echo " 25 | dnf                     | True/False\n";
    echo " 26 | stages                  | Number of stages\n";
    echo " 27 | gcNote                  | Summary string (ratings/users)\n";
    echo " 28 | ALshortDescriptionHtml  | HTML intro block\n";
    echo " 29 | ALsplitScreen           | Composite HTML for GSAK split screen\n";
    echo "\n";

    echo "# Lab (Stage) Reference List\n";
    echo "  1 | lab                     | Stage number (1–5)\n";
    echo "  2 | suffix                  | Numeric suffix (01–05)\n";
    echo "  3 | guid                    | Stage GUID\n";
    echo "  4 | LBname                  | Full stage name (plain)\n";
    echo "  5 | LBnameHtml              | Full stage name (HTML)\n";
    echo "  6 | lat                     | Stage latitude\n";
    echo "  7 | lon                     | Stage longitude\n";
    echo "  8 | LBimage                 | Stage image URL\n";
    echo "  9 | geofence                | Geofence radius (m)\n";
    echo " 10 | challengeType           | Question type\n";
    echo " 11 | question                | Question (plain)\n";
    echo " 12 | LBdescriptionHtml       | Stage description (HTML)\n";
    echo " 13 | shortDescriptionHtml    | Header + question HTML\n";
    echo " 14 | longDescriptionHtml     | Long HTML description\n";
    echo " 15 | hasCorrected            | False\n";
    echo " 16 | dnf                     | False\n";
    echo " 17 | gcNote                  | (reserved)\n";
    echo " 18 | LBsplitScreen           | Split screen composite HTML\n";
    echo " 19 | answer                  | Player answer (private only)\n";
    echo " 20 | awardImage              | Completion image (private only)\n";
    echo " 21 | message                 | Journal message (private only)\n";
    echo "\n";

    echo "# Spreadsheet / UserNote block\n";
    echo "  1 | userNote                | Final tab-delimited stage rows\n";
    exit();
}


// url (for userNote header)
$url = "https://labs.geocaching.com/goto/" . rawurlencode($uuid);
$debug = isset($_GET["debug"]) ? true : false;

if ($action !== "adventure" || $uuid === "") {
    echo "Usage: test_adventure.php?action=adventure&uuid=UUID[&user=clan-wallace]\n";
    exit();
}
// Global UserNote
$userNote = "";
$jsonClean = fetch_adventure_json_block($uuid);
$js = json_decode($jsonClean, true);
if ($js === null) {
    echo "ERROR: Invalid JSON after extraction. " .
        json_last_error_msg() .
        "\n\n";
    echo "First 800 chars of extracted JSON (for debugging):\n";
    echo substr($jsonClean, 0, 800) . "\n";
    exit();
}

$basic = $js["AdventureBasicInfo"] ?? null;
if (!$basic) {
    echo "ERROR: AdventureBasicInfo missing in decoded JSON.\n";
    exit();
}

// Decide if private fields should be shown (only clan-wallace)
$showPrivate = false;
if ($reqUser !== "" && strcasecmp($reqUser, "clan-wallace") === 0) {
    $showPrivate = true;
}

// Parent (Adventure) fields
$cacheId = intval($basic["id"] ?? 0);
$coreCode = id_to_corecode62($cacheId);
$guid = $basic["adventureGuid"] ?? "";

$ALname_raw = $basic["title"] ?? "";
$ALname = prepare_plain_field($ALname_raw);
$ALnameHtml = prepare_html_field($ALname_raw);

$visibility = $basic["visibility"] ?? "";
$isArchived =
    isset($basic["isArchived"]) && $basic["isArchived"] ? "true" : "false";
$ownerId = $basic["ownerGeoAccountId"] ?? "";
$ownerName_raw = $basic["ownerUsername"] ?? "";
$ownerName = prepare_plain_field($ownerName_raw);
$ownerNameHtml = prepare_html_field($ownerName_raw);

$favPoints = $basic["ratingsAverage"] ?? 0;
$users = $basic["ratingsTotalCount"] ?? 0;
$created = substr($basic["createdUtc"] ?? "", 0, 10);
$placedDate = substr($basic["publishedUtc"] ?? "", 0, 10);
$duration = $basic["medianTimeToComplete"] ?? null;

$ALlongDescription_raw = $basic["description"] ?? "";
$ALlongDescription_with_gc = make_gc_links($ALlongDescription_raw);
$ALlongDescriptionHtml = prepare_html_field($ALlongDescription_with_gc);

$lat = $basic["location"]["latitude"] ?? "";
$lon = $basic["location"]["longitude"] ?? "";
$ALimage = $basic["keyImageUrl"] ?? "";

$cacheType = "A";
$theme =
    is_array($basic["adventureThemes"] ?? null) &&
    count($basic["adventureThemes"])
        ? $basic["adventureThemes"][0]
        : "";
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
if ($duration) {
    $gcNote .= ". Duration about {$duration} mins";
}

$ALshortDescriptionHtml = "<h2 style=\"text-align: center\">{$ALnameHtml}<br />by<br />{$ownerNameHtml}</h2>";

$ALsplitScreen = "<div style='font-family:verdana,arial,sans-serif'><div style='box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;padding:1em;margin:2em 0;'><img src='https://labs.geocaching.com/Content/images/al-logo-balloon.svg' align='absmiddle' alt='Adventure' title='Adventure' style='float:right;width:6em;'/><p><span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>$gcNote</span>&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>$sequenceText</span></p><p>{$ALlongDescriptionHtml}</p></div><p style='text-align: center;'><img src='$ALimage' style='max-width:80%; max-height:80%; border:6px solid white; border-radius:20px; box-shadow:5px 5px 7px rgba(0,0,0,0.5); '/></p></div>";

if ($showPrivate) {
    // Adventure header row for UserNote: (tabs chosen to match your macro expectation)
    $userNote .=
        "\t\t\t" . ($ALname ?? "") . "\t\t\t\t\t\t" . ($url ?? "") . "\n";
}

// ---------- PRINT HUMAN-READABLE LIST ----------
$waypointIndex = 1;

// Parent / Adventure
echo "=== WAYPOINT {$waypointIndex} (PARENT ADVENTURE) ===\n";
$parent_map = [
    1 => ["coreCode", "coreCode", $coreCode],
    2 => ["cacheId", "cacheId", $cacheId],
    3 => ["guid", "guid", $guid],
    4 => ["ALname", "ALname", $ALname],
    5 => ["ALnameHtml", "ALnameHtml", $ALnameHtml],
    6 => ["visibility", "visibility", $visibility],
    7 => ["isArchived", "isArchived", $isArchived],
    8 => ["ownerId", "ownerId", $ownerId],
    9 => ["ownerName", "ownerName", $ownerName],
    10 => ["ownerNameHtml", "ownerNameHtml", $ownerNameHtml],
    11 => ["favPoints", "favPoints", $favPoints],
    12 => ["users", "users", $users],
    13 => ["created", "created", $created],
    14 => ["placedDate", "placedDate", $placedDate],
    15 => ["duration", "duration", $duration === null ? "" : $duration],
    16 => [
        "ALlongDescriptionHtml",
        "ALlongDescriptionHtml",
        $ALlongDescriptionHtml,
    ],
    17 => ["lat", "lat", $lat],
    18 => ["lon", "lon", $lon],
    19 => ["ALimage", "ALimage", $ALimage],
    20 => ["cacheType", "cacheType", $cacheType],
    21 => ["theme", "theme", $theme],
    22 => ["adventureType", "adventureType", $adventureType],
    23 => ["hints", "hints", $sequenceText],
    24 => ["hasCorrected", "hasCorrected", $hasCorrected],
    25 => ["dnf", "dnf", $dnf],
    26 => ["stages", "stages", $stagesCount],
    27 => ["gcNote", "gcNote", $gcNote],
    28 => [
        "ALshortDescriptionHtml",
        "ALshortDescriptionHtml",
        $ALshortDescriptionHtml,
    ],
    29 => ["ALsplitScreen", "ALsplitScreen", $ALsplitScreen],
];

foreach ($parent_map as $idx => $entry) {
    list($var, $label, $val) = $entry;
    $display = (string) $val;
    $display = str_replace("\r\n", "\n", $display);
    $display = str_replace("\n", "\n", $display);
    echo sprintf("%3d | %-22s | %s\n", $idx, $var, $display);
}

echo "\n";

// Stages (Labs)
if ($stagesCount === 0) {
    echo "No stages found in Adventure JSON.\n";
    exit();
}

$lab = 0;
foreach ($stagesArr as $stage) {
    $lab++;
    $waypointIndex++;
    echo "=== WAYPOINT {$waypointIndex} (STAGE {$lab}) ===\n";

    $suf = str_pad((string) $lab, 2, "0", STR_PAD_LEFT);
    $guid = $stage["id"] ?? "";
    $stage_title_raw = $stage["title"] ?? "";
    $stage_title_plain = prepare_plain_field($stage_title_raw);
    $LBname = $ALname . " : S" . $lab . " " . $stage_title_plain;
    $LBnameHtml = prepare_html_field($ALnameHtml . " : " . $stage_title_raw);

    $slat = $stage["location"]["latitude"] ?? "";
    $slon = $stage["location"]["longitude"] ?? "";
    $LBimage = "";
    if (!empty($stage["keyImage"]["url"])) {
        $LBimage = $stage["keyImage"]["url"];
    } elseif (!empty($stage["keyImageUrl"])) {
        $LBimage = $stage["keyImageUrl"];
    } elseif (!empty($stage["keyImage"])) {
        $LBimage = $stage["keyImage"];
    }

    $geofence = $stage["geofencingRadius"] ?? "";
    $challengeType =
        $stage["challengeType"] ?? ($stage["challenge"]["challengeType"] ?? "");

    $q =
        $stage["questionToAnswer"]["question"] ??
        ($stage["questionText"] ?? ($stage["question"] ?? ""));
		// Capture the Answer
    $a = $stage["questionToAnswer"]["answer"] ?? ($stage["answer"] ?? "");

    // If the answer is multiChoice
		if (
        strcasecmp($challengeType, "MultiChoice") === 0 ||
        strcasecmp($challengeType, "MultipleChoice") === 0
    ) {
        // set answer
				$a_plain = "MultiChoice";
    } else {
        // Convert answer to an ANSI safe string
				$a_plain = prepare_plain_field($a);
    }

    $q_plain = prepare_plain_field($q);
    $q_html = prepare_html_field(make_gc_links($q));
    // Create an HTML entities version of the answer
		$a_html =
        $a_plain === "MultiChoice"
            ? "MultiChoice"
            : prepare_html_field(make_gc_links($a));

    $LBdescriptionHtml = prepare_html_field(
        make_gc_links($stage["description"] ?? "")
    );
    $LBdescriptionPlain = prepare_plain_field($stage["description"] ?? "");

    $LBshortDescriptionHtml = "<h2 style=\"text-align: center\">{$LBnameHtml}</h2>";
    if ($q) {
        $LBshortDescriptionHtml .= "<p style=\"text-align: center\"><b>Question</b>: {$q_html}</p>";
    }

    $LBsplitScreen = "<div style='font-family:verdana,arial,sans-serif'><div style='box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0,0,0,0.3) 0px 30px 60px -30px, rgba(10,37,64,0.35) 0px -2px 6px 0px inset;padding:1em;margin:2em 0;'><img src='file:///C:/PROGRA~2/gsak/images/cacheQ.gif' align='absmiddle' alt='Lab Stage' title='Lab Stage' style='float:right;'/><p><span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>Stage {$lab} of {$stagesCount}</span>&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>{$sequenceText}</span>&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>Geofence: {$geofence} metres</span></p><div>{$LBdescriptionHtml}</div></div><p style='text-align: center;'><img src='{$LBimage}' style='max-width:80%; max-height:80%; border:6px solid white; border-radius:20px; box-shadow:5px 5px 7px rgba(0,0,0,0.5);'/></p>{$ALsplitScreen}</div>";

    // Build stage map
    $stage_map = [
        1 => ["lab", "lab", $lab],
        2 => ["suffix", "suffix", $suf],
        3 => ["guid", "guid", $guid],
        4 => ["LBname", "LBname", $LBname],
        5 => ["LBnameHtml", "LBnameHtml", $LBnameHtml],
        6 => ["lat", "lat", $slat],
        7 => ["lon", "lon", $slon],
        8 => ["LBimage", "LBimage", $LBimage],
        9 => ["geofence", "geofence", $geofence],
        10 => ["challengeType", "challengeType", $challengeType],
        11 => ["question", "question", $q_plain],
        12 => ["LBdescriptionHtml", "LBdescriptionHtml", $LBdescriptionHtml],
        13 => [
            "shortDescriptionHtml",
            "shortDescriptionHtml",
            $LBshortDescriptionHtml,
        ],
        14 => [
            "longDescriptionHtml",
            "longDescriptionHtml",
            $LBdescriptionHtml,
        ],
        15 => ["hasCorrected", "hasCorrected", "False"],
        16 => ["dnf", "dnf", "False"],
        17 => ["gcNote", "gcNote", ""],
        18 => ["LBsplitScreen", "LBsplitScreen", $LBsplitScreen],		
    ];

    if ($showPrivate) {
				
        // Award image (safe fallback)
        $awardImage =
            $stage["journal"]["image"]["url"] ??
            ($stage["journal"]["image"] ?? "");

        // message (two forms)
        $message_raw = $stage["journal"]["message"] ?? "";
        // HTML-safe variant (if you still want to output message_html elsewhere)
        $message_html =
            $message_raw !== "" ? prepare_html_field($message_raw) : "";

        // Plain, single-line variant for spreadsheet:
        $message_plain = $message_raw;
        // decode entities, strip tags, normalize whitespace, remove newlines
        $message_plain = html_entity_decode(
            $message_plain,
            ENT_QUOTES | ENT_HTML5,
            "UTF-8"
        );
        $message_plain = strip_tags($message_plain);
        $message_plain = str_replace(["\r", "\n"], " ", $message_plain); // remove line breaks
        $message_plain = preg_replace("/\s+/", " ", $message_plain); // collapse spaces
        $message_plain = trim($message_plain);
        // remove non-ASCII to avoid emoji mis-parsing in some tools (keeps basic punctuation)
        $message_plain = preg_replace('/[^\x20-\x7E]/', "", $message_plain);

				// add answer if private
				$stage_map[19] = ["answer", "answer", $a_html];
				$stage_map[20] = ["awardImage", "awardImage", $awardImage];
				$stage_map[21] = ["message", "message", $message_plain];

        // Stage deep-link: use the Adventure deeplink (as you used previously), or build per-stage if available
        $deepLink = $url;

        // Build the tab-delimited row.  NOTE: we keep the same number of tab columns as before.
        // Columns (example): Latitude, Longitude, Answer, Name, Award/message, Core, Id, Code, [empty x3], DeepLink, URL, AwardImage
        $userNote .=
            ($slat ?? "") .
            "\t" .
            ($slon ?? "") .
            "\t" .
            ($a_plain ?? "") .
            "\t" .
            ($stage_title_plain ?? "") .
            "\t" .
            ($message_plain ?? "") .
            "\t" .
            ($coreCode ?? "") .
            "\t" .
            ($cacheId ?? "") .
            "\t" .
            "" .
            "\t" . // Code (left empty as requested)
            "" .
            "\t" . // extra empty column
            "" .
            "\t" . // extra empty column
            "\t" .
            ($awardImage ?? "") .
            "\n";
    }

    foreach ($stage_map as $idx => $entry) {
        list($var, $label, $val) = $entry;
        $display = (string) $val;
        echo sprintf("%3d | %-22s | %s\n", $idx, $var, $display);
    }
}

// Only output the final UserNote block for private (Clan-Wallace) requests
if ($showPrivate) {
    echo "=== USERNOTE (PRIVATE) ===\n";
    // echo the whole block once (real tabs and newlines)
    echo $userNote;
    echo "\n"; // final newline for cleanliness
}
exit();
?>
