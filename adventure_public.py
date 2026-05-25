# File: python/app/server/adventure_public.py ✅
# Version: 2.7 (Sanitized Public Distribution)

import json
import re
import urllib.parse
from urllib.request import urlopen
from html import unescape

# ---------------- UTILITIES ----------------

ALPHABET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
BASE = len(ALPHABET)
LENGTH = 4

def id_to_corecode62(id_: int) -> str:
    if id_ <= 0:
        return "0" * LENGTH
    out = ""
    while id_ > 0:
        r = id_ % BASE
        out = ALPHABET[r] + out
        id_ //= BASE
    return out.rjust(LENGTH, "0")

def strip_gc_a_tags(html: str) -> str:
    def repl(m):
        href = m.group(2)
        inner = m.group(3)
        if re.match(r"^https?://", href, re.I) and re.search(r"(coord\.info|geocaching\.com/geocache)", href, re.I):
            return inner
        return m.group(0)
    return re.sub(r'<a\b[^>]*href=(["\']?)([^"\'>\s]+)\1[^>]*>(.*?)</a>', repl, html, flags=re.I|re.S)

def make_gc_links(text: str) -> str:
    if not text:
        return ""
    text = re.sub(r"<a[^>]*>\s*(GC[A-Z0-9-]{1,10})\s*</a>", r"\1", text, flags=re.I)
    def repl(m):
        gc = m.group(1).upper()
        return f'<a href="gsak://%FF/open/https://coord.info/{gc}">{gc}</a>'
    text = re.sub(r"\b(GC[A-Z0-9-]{1,10})\b", repl, text, flags=re.I)
    return text

def prepare_html_field(s: str) -> str:
    if not s:
        return ""
    s = strip_gc_a_tags(s)
    s = unescape(s)
    s = html_numeric_entities(s)
    s = s.replace("\r\n", "\n").replace("\r", "\n").strip()
    s = s.replace("\n", "<br />")
    return s

def html_numeric_entities(text: str) -> str:
    if not text:
        return ""
    out = []
    for ch in text:
        code = ord(ch)
        if code < 128:
            out.append(ch)
        else:
            out.append(f"&#x{code:X};")
    return "".join(out)

