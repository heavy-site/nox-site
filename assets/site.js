/* Shared behaviour for every page. Each page calls only what it has markup for. */
(function () {
  "use strict";

  var esc = function (s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  };
  var $ = function (id) { return document.getElementById(id); };

  // Venue figures the page can show even when the backend is unreachable —
  // an empty hero is worse than a slightly stale one.
  var FALLBACK = {
    headline: [
      { value: "215 м²",  label: "зал" },
      { value: "300–350", label: "гостей" },
      { value: "9,6 м",   label: "барна стійка" },
      { value: "88,2 м²", label: "танцпол, окреме приміщення" }
    ],
    rent: {
      included: [
        { title: "Зал 215 м²",         note: "18,00 × 12,00 м, шість колон по периметру танцполу" },
        { title: "Бар 9,6 м",          note: "три секції фронту, робоча лінія за стійкою, холодильники" },
        { title: "Гардероб",           note: "окрема зона біля входу" },
        { title: "Санвузол на 7 кабін", note: "умивальники, пісуари" },
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

  function headline(items) {
    var html = items.map(function (h) {
      return '<div class="fig"><b>' + esc(h.value) + "</b><span>" + esc(h.label) + "</span></div>";
    }).join("");
    all("headline").forEach(function (host) { host.innerHTML = html; });
  }

  // Photos when there are photos; the drawing of the room until then.
  function visual(media) {
    all("visual").forEach(function (host, n) {
      if (media && media.length) {
        host.innerHTML = '<div class="gallery">' + media.map(function (m, i) {
          var wide = (media.length % 2 === 1 && i === 0) ? ' class="wide"' : "";
          return "<figure" + wide + '><img src="' + esc(m.src) + '" alt="' + esc(m.alt) +
            '" loading="lazy" decoding="async">' +
            (m.caption ? "<figcaption>" + esc(m.caption) + "</figcaption>" : "") + "</figure>";
        }).join("") + "</div>";
        return;
      }
      host.innerHTML = '<div class="diagram"></div>' +
        '<p class="diagcap">План залу · 18,00 × 12,00 м · бар 9,6 м</p>';
      var box = host.querySelector(".diagram");
      if (window.noxDiagram) {
        window.noxDiagram(box, { id: "plan" + n });
      } else {
        box.innerHTML = '<img src="/assets/plan.svg" alt="План залу nøx">';
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

  function evRow(e, past) {
    var when = esc(e.dateText || e.date) + (e.year ? " " + esc(e.year) : "");
    var right = (!past && e.tickets)
      ? '<a class="btn" href="' + esc(e.tickets) + '" target="_blank" rel="noopener">Квитки</a>' : "";
    return '<div class="ev' + (past ? " past" : "") + '">' +
      '<div class="d">' + when + "</div>" +
      '<div><div class="t">' + esc(e.title) + "</div>" +
      (e.promoter ? '<div class="p">' + esc(e.promoter) + (e.time ? " · " + esc(e.time) : "") + "</div>" : "") +
      "</div><div>" + right + "</div></div>";
  }

  function empty(text) {
    return '<div class="none"><p>' + text + "</p>" +
      '<a class="btn machine" href="/booking">Забронювати дату</a></div>';
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
      host.innerHTML = empty("Найближчі вечори зʼявляться тут. Дати ще вільні.");
      return;
    }

    var poster = "";
    if (e.poster) {
      poster = '<div class="fposter"><img src="' + esc(e.poster) + '"' +
        (e.posterSmall
          ? ' srcset="' + esc(e.posterSmall) + " 720w, " + esc(e.poster) + ' 1080w"' +
            ' sizes="(max-width:860px) 92vw, 440px"'
          : "") +
        ' alt="' + esc(e.title) + ' — афіша" loading="lazy"></div>';
    }

    host.innerHTML = '<div class="feat">' + poster +
      '<div class="fbody">' +
        '<div class="d">' + esc(e.dateText || e.date) + (e.year ? " " + esc(e.year) : "") + "</div>" +
        '<div class="t">' + esc(e.title) + "</div>" +
        (e.promoter ? '<div class="p">' + esc(e.promoter) + (e.time ? " · " + esc(e.time) : "") + "</div>" : "") +
        (e.lineup ? '<p class="line">' + esc(e.lineup) + "</p>" : "") +
        (e.tickets
          ? '<a class="btn" href="' + esc(e.tickets) + '" target="_blank" rel="noopener">Квитки</a>' : "") +
      "</div></div>";
  }

  // Everything booked after that one.
  function events(data) {
    var host = $("later");
    if (!host) return;
    var rest = (data.upcoming || []).slice(1);
    host.innerHTML = rest.length
      ? rest.map(function (e) { return evRow(e, false); }).join("")
      : empty("Далі поки порожньо — дати вільні.");
  }

  // Compact "next night" block for the home page.
  function nextNight(data) {
    var host = $("next");
    if (!host) return;
    if (data.upcoming && data.upcoming.length) {
      var e = data.upcoming[0];
      host.innerHTML = '<div class="next"><div class="d">' +
        esc(e.dateText || e.date) + (e.year ? " " + esc(e.year) : "") + "</div>" +
        '<div class="t">' + esc(e.title) + "</div></div>";
    } else {
      host.innerHTML = '<div class="next"><div class="t">Дати на найближчі місяці ще вільні</div></div>';
    }
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
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var btn = $("rentbtn"), msg = $("rentmsg"), body = {};
      ["name", "contact", "telegram", "event", "date", "time_from", "time_to",
       "guests", "artists", "music", "social", "comment", "website"].forEach(function (k) {
        if (form.elements[k]) body[k] = form.elements[k].value.trim();
      });
      btn.disabled = true; msg.className = "msg"; msg.textContent = "Надсилаємо…";

      fetch("/api/rent", {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok && res.j.ok) {
            form.reset();
            msg.className = "msg ok";
            msg.textContent = "Заявку отримано. Відповімо найближчим часом.";
          } else {
            msg.className = "msg err";
            msg.textContent = (res.j && res.j.error) || "Не вдалося надіслати. Спробуйте ще раз.";
          }
        })
        .catch(function () {
          msg.className = "msg err";
          msg.textContent = "Немає звʼязку. Спробуйте ще раз або подзвоніть.";
        })
        .then(function () { btn.disabled = false; });
    });
  }

  function paint(d) {
    headline(d.headline || FALLBACK.headline);
    visual(d.media || []);
    rent(d.rent || FALLBACK.rent);
    feature(d);
    events(d);
    nextNight(d);
  }

  stickyBar();
  bookingForm();

  fetch("/api/site", { headers: { Accept: "application/json" } })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(paint)
    .catch(function () { paint(FALLBACK); });
})();
