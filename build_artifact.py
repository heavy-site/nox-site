"""Bundle the three-page site into one self-contained HTML for review.

The artifact sandbox allows no local files, so the stylesheet, scripts, floor
plan and logo are all inlined, the API is stubbed with the
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


def data_uri_jpeg(path):
    raw = open(os.path.join(ROOT, *path.split("/")), "rb").read()
    return "data:image/jpeg;base64," + base64.b64encode(raw).decode("ascii")


def body_of(page):
    html = read(page)
    m = re.search(r"(<main>.*?</main>)", html, re.S)
    return m.group(1)


# ── payload identical in shape to api/site.php ─────────────────────────
# The poster travels inside the page — the sandbox serves no local files. Only
# the 720px cut goes, and only once: the payload carries no posterSmall, so the
# same base64 is not repeated for a second srcset candidate.
EVENTS = [{
    "id": "insane-rave", "title": "Insane Rave", "promoter": "HEAVY",
    "date": "2026-08-29", "dateEnd": "2026-08-30",
    "dateText": "29–30 серпня", "year": "2026", "time": "",
    "tickets": "https://he4vy.com/tickets",
    "lineup": "Mr.bilich, kaplini, MRX, mad cult, secret guest",
    "poster": data_uri_jpeg("assets/insane-poster-720.jpg"),
}, {
    "id": "dolls-rave", "title": "DOLLS RAVE", "promoter": "", "genre": "Hard Techno",
    "date": "2026-10-17", "dateEnd": "",
    "dateText": "17 жовтня", "year": "2026", "time": "16:00–22:00",
    "tickets": "", "post": "https://www.instagram.com/p/DeHTbgUMODh/",
    "lineup": "NORDEIL × VITALI TASH × FOAVAS × D3ADW1RE × ATRK × PTERODACTYL × NIKOLIETTA × 2SIDE × TSURA × SVZST NPS × PUSSY KILLER",
    "poster": data_uri_jpeg("assets/dolls-poster-720.jpg"),
}, {
    "id": "heavy-oct", "title": "HEAVY", "promoter": "", "genre": "Heavy EDM",
    "date": "2026-10-24", "dateEnd": "",
    "dateText": "24 жовтня", "year": "2026", "time": "18:00–22:00",
    "tickets": "https://he4vy.com/tickets", "post": "https://www.instagram.com/reel/DeCShr_szA7/",
    "lineup": "18:00–20:00 Mad Cult b2b Artem\n20:00–21:00 Toxic Killer\n21:00–22:00 Smolyakov",
    "poster": data_uri_jpeg("assets/heavy-poster-720.jpg"),
}, {
    "id": "mysterium-2", "title": "Mysterium · Episode II", "promoter": "",
    "genre": "Hardbass, Breakcore, Neotrance, Hard Trance, Melodic Hard Techno",
    "date": "2026-10-31", "dateEnd": "2026-11-01",
    "dateText": "31 жовтня – 1 листопада", "year": "2026", "time": "16:00–22:00",
    "tickets": "https://asura.company/b/bcae524ae6",
    "post": "https://www.instagram.com/p/Dd9NEhOqDfQ/",
    "lineup": "День 1: ASURA NBLCK, QKI, MILLAREN, SVZHST nps, 1240+, AUDIOVOVA\n"
              "День 2: ASURA NBLCK, SKY MAVKA, VERARTUM, TEMP3R, DINASTIA",
    "poster": data_uri_jpeg("assets/mysterium-poster-720.jpg"),
}]
today = datetime.date.today().isoformat()
PAYLOAD = {
    "outside": [
        {"value": "28 м²", "label": "тераса, 4 × 7 м"},
        {"value": "≈ 50", "label": "гостей на терасі"},
        {"value": "203 м²", "label": "парковка, 14 × 14,5 м"},
        {"value": "≈ 8", "label": "авто на парковці"},
    ],
    "headline": [
        {"value": "215 м²", "label": "зал"},
        {"value": "300–350", "label": "гостей"},
        {"value": "4,1 м", "label": "барна стійка"},
        {"value": "84,1 м²", "label": "танцпол, окреме приміщення"},
    ],
    "rent": {
        "included": [
            {"title": "Зал 215 м²", "note": "18,00 × 12,00 м, шість колон по периметру танцполу"},
            {"title": "Бар 4,1 м", "note": "три секції фронту, робоча лінія за стійкою, холодильники"},
            {"title": "Гардероб", "note": "окрема зона біля входу"},
            {"title": "Санвузол на 6 кабін", "note": "два умивальники, чотири пісуари"},
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
    "upcoming": sorted([e for e in EVENTS if (e["dateEnd"] or e["date"]) >= today], key=lambda e: e["date"]),
    "past": sorted([e for e in EVENTS if (e["dateEnd"] or e["date"]) < today], key=lambda e: e["date"], reverse=True),
    "busy": ["2026-10-16", "2026-10-24", "2026-10-31"],
}

# ── assets ─────────────────────────────────────────────────────────────
css = read("assets", "site.css")
fx = read("assets", "fx.js")
site = read("assets", "site.js")
i18n = read("assets", "i18n.js")

plan_uri = data_uri_svg("assets/plan.svg")
plan_en_uri = data_uri_svg("assets/plan-en.svg")
logo_uri = data_uri_svg("assets/logo-mark.svg")
logo_inline_uri = data_uri_svg("assets/logo.svg")

fx = fx.replace('"/assets/logo-mark.svg"', json.dumps(logo_uri))


# Both drawings of the room travel inside the bundle: the plan carries its own
# words, so English has a file of its own.
def inline_plans(js):
    for path, uri in (("/assets/plan-en.svg", plan_en_uri), ("/assets/plan.svg", plan_uri)):
        for quoted in ('"%s"' % path, "'%s'" % path):
            js = js.replace(quoted, json.dumps(uri))
    return js


fx = inline_plans(fx)
site = inline_plans(site)

# The painter writes its own links — the empty-state "Забронювати дату" button
# among them. Rewriting only the pages left those pointing at /booking, which
# is nothing at all inside a hash-routed bundle.
site = site.replace('href="/booking"', 'href="#/booking"')
# A poster card of a past night opens the listing; its address is a string in
# the script, not an href, so it is rewritten on its own.
site = site.replace(': "/events";', ': "#/events";')

# ── pages ──────────────────────────────────────────────────────────────
pages = {"home": body_of("index.html"), "events": body_of("events.html"), "booking": body_of("booking.html")}
for key, html in pages.items():
    html = html.replace('src="/assets/logo.svg"', 'src="%s"' % logo_inline_uri)
    # The sandbox loads no third-party frames, so the map says where it is
    # rather than sitting there as an empty rectangle.
    html = re.sub(r"<iframe class=\"mapframe\".*?</iframe>",
                  '<p class="mapnote">Мапа працює на самому сайті —<br>у цьому превʼю сторонні фрейми не вантажаться.</p>',
                  html, flags=re.S)
    html = html.replace('href="/booking"', 'href="#/booking"').replace('href="/events"', 'href="#/events"')
    html = html.replace('href="/"', 'href="#/"')
    pages[key] = html

nav = """
<div class="bar" id="bar">
  <a class="wm" href="#/"><img src="__LOGO__" alt="" width="26" height="26"><span>nøx</span></a>
  <nav>
    <a href="#/" data-route="home" data-i18n="nav.home">Головна</a>
    <a href="#/events" data-route="events" data-i18n="nav.events">Події</a>
    <a class="cta" href="#/booking" data-route="booking" data-i18n="nav.booking">Організаторам</a>
  </nav>
  <div class="lang" role="group" aria-label="Мова" data-i18n-aria="lang.label">
    <button type="button" data-lang="uk" aria-pressed="true">укр</button>
    <button type="button" data-lang="en" aria-pressed="false">eng</button>
  </div>