# ---------------- MAIN ----------------
def handle_adventure_request(handler):
    query = urllib.parse.urlparse(handler.path).query
    params = dict(urllib.parse.parse_qsl(query))
    uuid = params.get("uuid", "")

    if not uuid:
        handler.send_response(400)
        handler.end_headers()
        handler.wfile.write(b"Missing UUID parameter")
        return

    url = f"https://labs.geocaching.com/goto/{uuid}"

    try:
        html = urlopen(url).read().decode("utf-8")
    except Exception as e:
        handler.send_response(500)
        handler.end_headers()
        handler.wfile.write(f"Failed to fetch adventure: {e}".encode("utf-8"))
        return

    start = html.find('{"AdventureBasicInfo"')
    end_marker = '"AdventureJson":null}'
    end = html.find(end_marker, start)

    if end != -1:
        json_raw = html[start:end + len(end_marker)]
    else:
        handler.send_response(500)
        handler.end_headers()
        handler.wfile.write(b"Cannot locate Adventure JSON block")
        return

    json_clean = re.sub(r'"SmartLinkUrlQrCode"\s*:\s*"[^"]*"\s*,?', "", json_raw)

    try:
        js = json.loads(json_clean)
    except Exception as e:
        handler.send_response(500)
        handler.end_headers()
        handler.wfile.write(f"JSON decode failed: {e}".encode("utf-8"))
        return

    basic = js.get("AdventureBasicInfo", {})

    # ---------------- Parent Adventure ----------------
    cache_id = int(basic.get("id", 0))
    core_code = id_to_corecode62(cache_id)

    guid = basic.get("adventureGuid", "")
    ALname = basic.get("title", "")
    ALnameHtml = prepare_html_field(ALname)

    visibility = basic.get("visibility", "")
    is_archived = str(basic.get("isArchived", False))

    owner_id = str(basic.get("ownerGeoAccountId", ""))
    owner_plain = basic.get("ownerUsername", "")
    owner_html = prepare_html_field(owner_plain)

    fav = basic.get("ratingsAverage", 0)
    users = basic.get("ratingsTotalCount", 0)

    created = basic.get("createdUtc", "")[:10]
    published = basic.get("publishedUtc", "")[:10]
    duration = basic.get("medianTimeToComplete", "")

    long_raw = basic.get("description", "")
    long_html = prepare_html_field(make_gc_links(long_raw))

    lat = basic.get("location", {}).get("latitude", "")
    lon = basic.get("location", {}).get("longitude", "")

    if isinstance(lat, float): lat = f"{lat:.7f}"
    if isinstance(lon, float): lon = f"{lon:.12f}"

    image = basic.get("keyImageUrl", "")
    cache_type = "A"
    adventure_type = basic.get("adventureType", "")

    themes = basic.get("adventureThemes")
    theme = themes[0] if isinstance(themes, list) and len(themes) > 0 else ""
    theme_suffix = f" - {theme} themed" if theme else " - No theme"
    
    seq_name = "Random" if adventure_type.lower() == "nonsequential" else "Linear"
    sequence_text = f"Sequence: {seq_name}{theme_suffix}"

    if adventure_type.lower() == "nonsequential":
        has_corrected, dnf = "True", "False"
    else:
        has_corrected, dnf = "False", "True"

    stages = basic.get("stages", [])
    stages_count = len(stages)

    gc_note = f"Ave: {fav} rating from {users} Users for {stages_count} Lab Stages"
    gc_note_with_duration = f"{gc_note}. Duration about {duration} mins" if duration else gc_note

    ALshort = f'<h2 style="text-align: center">{ALnameHtml}<br />by<br />{owner_html}</h2>'

    ALsplit = (
        "<div style='font-family:verdana,arial,sans-serif'>"
        "<div style='box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, "
        "rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, "
        "rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;"
        "padding:1em;margin:2em 0;'>"
        "<img src='https://labs.geocaching.com/Content/images/al-logo-balloon.svg' "
        "align='absmiddle' alt='Adventure' title='Adventure' style='float:right;width:6em;'/>"
        f"<p><span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>{gc_note}</span> "
    )
    if duration:
        ALsplit += f"<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>Duration about {duration} mins</span>&nbsp;&nbsp;"
    ALsplit += f"<span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>{sequence_text}</span></p>"
    ALsplit += f"<p>{long_html}</p></div>"
    ALsplit += f"<p style='text-align: center;'>"
    ALsplit += f"<img src='{image}' style='max-width:80%; max-height:80%; border:6px solid white; border-radius:20px; box-shadow:5px 5px 7px rgba(0,0,0,0.5); '/></p></div>"

    parent_row = [
        core_code, str(cache_id), guid, ALname, ALnameHtml, visibility, is_archived,
        owner_id, owner_plain, owner_html, str(fav), str(users), created, published,
        str(duration), long_html, lat, lon, image, cache_type, theme, adventure_type,
        sequence_text, has_corrected, dnf, str(stages_count), gc_note_with_duration,
        ALshort, ALsplit
    ]

    output = "||".join(parent_row)

    # ---------------- STAGE LOOP ----------------
    lab = 0
    for stage in stages:
        lab += 1
        suf = str(lab).zfill(2)

        guid = stage.get("id", "")
        stage_title_raw = stage.get("title", "")

        LBname = f"{ALname} : S{lab} {stage_title_raw}"
        LBnameHtml = prepare_html_field(f"{ALnameHtml} : {stage_title_raw}")

        slat = stage.get("location", {}).get("latitude", "")
        slon = stage.get("location", {}).get("longitude", "")
        if isinstance(slat, float): slat = f"{slat:.12f}".rstrip("0").rstrip(".")
        if isinstance(slon, float): slon = f"{slon:.12f}".rstrip("0").rstrip(".")

        if isinstance(stage.get("keyImage"), dict):
            LBimage = stage["keyImage"].get("url", "")
        else:
            LBimage = stage.get("keyImageUrl", "")

        geofence = stage.get("geofencingRadius", "")
        if isinstance(geofence, float): geofence = int(geofence)

        challengeType = (stage.get("challengeType") or "")
        qa = stage.get("questionToAnswer", {})
        q = qa.get("question", "")
        questionHtml = prepare_html_field(make_gc_links(q))

        LBdescriptionHtml = prepare_html_field(make_gc_links(stage.get("description", "")))
        LBshortDescriptionHtml = f'<h2 style="text-align: center">{ALnameHtml} : {stage_title_raw}</h2>'
        if q:
            LBshortDescriptionHtml += f'<p style="text-align: center"><b>Question</b>: {questionHtml}</p>'

        LBsplitScreen = (
            "<div style='font-family:verdana,arial,sans-serif'>"
            "<div style='box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, "
            "rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, "
            "rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;"
            "padding:1em;margin:2em 0;'>"
            "<img src='file:///C:/PROGRA~2/gsak/images/cacheQ.gif' "
            "align='absmiddle' alt='Lab Stage' title='Lab Stage' style='float:right;'/>"
            f"<p><span style='background:#060;color:#fff;border-radius:5px;padding:3px 5px;'>Stage {lab} of {stages_count}</span>"
            f"&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px 5px;padding:3px 5px;'>Sequence: Linear</span>"
            f"&nbsp;&nbsp;<span style='background:#060;color:#fff;border-radius:5px 5px;padding:3px 5px;'>Geofence: {geofence} metres</span></p>"
            f"<div>{LBdescriptionHtml}</div></div>"
            f"<p style='text-align: center;'><img src='{LBimage}' style='max-width:80%; max-height:80%; border:6px solid white; border-radius:20px; box-shadow:5px 5px 7px rgba(0,0,0,0.5);'/></p>"
            f"{ALshort}{ALsplit}</div>"
        )

        stage_row = [
            str(lab), suf, str(guid), LBname, LBnameHtml, str(slat), str(slon),
            LBimage, str(geofence), challengeType, q, LBdescriptionHtml,
            LBshortDescriptionHtml, LBdescriptionHtml, "False", "False", "", LBsplitScreen
        ]

        output += "|@|" + "||".join(stage_row)

    # ---------------- CP1252 OUTPUT ----------------
    try:
        cp1252_bytes = output.encode('cp1252', errors='replace')
    except Exception:
        cp1252_bytes = output.encode('utf-8')

    # ---------------- SEND ----------------
    handler.send_response(200)
    handler.send_header("Content-Type", "text/plain; charset=Windows-1252")
    handler.send_header("Content-Transfer-Encoding", "binary")
    handler.send_header("Content-Length", str(len(cp1252_bytes)))
    handler.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
    handler.send_header("Pragma", "no-cache")
    handler.send_header("Expires", "0")
    handler.end_headers()

    try:
        handler.wfile.write(cp1252_bytes)
    except (ConnectionAbortedError, BrokenPipeError):
        pass