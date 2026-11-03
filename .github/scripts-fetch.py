import re, sys, os, urllib.request, urllib.parse, imghdr
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15"

def get(url, timeout=20):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept-Language": "da,en"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read(), r.geturl()

def ext_of(b):
    if b[:8] == b"\x89PNG\r\n\x1a\n": return "png"
    if b[:3] == b"\xff\xd8\xff": return "jpg"
    if b[:4] == b"RIFF" and b[8:12] == b"WEBP": return "webp"
    if b[:4] in (b"\x00\x00\x01\x00",): return "ico"
    if b[:6] in (b"GIF87a", b"GIF89a"): return "gif"
    if b"<svg" in b[:500]: return "svg"
    return None

def save(b, folder, name):
    e = ext_of(b)
    if not e: return None
    os.makedirs(folder, exist_ok=True)
    for f in os.listdir(folder):
        if f.rsplit(".", 1)[0] == name: os.remove(os.path.join(folder, f))
    path = f"{folder}/{name}.{e}"
    open(path, "wb").write(b)
    return path

try:
    b, _ = get("https://avatars.githubusercontent.com/u/118354717?v=4&s=512")
    print("AVATAR", save(b, "assets/brand", "avatar"))
except Exception as ex:
    print("AVATAR FEJL", ex)

domains = sys.argv[1:]
for d in domains:
    found = None
    cands = []
    try:
        html, final = get("https://" + d)
        html = html.decode("utf-8", "ignore")
        for tag in re.findall(r"<link[^>]+>", html, re.I):
            rel = re.search(r'rel=["\']([^"\']+)', tag, re.I)
            href = re.search(r'href=["\']([^"\']+)', tag, re.I)
            if not rel or not href or "icon" not in rel.group(1).lower(): continue
            size = re.search(r'sizes=["\'](\d+)', tag, re.I)
            s = int(size.group(1)) if size else (180 if "apple" in rel.group(1).lower() else 32)
            if href.group(1).lower().endswith(".svg"): s = 999
            cands.append((s, urllib.parse.urljoin(final, href.group(1).replace("&amp;", "&"))))
        og = re.search(r'<meta[^>]+property=["\']og:logo["\'][^>]+content=["\']([^"\']+)', html, re.I)
        if og: cands.append((500, urllib.parse.urljoin(final, og.group(1))))
    except Exception as ex:
        print("SIDE FEJL", d, ex)
    cands.sort(reverse=True)
    cands.append((0, f"https://www.google.com/s2/favicons?domain={d}&sz=256"))
    for s, u in cands:
        try:
            b, _ = get(u)
            if ext_of(b) and len(b) > 300:
                found = save(b, "assets/logos", d)
                if found: break
        except Exception:
            pass
    print("LOGO", d, found)
