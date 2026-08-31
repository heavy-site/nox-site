"""Local dev server. Serves the real files and mimics what .htaccess and the PHP
endpoints do on the host, so the site can be checked on a machine without PHP.

    python devserver.py [port]     ->  http://127.0.0.1:8099/

Nothing here ships: it is gitignored and never deployed.
"""
import datetime, http.server, json, os, socketserver, sys, urllib.parse

# The Windows console is cp1252; Cyrillic in a log line must not kill a request.
for _s in (sys.stdout, sys.stderr):
    try:
        _s.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

ROOT = os.path.dirname(os.path.abspath(__file__))
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8099

ROUTES = {"/": "/index.html", "/events": "/events.html", "/booking": "/booking.html"}

EVENTS = [{
    "id": "insane-rave", "title": "Insane Rave", "promoter": "HEAVY",
    "date": "2026-08-29", "dateEnd": "2026-08-30",
    "dateText": "29–30 серпня", "year": "2026", "time": "",
    "tickets": "https://he4vy.com/tickets",
}]

MEDIA_EXT = (".jpg", ".jpeg", ".png", ".webp", ".avif")


def media():
    """Mirror of nox_media() in api/_venue.php."""
    d = os.path.join(ROOT, "media")
    if not os.path.isdir(d):
        return []
    out = []
    for name in sorted(os.listdir(d)):
        if not name.lower().endswith(MEDIA_EXT):
            continue
        base = os.path.splitext(name)[0]
        cap = os.path.join(d, base + ".txt")
        out.append({
            "src": "/media/" + urllib.parse.quote(name),
            "caption": open(cap, encoding="utf-8").read().strip() if os.path.exists(cap) else "",
            "alt": "nøx — " + base.lstrip("0123456789-_").replace("-", " ").replace("_", " "),
        })
    return out


def payload():
    today = datetime.date.today().isoformat()
    return {
        "venue": {"address": "Нижньоюрківська 31, Київ"},
        "headline": [
            {"value": "215 м²", "label": "зал"},
            {"value": "300–350", "label": "гостей"},
            {"value": "9,6 м", "label": "барна стійка"},
            {"value": "18 × 12", "label": "метрів, без колон по центру"},
        ],
        "rent": {
            "included": [
                {"title": "Зал 215 м²", "note": "18,00 × 12,00 м, шість колон по периметру танцполу"},
                {"title": "Бар 9,6 м", "note": "три секції фронту, робоча лінія за стійкою, холодильники"},
                {"title": "Гардероб", "note": "окрема зона біля входу"},
                {"title": "Санвузол на 7 кабін", "note": "умивальники, пісуари"},
                {"title": "Тераса", "note": "вихід просто із залу"},
                {"title": "Парковка", "note": "своя, біля входу"},
            ],
            "arranged": [
                {"title": "Звук і світло", "note": "привозите своє або орендуємо — підкажемо, з ким працюємо"},
                {"title": "Бармени", "note": "наша команда, кількість — під ваш прогноз"},
                {"title": "Охорона", "note": "на вході й у залі"},
            ],
        },
        "media": media(),
        "upcoming": sorted([e for e in EVENTS if (e["dateEnd"] or e["date"]) >= today], key=lambda e: e["date"]),
        "past": sorted([e for e in EVENTS if (e["dateEnd"] or e["date"]) < today], key=lambda e: e["date"], reverse=True),
    }


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **kw):
        super().__init__(*a, directory=ROOT, **kw)

    def _json(self, obj, code=200):
        body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        if path.rstrip("/") == "/api/site" or path == "/api/site":
            return self._json(payload())
        if path in ROUTES:
            self.path = ROUTES[path]
        elif path.rstrip("/") in ROUTES:
            self.path = ROUTES[path.rstrip("/")]
        return super().do_GET()

    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        if path.rstrip("/") == "/api/rent":
            length = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(length).decode("utf-8", "replace")
            try:
                body = json.loads(raw)
            except Exception:
                return self._json({"error": "Некоректний запит"}, 400)
            if body.get("website"):
                return self._json({"ok": True})
            for f in ("name", "contact", "date"):
                if not str(body.get(f, "")).strip():
                    return self._json({"error": "Заповніть обовʼязкові поля"}, 400)
            print("  [rent]", {k: body.get(k) for k in ("name", "contact", "date", "guests")})
            return self._json({"ok": True})
        self.send_error(405)

    def end_headers(self):
        if self.path.endswith(".html") or self.path in ROUTES.values():
            self.send_header("Cache-Control", "no-cache, must-revalidate")
        super().end_headers()

    def log_message(self, fmt, *args):
        sys.stderr.write("  %s\n" % (fmt % args))


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    with Server(("127.0.0.1", PORT), Handler) as httpd:
        print("nox-site dev server -> http://127.0.0.1:%d/" % PORT)
        print("  /  /events  /booking   ·   /api/site  /api/rent")
        httpd.serve_forever()