</div>
""".replace("__LOGO__", logo_inline_uri)

router = """
<script>
(function(){
  var pages = ["home","events","booking"];
  /* One scrollTo is not enough on a route change. The preview runs inside the
     artifact shell, and the shell puts the scroll position back on its own —
     it remembers it in sessionStorage and restores it whenever the frame is
     resized or promoted, which is exactly what a route change causes. So the
     route that opened was the old page's position, seen through a shorter
     page. We hold the top for a short while instead, the same way fx.js holds
     it on load, and let go the instant the reader touches anything so a real
     gesture is never fought. The shell's own note of the position is zeroed
     too, so a restore that lands after the hold lands at the top. */
  function holdTop(){
    try { sessionStorage.setItem("__frame_scroll", JSON.stringify({ y: 0 })); } catch (e) {}
    var loose = false;
    function release(){ loose = true; }
    ["wheel","touchstart","keydown","pointerdown"].forEach(function(t){
      addEventListener(t, release, { passive: true, once: true });
    });
    var until = Date.now() + 1200;
    (function hold(){
      if (scrollY !== 0) scrollTo(0,0);
      if (!loose && Date.now() < until) requestAnimationFrame(hold);
      else ["wheel","touchstart","keydown","pointerdown"].forEach(function(t){
        removeEventListener(t, release);
      });
    })();
  }

  /* The route lives here, not only in the address. The app's viewer shows
     the preview inside an embedded frame, where a link to "#/booking"
     resolves against the viewer's own address and goes nowhere — so a click
     on any route link is taken over and the page is switched in place. The
     address follows when it can, and the hash still works where it does. */
  var current = null;
  function fromHash(){
    var h = "";
    try { h = location.hash.replace(/^#\\/?/, ""); } catch (e) {}
    return pages.indexOf(h) >= 0 ? h : (h === "" ? "home" : null);
  }
  document.addEventListener("click", function(e){
    var a = e.target.closest && e.target.closest('a[href^="#/"], a[href="#/"]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var h = a.getAttribute("href").replace(/^#\\/?/, "") || "home";
    if (pages.indexOf(h) < 0) return;
    e.preventDefault();
    current = h;
    try { history.replaceState(null, "", "#/" + (h === "home" ? "" : h)); } catch (err) {}
    show();
  });

  function show(){
    var h = current || fromHash() || "home";
    if (pages.indexOf(h) < 0) h = "home";
    pages.forEach(function(p){
      var el = document.getElementById("page-" + p);
      if (!el) return;
      el.hidden = (p !== h);
      // A route the reader comes back to opens at its own top, not where it
      // was left — the same as following a link on the site itself.
      if (el.hidden) return;
      el.scrollTop = 0;
      // The side routes scroll inside themselves, and a keyboard scrolls what
      // has focus — so the route that opens takes it. preventScroll, because
      // taking focus must not undo the top we just set.
      el.tabIndex = -1;
      try { el.focus({ preventScroll: true }); } catch (e) {}
    });
    document.querySelectorAll(".bar nav a[data-route]").forEach(function(a){
      if (a.dataset.route === h) a.setAttribute("aria-current","page");
      else a.removeAttribute("aria-current");
    });
    document.getElementById("bar").classList.toggle("stuck", h !== "home");
    holdTop();
    document.dispatchEvent(new CustomEvent("nox:route", { detail: h }));
  }
  addEventListener("hashchange", function(){ var h = fromHash(); if (h) { current = h; show(); } });
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
out = """<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>nøx — сайт майданчика</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400&family=Manrope:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
%s
.page[hidden]{ display:none !important; }

/* Усі три сторінки живуть в одному документі, тож смуга прокрутки в них одна:
   з прокрученої головної читач потрапляв у середину афіші, і ні scrollTo, ні
   пам'ять оболонки тут ні до чого — це та сама прокрутка. Бічні сторінки
   дістають власну. Поки відкрита котрась із них, документ рівно на висоту
   екрана: прокручувати в ньому нічого, переносити нічого, а оболонці нічого
   відновлювати. Головна лишається на прокрутці документа — на ній тримаються
   і відкриття зі знаком, і хвиля в лівому полі. */
#page-events:not([hidden]),
#page-booking:not([hidden]){
  height:100svh; overflow-y:auto; -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
}
</style>

<canvas id="fx" aria-hidden="true"></canvas>
<canvas id="wave" aria-hidden="true"></canvas>
<div class="film" aria-hidden="true"></div>
<div class="burn" aria-hidden="true"></div>
<div class="frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>

%s

<div class="page" id="page-home">%s</div>
<div class="page" id="page-events" data-nox-scroller hidden>%s</div>
<div class="page" id="page-booking" data-nox-scroller hidden>%s</div>

%s
%s
<script>%s</script>
<script>%s</script>
<script>%s</script>
""" % (css, nav, pages["home"], pages["events"], pages["booking"], stub, router, i18n, fx, site)

os.makedirs(OUT_DIR, exist_ok=True)
path = os.path.join(OUT_DIR, "nox-site.html")
io.open(path, "w", encoding="utf-8", newline="\n").write(out)
print("written:", path, "%.2f MB" % (len(out.encode("utf-8")) / 1048576))
