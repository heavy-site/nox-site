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
      { value: "18 × 12", label: "метрів, без колон по центру" }
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

  function events(data) {
    var up = $("upcoming");
    if (up) {
      up.innerHTML = (data.upcoming && data.upcoming.length)
        ? data.upcoming.map(function (e) { return evRow(e, false); }).join("")
        : '<div class="none"><p>Найближчі вечори зʼявляться тут. Дати ще вільні.</p>' +
          '<a class="btn machine" href="/booking">Забронювати дату</a></div>';
    }
    if ($("past") && data.past && data.past.length) {
      $("past").innerHTML = data.past.map(function (e) { return evRow(e, true); }).join("");
      if ($("pastwrap")) $("pastwrap").hidden = false;
    }
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
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var btn = $("rentbtn"), msg = $("rentmsg"), body = {};
      ["name", "contact", "event", "date", "guests", "comment", "website"].forEach(function (k) {
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
