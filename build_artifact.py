"""Bundle the three-page site into one self-contained HTML for review.

The artifact sandbox allows no local files, so the stylesheet, scripts, floor
plan, logo and the Yulong face are all inlined, the API is stubbed with the
same data api/site.php returns, and the three pages become hash routes.

    python build_artifact.py   ->  artifact/nox-site.html   (gitignored)
"""
import base64, io, json, os, re, datetime

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(ROOT, "artifact")


def read(*parts):
    return io.open(os.path.join(ROOT, *parts), encoding="utf-8").read()


def data_uri_svg(path):
    raw = read(*path.split("/"))
    return "data:image/svg+xml;base64," + base64.b64encode(raw.encode("utf-8")).decode("ascii")


def body_of(page):
    html = read(page)
    # everything between <main> and the closing </footer>
    m = re.search(r"(<main>.*?</footer>)", html, re.S)
    return m.group(1)


# ── payload identical in shape to api/site.php ─────────────────────────
EVENTS = [{
    "id": "insane-rave", "title": "Insane Rave", "promoter": "HEAVY",
    "date": "2026-08-29", "dateEnd": "2026-08-30",
    "dateText": "29–30 серпня", "year": "2026", "time": "",
    "tickets": "https://he4vy.com/tickets",
}]
today = datetime.date.today().isoformat()
PAYLOAD = {
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
    "media": [],
    "upcoming": [e for e in EVENTS if (e["dateEnd"] or e["date"]) >= today],
    "past": [e for e in EVENTS if (e["dateEnd"] or e["date"]) < today],
}

# ── assets ─────────────────────────────────────────────────────────────
css = read("assets", "site.css")
fx = read("assets", "fx.js")
site = read("assets", "site.js")

font_b64 = base64.b64encode(open(os.path.join(ROOT, "fonts", "Yulong-Regular.ttf"), "rb").read()).decode("ascii")
css = css.replace('src:url("/fonts/Yulong-Regular.ttf") format("truetype");',
                  'src:url(data:font/ttf;base64,%s) format("truetype");' % font_b64)

plan_uri = data_uri_svg("assets/plan.svg")
logo_uri = data_uri_svg("assets/logo-mark.svg")
logo_inline_uri = data_uri_svg("assets/logo.svg")

fx = fx.replace('"/assets/logo-mark.svg"', json.dumps(logo_uri)).replace('"/assets/plan.svg"', json.dumps(plan_uri))
site = site.replace("'/assets/plan.svg'", json.dumps(plan_uri)).replace('"/assets/plan.svg"', json.dumps(plan_uri))

# ── pages ──────────────────────────────────────────────────────────────
pages = {"home": body_of("index.html"), "events": body_of("events.html"), "booking": body_of("booking.html")}
for key, html in pages.items():
    html = html.replace('src="/assets/logo.svg"', 'src="%s"' % logo_inline_uri)
    html = html.replace('href="/booking"', 'href="#/booking"').replace('href="/events"', 'href="#/events"')
    html = html.replace('href="/"', 'href="#/"')
    pages[key] = html

nav = """
<div class="bar" id="bar">
  <a class="wm" href="#/"><img src="__LOGO__" alt="" width="26" height="26"><span>nøx</span></a>
  <nav>
    <a href="#/" data-route="home">Головна</a>
    <a href="#/events" data-route="events">Афіша</a>
    <a href="#/booking" data-route="booking">Оренда</a>
    <a class="cta" href="#/booking">Забронювати</a>
  </nav>
</div>
""".replace("__LOGO__", logo_inline_uri)

router = """
<script>
(function(){
  var pages = ["home","events","booking"];
  function show(){
    var h = location.hash.replace(/^#\\/?/, "") || "home";
    if (pages.indexOf(h) < 0) h = "home";
    pages.forEach(function(p){
      var el = document.getElementById("page-" + p);
      if (el) el.hidden = (p !== h);
    });
    document.querySelectorAll(".bar nav a[data-route]").forEach(function(a){
      if (a.dataset.route === h) a.setAttribute("aria-current","page");
      else a.removeAttribute("aria-current");
    });
    document.getElementById("bar").classList.toggle("stuck", h !== "home");
    scrollTo(0,0);
    document.dispatchEvent(new CustomEvent("nox:route", { detail: h }));
  }
  addEventListener("hashchange", show);
  document.addEventListener("DOMContentLoaded", show);
  if (document.readyState !== "loading") show();
})();
</script>
"""

# The bundled JS paints every route at once, so each page's ids are filled.
stub = """
<script>
window.__NOX__ = %s;
window.fetch = function(url, opts){
  var u = String(url);
  if (u.indexOf("/api/site") >= 0)
    return Promise.resolve(new Response(JSON.stringify(window.__NOX__), {status:200, headers:{"Content-Type":"application/json"}}));
  if (u.indexOf("/api/rent") >= 0)
    return Promise.resolve(new Response(JSON.stringify({ok:true}), {status:200, headers:{"Content-Type":"application/json"}}));
  return Promise.reject(new Error("blocked in preview"));
};
</script>
""" % json.dumps(PAYLOAD, ensure_ascii=False)

# site.js and fx.js address elements by bare id; in the bundle each id is
# suffixed per route, so run them once per page with a scoped lookup.
out = """<title>nøx — сайт майданчика</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400&family=Manrope:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
%s
.page[hidden]{ display:none !important; }
</style>

<canvas id="fx" aria-hidden="true"></canvas>
<div class="film" aria-hidden="true"></div>
<div class="burn" aria-hidden="true"></div>
<div class="frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>

%s

<div class="page" id="page-home">%s</div>
<div class="page" id="page-events" hidden>%s</div>
<div class="page" id="page-booking" hidden>%s</div>

%s
%s
<script>%s</script>
<script>%s</script>
""" % (css, nav, pages["home"], pages["events"], pages["booking"], stub, router, fx, site)

os.makedirs(OUT_DIR, exist_ok=True)
path = os.path.join(OUT_DIR, "nox-site.html")
io.open(path, "w", encoding="utf-8", newline="\n").write(out)
print("written:", path, "%.2f MB" % (len(out.encode("utf-8")) / 1048576))
