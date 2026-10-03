/* Shared behaviour for every page. Each page calls only what it has markup for. */
(function () {
  "use strict";

  var esc = function (s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  };
  var $ = function (id) { return document.getElementById(id); };

  // The page is Ukrainian on its own; i18n.js, when it is there, turns what we
  // build here into English too. Every helper falls back to the Ukrainian it
  // was given, so the site is whole even if that file never loads.
  var T     = function (k, uk) { return window.noxT ? window.noxT(k, uk) : uk; };
  var TERM  = function (v) { return window.noxTerm ? window.noxTerm(v) : v; };
  var DTEXT = function (v) { return window.noxDateText ? window.noxDateText(v) : v; };
  var WDAY  = function (v) { return window.noxWeekday ? window.noxWeekday(v) : ""; };

  // "субота" for one night, "субота — неділя" for a night that runs into the
  // next day. Empty when the calendar carries no machine-readable date.
  function weekdays(e) {
    var a = WDAY(e.date), b = WDAY(e.dateEnd);
    if (!a) return "";
    return (b && b !== a) ? a + " — " + b : a;
  }

  // Who runs the night. The hours go on a line of their own, in white and
  // larger: when to come is what a guest reads the card for.
  function evMeta(e) {
    return e.promoter || "";
  }
  // The announcement post, beside the tickets. Named after Instagram when that
  // is where it lives, since that is what a guest expects to open.
  function evPost(e) {
    if (!e.post || !/^https?:/.test(e.post)) return "";
    var label = /instagram\.com/.test(e.post) ? T("js.post.ig", "Пост в Instagram") : T("js.post", "Пост події");
    return '<a class="btn ghost" href="' + esc(e.post) + '" target="_blank" rel="noopener">' + esc(label) + " ↗</a>";
  }
  // The lineup as the organiser wrote it, a set to a line. A line that opens
  // with hours ("18:00–20:00 Mad Cult") or a label ("День 1: …") is laid out
  // as a timetable: that part on the left, the names beside it. A lineup on
  // one plain line stays a sentence.
  var SET = /^(\d{1,2}[:.]\d{2}\s*[–—-]\s*\d{1,2}[:.]\d{2})\s*[–—:-]?\s*(.+)$/;
  var LABEL = /^([^:]{1,24}):\s*(.+)$/;
  function lineup(text, cls) {
    var lines = String(text || "").split(/\r?\n/).map(function (l) { return l.trim(); })
      .filter(Boolean);
    if (!lines.length) return "";
    var rows = lines.map(function (l) {
      var m = SET.exec(l), at;
      if (m) at = m[1].replace(/\./g, ":").replace(/\s*[–—-]\s*/, "–");
      else if ((m = LABEL.exec(l))) at = m[1];
      return m ? { at: at, who: m[2] } : null;
    });
    if (lines.length === 1 && !rows[0]) return '<p class="' + cls + '">' + esc(lines[0]) + "</p>";
    return '<ul class="sched ' + cls + '">' + lines.map(function (l, i) {
      var r = rows[i];
      return r ? '<li><span class="at">' + esc(r.at) + '</span><span class="who">' + esc(r.who) + "</span></li>"
               : '<li><span class="who">' + esc(l) + "</span></li>";
    }).join("") + "</ul>";
  }

  function evTime(e, tag) {
    return e.time ? "<" + tag + ' class="tm">' + esc(e.time) + "</" + tag + ">" : "";
  }

  function evDate(e) {
    return esc(DTEXT(e.dateText || e.date)) + (e.year ? " " + esc(e.year) : "");
  }

  // Venue figures the page can show even when the backend is unreachable —
  // an empty hero is worse than a slightly stale one.
  var FALLBACK = {
    outside: [
      { value: "28 м²",  label: "тераса, 4 × 7 м" },
      { value: "≈ 50",   label: "гостей на терасі" },
      { value: "203 м²", label: "парковка, 14 × 14,5 м" },
      { value: "≈ 8",    label: "авто на парковці" }
    ],
    headline: [
      { value: "215 м²",  label: "зал" },
      { value: "300–350", label: "гостей" },
      { value: "4,1 м",   label: "барна стійка" },
      { value: "84,1 м²", label: "танцпол, окреме приміщення" }
    ],
    rent: {
      included: [
        { title: "Зал 215 м²",         note: "18,00 × 12,00 м, шість колон по периметру танцполу" },
        { title: "Бар 4,1 м",          note: "три секції фронту, робоча лінія за стійкою, холодильники" },
        { title: "Гардероб",           note: "окрема зона біля входу" },
        { title: "Санвузол на 6 кабін", note: "два умивальники, чотири пісуари" },
        { title: "Тераса",             note: "вихід просто із залу" },
        { title: "Парковка",           note: "своя, біля входу" }
      ],
      arranged: [
        { title: "Звук і світло", note: "привозите своє або орендуємо — підкажемо, з ким працюємо" },
        { title: "Бармени",       note: "наша команда, кількість — під ваш прогноз" },
        { title: "Охорона",       note: "на вході й у залі" }
      ]
    },
    media: [], upcoming: [], past: []
  };

  function stickyBar() {
    var bar = $("bar");
    if (!bar) return;
    var on = function () { bar.classList.toggle("stuck", window.scrollY > 40); };
    addEventListener("scroll", on, { passive: true });
    on();
  }

  var all = function (name) {
    return Array.prototype.slice.call(document.querySelectorAll('[data-nox="' + name + '"]'));
  };

  // The figures. Above the plan they follow what the plan shows: the club's
  // own, or the terrace's and the parking's when the ground outside is on.
  function figs(items) {
    return items.map(function (h) {
      return '<div class="fig"><b>' + esc(TERM(h.value)) + "</b><span>" + esc(TERM(h.label)) + "</span></div>";
    }).join("");
  }
  function headline(items, outside) {
    var outsideOn = window.noxPlanView && window.noxPlanView() === "full" && outside && outside.length;
    all("headline").forEach(function (host) {
      host.innerHTML = figs(host.hasAttribute("data-plan-figs") && outsideOn ? outside : items);
    });
  }

  // The words of the plan are drawn into the file itself, so the English room
  // is a file of its own.
  function planSrc() {
    return (window.noxLang && window.noxLang() === "en")
      ? "/assets/plan-en.svg" : "/assets/plan.svg";
  }

  // Photos when there are photos. The drawing of the room lives on the
  // organisers' page now, so without photos this stays empty.
  function visual(media) {
    all("visual").forEach(function (host) {
      host.innerHTML = "";
      if (media && media.length) {
        host.innerHTML = '<div class="gallery">' + media.map(function (m, i) {
          var wide = (media.length % 2 === 1 && i === 0) ? ' class="wide"' : "";
          return "<figure" + wide + '><img src="' + esc(m.src) + '" alt="' + esc(m.alt) +
            '" loading="lazy" decoding="async">' +
            (m.caption ? "<figcaption>" + esc(m.caption) + "</figcaption>" : "") + "</figure>";
        }).join("") + "</div>";
      }
    });
  }

  // The plan for organisers: the room, the terrace and the parking, drawn the
  // full width of the page, with the cloakroom and the stage live on it.
  function plan() {
    all("plan").forEach(function (host) {
      host.innerHTML = '<div class="planfig"></div>';
      var box = host.querySelector(".planfig");
      if (window.noxPlan) {
        window.noxPlan(box);
      } else {
        box.innerHTML = '<img src="' + planSrc() + '" alt="' +
          esc(T("js.plan.alt", "План залу nøx")) + '">';
      }
    });
  }

  function rent(data) {
    var row = function (x) {
      return "<li><b>" + esc(x.title) + "</b>" + (x.note ? "<span>" + esc(x.note) + "</span>" : "") + "</li>";
    };
    if ($("included")) $("included").innerHTML = data.included.map(row).join("");
    if ($("arranged")) $("arranged").innerHTML = data.arranged.map(row).join("");
  }

  // A row says as much as the calendar knows about the night: the date with
  // its weekday, when it starts, who runs it, and who plays.
  function evRow(e, past) {
    var wd = weekdays(e), meta = evMeta(e);
    var src = e.posterSmall || e.poster;
    var right = ((!past && e.tickets)
      ? '<a class="btn" href="' + esc(e.tickets) + '" target="_blank" rel="noopener">' +
        esc(T("js.tickets", "Квитки")) + '</a>' : "") + evPost(e);
    // The poster comes along whole, only smaller.
    var thumb = src
      ? '<div class="thumb"><img src="' + esc(src) + '" alt="' + esc(e.title) + " — " +
        esc(T("js.poster.alt", "афіша")) + '" loading="lazy" decoding="async"></div>' : "";
    return '<div class="ev' + (past ? " past" : "") + (thumb ? " has-thumb" : "") + '" id="ev-' + esc(e.id) + '">' + thumb +
      '<div class="d">' + evDate(e) +
        (wd ? '<span class="wd">' + esc(wd) + "</span>" : "") + "</div>" +
      '<div><div class="t">' + esc(e.title) + "</div>" +
      evTime(e, "div") +
      (meta ? '<div class="p">' + esc(meta) + "</div>" : "") +
      (e.genre ? '<div class="g">' + esc(e.genre) + "</div>" : "") +
      lineup(e.lineup, "lu") +
      '</div><div class="acts">' + right + "</div></div>";
  }

  function empty(text) {
    return '<div class="none"><p>' + esc(text) + "</p>" +
      '<a class="btn machine" href="/booking">' +
      esc(T("js.none.cta", "Забронювати дату")) + "</a></div>";
  }

  // The head of the calendar: the nearest night, given the whole width, with
  // its poster. The newest night in the calendar holds this place whether or
  // not its date has passed — the section is the nearest event either way.
  function feature(data) {
    var host = $("feature");
    if (!host) return;

    var up = data.upcoming || [], past = data.past || [];
    var e = up[0] || past[0];               // past comes newest first
    if (!e) {
      host.innerHTML = empty(T("js.none.upcoming", "Найближчі вечори зʼявляться тут. Дати ще вільні."));
      return;
    }

    var poster = "";
    if (e.poster) {
      poster = '<div class="fposter"><img src="' + esc(e.poster) + '"' +
        (e.posterSmall
          ? ' srcset="' + esc(e.posterSmall) + " 720w, " + esc(e.poster) + ' 1080w"' +
            ' sizes="(max-width:860px) 92vw, 440px"'
          : "") +
        ' alt="' + esc(e.title) + " — " + esc(T("js.poster.alt", "афіша")) + '" loading="lazy"></div>';
    }

    var wd = weekdays(e), meta = evMeta(e);

    host.innerHTML = '<div class="feat" id="ev-' + esc(e.id) + '">' + poster +
      '<div class="fbody">' +
        '<div class="d">' + evDate(e) +
          (wd ? '<span class="wd">' + esc(wd) + "</span>" : "") + "</div>" +
        '<div class="t">' + esc(e.title) + "</div>" +
        evTime(e, "div") +
        (meta ? '<div class="p">' + esc(meta) + "</div>" : "") +
        (e.genre ? '<div class="g">' + esc(e.genre) + "</div>" : "") +
        lineup(e.lineup, "line") +
        '<div class="acts">' +
          (e.tickets
            ? '<a class="btn" href="' + esc(e.tickets) + '" target="_blank" rel="noopener">' +
              esc(T("js.tickets", "Квитки")) + "</a>" : "") +
          evPost(e) +
        "</div>" +
      "</div></div>";
  }

  // Everything booked after that one.
  function events(data) {
    var host = $("later");
    if (!host) return;
    var rest = (data.upcoming || []).slice(1);
    host.innerHTML = rest.length
      ? rest.map(function (e) { return evRow(e, false); }).join("")
      : empty(T("js.none.later", "Далі поки порожньо — дати вільні."));
  }

  // Under the nearest night, a line through every night ahead, in date
  // order: a dot on the line for each, its card beneath. A card opens its
  // night further down the page. One night needs no line, so it stays empty.
  function timeline(data) {
    var host = $("tline");
    if (!host) return;
    var up = data.upcoming || [];
    if (up.length < 2) { host.innerHTML = ""; host.hidden = true; return; }
    host.hidden = false;
    host.innerHTML = '<ol class="tl">' + up.map(function (e, i) {
      var src = e.posterSmall || e.poster, wd = weekdays(e);
      return '<li class="tl-i' + (i === 0 ? " now" : "") + '">' +
        '<button type="button" class="tl-c" data-go="ev-' + esc(e.id) + '">' +
          '<span class="tl-dot" aria-hidden="true"></span>' +
          '<span class="tl-d">' + esc(DTEXT(e.dateText || e.date)) + "</span>" +
          '<span class="tl-card' + (src ? "" : " bare") + '">' +
            (src ? '<img src="' + esc(src) + '" alt="" loading="lazy" decoding="async">' : "") +
            '<span class="tl-b">' +
              (i === 0 ? '<em class="tl-now">' + esc(T("js.line.now", "найближча")) + "</em>" : "") +
              '<b>' + esc(e.title) + "</b>" +
              (wd ? '<span class="tl-wd">' + esc(wd) + "</span>" : "") +
              evTime(e, "span") +
            "</span>" +
          "</span>" +
        "</button></li>";
    }).join("") + "</ol>";

    if (!host.dataset.wired) {
      host.dataset.wired = "1";
      host.addEventListener("click", function (ev) {
        var b = ev.target.closest ? ev.target.closest("[data-go]") : null;
        if (b && $(b.dataset.go)) $(b.dataset.go).scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }
  }

  // The home page's posters: the nights ahead. Until there is one, the last
  // ones that have been, newest first, so the block is never empty. A card
  // ahead opens its tickets; one that has passed opens the listing.
  function posters(data) {
    var hosts = all("posters");
    if (!hosts.length) return;
    var up = data.upcoming || [], past = data.past || [];
    var card = function (e, gone) {
      var href = (!gone && e.tickets) ? e.tickets : "/events";
      var out = /^https?:/.test(href) ? ' target="_blank" rel="noopener"' : "";
      var src = e.posterSmall || e.poster;
      var meta = evMeta(e);
      return '<a class="pcard' + (gone ? " gone" : "") + '" href="' + esc(href) + '"' + out + ">" +
        '<div class="pimg">' + (src
          ? '<img class="pbg" src="' + esc(src) + '" alt="" aria-hidden="true">' +
            '<img src="' + esc(src) + '" alt="' + esc(e.title) + " — " + esc(T("js.poster.alt", "афіша")) +
            '" loading="lazy" decoding="async">'
          : '<span class="pname">' + esc(e.title) + "</span>") +
          (gone ? '<em class="ptag">' + esc(T("js.poster.past", "минула")) + "</em>" : "") +
        "</div>" +
        '<div class="pmeta"><span class="d">' + evDate(e) + "</span>" +
          "<b>" + esc(e.title) + "</b>" +
          evTime(e, "span") +
          (meta ? "<span>" + esc(meta) + "</span>" : "") +
          (e.genre ? '<span class="g">' + esc(e.genre) + "</span>" : "") +
          (!gone && e.tickets ? '<i class="pcta">' + esc(T("js.tickets", "Квитки")) + " →</i>" : "") +
        "</div></a>";
    };
    var html = up.length
      ? up.map(function (e) { return card(e, false); }).join("")
      : past.slice(0, 3).map(function (e) { return card(e, true); }).join("");
    hosts.forEach(function (host) { host.innerHTML = html; });
  }

  // Compact "next night" block for the home page.
  function nextNight(data) {
    var host = $("next");
    if (!host) return;
    if (data.upcoming && data.upcoming.length) {
      var e = data.upcoming[0], wd = weekdays(e), meta = evMeta(e);
      host.innerHTML = '<div class="next"><div class="d">' + evDate(e) +
        (wd ? '<span class="wd">' + esc(wd) + "</span>" : "") + "</div>" +
        '<div class="t">' + esc(e.title) + "</div>" + evTime(e, "div") +
        (meta ? '<div class="p">' + esc(meta) + "</div>" : "") + "</div>";
    } else {
      // No night ahead: the block stays empty rather than saying so.
      host.innerHTML = "";
    }
  }

  // Days already held by a confirmed night, from the payload. The form says so
  // the moment one is picked, instead of after it is filled in and sent.
  var BUSY = [];
  function busyCheck() {
    var day = $("r-date"), msg = $("rentmsg");
    if (!day || !msg) return;
    var taken = day.value && BUSY.indexOf(day.value) >= 0;
    var text = T("js.busy", "Ця дата вже зайнята. Оберіть іншу, будь ласка.");
    day.setCustomValidity(taken ? text : "");
    if (taken) { msg.className = "msg err"; msg.textContent = text; }
    else if (msg.textContent === text) { msg.className = "msg"; msg.textContent = ""; }
  }

  function bookingForm() {
    var form = $("rentform");
    if (!form) return;

    // A native <input type="time"> is drawn in the browser's own locale, so an
    // English one shows 10:00 PM and there is no attribute to say otherwise.
    // Two lists of our own are 24-hour everywhere and look the same to everyone.
    ["r-from", "r-to"].forEach(function (id) {
      var sel = $(id);
      if (!sel || sel.options.length > 1) return;
      for (var m = 0; m < 24 * 60; m += 15) {
        var t = ("0" + Math.floor(m / 60)).slice(-2) + ":" + ("0" + (m % 60)).slice(-2);
        sel.appendChild(new Option(t, t));
      }
    });

    // Nobody books a night that has been. Kyiv time, not the visitor's, so the
    // floor matches the calendar the venue actually runs on.
    var day = $("r-date");
    if (day && day.type === "date") {
      var kyiv = new Date(new Date().toLocaleString("en-US", { timeZone: "Europe/Kyiv" }));
      var pad = function (n) { return (n < 10 ? "0" : "") + n; };
      day.min = kyiv.getFullYear() + "-" + pad(kyiv.getMonth() + 1) + "-" + pad(kyiv.getDate());
    }
    if (day) {
      day.addEventListener("change", busyCheck);
      day.addEventListener("input", busyCheck);
    }
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var btn = $("rentbtn"), msg = $("rentmsg"), body = {};
      ["name", "contact", "telegram", "event", "date", "time_from", "time_to",
       "guests", "artists", "music", "social", "comment", "website"].forEach(function (k) {
        if (form.elements[k]) body[k] = form.elements[k].value.trim();
      });
      btn.disabled = true; msg.className = "msg";
      msg.textContent = T("js.sending", "Надсилаємо…");

      fetch("/api/rent", {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok && res.j.ok) {
            form.reset();
            msg.className = "msg ok";
            msg.textContent = T("js.sent", "Заявку отримано. Відповімо найближчим часом.");
          } else if (res.j && res.j.busy) {
            // Taken since the page was opened: the form learns the day too.
            if (day && day.value && BUSY.indexOf(day.value) < 0) BUSY.push(day.value);
            busyCheck();
          } else {
            msg.className = "msg err";
            msg.textContent = (res.j && res.j.error) ||
              T("js.failed", "Не вдалося надіслати. Спробуйте ще раз.");
          }
        })
        .catch(function () {
          msg.className = "msg err";
          msg.textContent = T("js.offline", "Немає звʼязку. Спробуйте ще раз або подзвоніть.");
        })
        .then(function () { btn.disabled = false; });
    });
  }

  // The last payload is kept so a change of language can simply paint again:
  // everything built here carries language, and re-rendering is cheaper than
  // teaching each block to translate itself in place.
  var LAST = null;

  function paint(d) {
    LAST = d;
    BUSY = d.busy || [];
    busyCheck();
    headline(d.headline || FALLBACK.headline, d.outside || FALLBACK.outside);
    visual(d.media || []);
    plan();
    rent(d.rent || FALLBACK.rent);
    feature(d);
    events(d);
    timeline(d);
    nextNight(d);
    posters(d);
  }

  stickyBar();
  bookingForm();
  document.addEventListener("nox:lang", function () { if (LAST) paint(LAST); });
  document.addEventListener("nox:planview", function () {
    var d = LAST || FALLBACK;
    headline(d.headline || FALLBACK.headline, d.outside || FALLBACK.outside);
  });

  fetch("/api/site", { headers: { Accept: "application/json" } })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(paint)
    .catch(function () { paint(FALLBACK); });
})();
