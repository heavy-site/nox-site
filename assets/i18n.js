/* nøx — дві мови.
   Українська живе просто в розмітці: без цього скрипта, без JS узагалі,
   сторінка лишається українською й цілою. Англійська приходить словником —
   ключ у `data-i18n`, а український текст поруч, у самому елементі, як
   запасний. Тому забутий ключ ніколи не лишає порожнього місця: гість
   побачить українське слово, а не діру. */
(function () {
  "use strict";

  var KEY = "nox.lang";

  var DICT = {
    "nav.home": "Home",
    "nav.events": "Events",
    "nav.booking": "Book",

    "hero.welcome": "Welcome to the club of a new era <b>nøx</b>",
    "hero.scroll": "Scroll",

    "home.s1.mark": "I · The space",
    "home.s1.h2": "The hall",
    "home.s2.mark": "II · Calendar",
    "home.s2.h2": "The next night",
    "home.t1.b": "Upcoming<br>nights",
    "home.t2.b": "Book<br>a date",
    "home.s3.mark": "III · Guests",
    "home.s3.h2": "Getting here",
    "home.where.b": "Where it is",
    "home.where.text": "Nyzhnoiurkivska 31, right at the start of the street.",
    "home.map.title": "Map: Nyzhnoiurkivska 31, Kyiv",
    "home.map.route": "Get directions →",

    "events.mark": "Calendar",
    "events.h1": "Events",
    "events.next.mark": "The next night",
    "events.later.mark": "Later",

    "booking.h1": "Rental",
    "booking.price.mark": "Price",
    "booking.price.h2": "What is included",
    "booking.price.sub": "base rate for the venue",
    "booking.price.i1.b": "The hall with our bar",
    "booking.price.i1.s": "The bar at the venue is ours.",
    "booking.price.i2.b": "Face control and security",
    "booking.price.i2.s": "Our people at the door and in the hall.",
    "booking.price.i3.b": "Our technician",
    "booking.price.i3.s": "He runs the light as well. You can add your own technician, or put yours in his place.",
    "booking.price.i4.b": "Basic sound and light",
    "booking.price.i4.s": "The hall's own equipment is part of the rate.",
    "booking.price.fixed": "fixed position",
    "booking.price.can": "can be added to or replaced",
    "booking.form.mark": "Enquiry",

    "f.name": "Who you are",
    "f.name.ph": "Name, or the name of the crew",
    "f.contact": "Contact",
    "f.contact.ph": "Phone or email",
    "f.tg": "Telegram for replies",
    "f.tg.ph": "@handle or t.me/…",
    "f.date": "Date",
    "f.time": "Hours",
    "f.time.from": "Start",
    "f.time.to": "End",
    "f.guests": "Guests expected",
    "f.artists": "How many artists",
    "f.music": "Music",
    "f.music.electronic": "Electronic",
    "f.music.live": "Live",
    "f.event": "What the night is",
    "f.event.ph": "Title, format, music",
    "f.social": "Links for the event",
    "f.social.ph": "instagram.com/… , t.me/… or the event page",
    "f.comment": "What you need from us",
    "f.comment.ph": "Sound, light, bartenders, timing",
    "f.hp": "Leave this empty",
    "f.submit": "Send",

    "js.tickets": "Tickets",
    "js.next.free": "The coming months are still open",
    "js.none.upcoming": "The next nights will show up here. The dates are still open.",
    "js.none.later": "Nothing further yet — the dates are open.",
    "js.none.cta": "Book a date",
    "js.sending": "Sending…",
    "js.sent": "Enquiry received. We will come back to you shortly.",
    "js.failed": "Could not send it. Please try again.",
    "js.offline": "No connection. Try again, or call us.",
    "js.plan.alt": "Floor plan of nøx",
    "js.plan.aria": "Floor plan of nøx: the dance floor with its columns, a 9.6 m bar counter, restrooms, cloakroom",
    "js.poster.alt": "poster",

    "lang.label": "Language"
  };

  /* Підписи й одиниці приходять із бекенда українською — тут вони стають
     англійськими. Чого немає у списку, лишається як є. */
  var TERMS = {
    "зал": "hall",
    "гостей": "guests",
    "барна стійка": "bar counter",
    "танцпол, окреме приміщення": "dance floor, a room of its own"
  };

  var MONTHS = {
    "січня": "January",
    "лютого": "February",
    "березня": "March",
    "квітня": "April",
    "травня": "May",
    "червня": "June",
    "липня": "July",
    "серпня": "August",
    "вересня": "September",
    "жовтня": "October",
    "листопада": "November",
    "грудня": "December"
  };

  var DAYS = {
    uk: ["неділя", "понеділок",
         "вівторок", "середа",
         "четвер", "пʼятниця",
         "субота"],
    en: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"]
  };

  var lang = "uk";
  try {
    var saved = localStorage.getItem(KEY);
    if (saved === "en" || saved === "uk") lang = saved;
  } catch (e) {}
  // A shared link can carry the language: /events?lang=en
  var q = /[?&]lang=(uk|en)\b/.exec(location.search);
  if (q) lang = q[1];

  function t(key, uk) {
    if (lang === "uk") return uk;
    var v = DICT[key];
    return v == null ? uk : v;
  }

  // "9,6 м" -> "9.6 m", "215 м²" -> "215 m²". The figures arrive from the venue
  // file as one string; only the unit and the decimal comma are language.
  function term(v) {
    if (lang === "uk" || v == null) return v;
    var s = String(v);
    if (TERMS[s]) return TERMS[s];
    return s.replace(/(\d),(\d)/g, "$1.$2")
            .replace(/м²/g, "m²")
            .replace(/(\d)\s*м(?![а-яіїєґ])/g, "$1 m");
  }

  // "29–30 серпня" -> "29–30 August"
  function dateText(v) {
    if (lang === "uk" || !v) return v;
    return String(v).replace(/[а-яіїєґ]+/gi, function (w) {
      return MONTHS[w.toLowerCase()] || w;
    });
  }

  // "2026-08-29" -> "субота" / "Saturday". Empty for anything that is not a date.
  function weekday(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ""));
    if (!m) return "";
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return DAYS[lang][d.getUTCDay()];
  }

  // Attribute translations: data-i18n-placeholder="f.name.ph" and friends. The
  // Ukrainian that was in the attribute is kept beside it as the fallback.
  var ATTRS = [
    ["i18nPlaceholder", "data-i18n-placeholder", "placeholder", "ukPlaceholder"],
    ["i18nAria", "data-i18n-aria", "aria-label", "ukAria"],
    ["i18nTitle", "data-i18n-title", "title", "ukTitle"],
    ["i18nAlt", "data-i18n-alt", "alt", "ukAlt"]
  ];

  function apply() {
    document.documentElement.lang = lang;

    document.querySelectorAll("[data-i18n]").forEach(function (el) {
      if (el.dataset.uk == null) el.dataset.uk = el.innerHTML;
      el.innerHTML = t(el.dataset.i18n, el.dataset.uk);
    });

    ATTRS.forEach(function (a) {
      document.querySelectorAll("[" + a[1] + "]").forEach(function (el) {
        if (el.dataset[a[3]] == null) el.dataset[a[3]] = el.getAttribute(a[2]) || "";
        el.setAttribute(a[2], t(el.dataset[a[0]], el.dataset[a[3]]));
      });
    });

    document.querySelectorAll("[data-lang]").forEach(function (b) {
      b.setAttribute("aria-pressed", String(b.getAttribute("data-lang") === lang));
    });

    document.dispatchEvent(new CustomEvent("nox:lang", { detail: lang }));
  }

  function set(next) {
    if (next !== "uk" && next !== "en") return;
    if (next === lang) return;
    lang = next;
    try { localStorage.setItem(KEY, lang); } catch (e) {}
    apply();
  }

  document.addEventListener("click", function (e) {
    var el = e.target && e.target.closest ? e.target.closest("[data-lang]") : null;
    if (!el) return;
    e.preventDefault();
    set(el.getAttribute("data-lang"));
  });

  window.noxT = t;
  window.noxTerm = term;
  window.noxDateText = dateText;
  window.noxWeekday = weekday;
  window.noxLang = function () { return lang; };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", apply);
  } else { apply(); }
})();
