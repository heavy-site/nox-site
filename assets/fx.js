/* nøx — atmosphere layer.
   A WebGL void behind everything, the floor plan drawn as a ritual diagram,
   an occasional glitch on the wordmark, and reveals on scroll.
   Every piece degrades to nothing if the browser or the user says no. */
(function () {
  "use strict";

  var REDUCED = matchMedia("(prefers-reduced-motion: reduce)").matches;

  // How far the opening reveal has run, 0 (darkness) to 1 (the mark is whole).
  // The shader reads it every frame; the scroll controller writes it.
  var STATE = { reveal: REDUCED ? 1 : 0 };

  /* ── 1. the void ────────────────────────────────────────────────── */
  var VERT = [
    "attribute vec2 p;",
    "void main(){ gl_Position = vec4(p, 0.0, 1.0); }"
  ].join("\n");

  var FRAG = [
    "precision mediump float;",
    "uniform vec2  u_res;",
    "uniform float u_t;",
    "uniform vec2  u_m;",
    "uniform float u_rev;",

    "float hash(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }",

    "float noise(vec2 p){",
    "  vec2 i = floor(p), f = fract(p);",
    "  vec2 u = f * f * (3.0 - 2.0 * f);",
    "  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),",
    "             mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);",
    "}",

    "float fbm(vec2 p){",
    "  float v = 0.0, a = 0.5;",
    "  for (int i = 0; i < 5; i++) { v += a * noise(p); p *= 2.02; a *= 0.5; }",
    "  return v;",
    "}",

    "void main(){",
    "  vec2 uv = gl_FragCoord.xy / u_res.xy;",
    "  vec2 q  = (gl_FragCoord.xy - 0.5 * u_res.xy) / u_res.y;",

    // slow drifting plumes, pulled gently toward the cursor
    "  vec2 d = q - u_m * 0.20;",
    "  float t = u_t * 0.014;",
    "  float f = fbm(d * 2.3 + vec2(t, -t * 0.7));",
    "  f = fbm(d * 3.1 + vec2(f * 1.4, t * 1.2));",

    // ritual interference: faint concentric rings around the centre
    "  float r  = length(d);",
    "  float ring = sin(r * 46.0 - u_t * 0.5) * 0.5 + 0.5;",
    "  ring *= smoothstep(0.85, 0.12, r) * 0.035;",

    // gas flame: deep blue body, near-white blue at the hottest points
    "  vec3 col = vec3(0.020, 0.026, 0.040);",
    "  col += vec3(0.045, 0.200, 0.430) * pow(f, 2.4) * 0.86;",
    "  col += vec3(0.300, 0.560, 0.760) * pow(f, 6.0) * 0.34;",
    "  col += vec3(0.075, 0.230, 0.400) * ring;",

    // a dim halo where the cursor is
    "  col += vec3(0.050, 0.170, 0.320) * smoothstep(0.62, 0.0, length(q - u_m * 0.5)) * 0.28;",

    // grain, then fade to the page ground at the edges
    "  col += (hash(gl_FragCoord.xy + fract(u_t)) - 0.5) * 0.030;",
    "  col *= smoothstep(1.25, 0.25, length(uv - 0.5));",

    "  col *= 0.16 + 0.84 * u_rev;",
    "  gl_FragColor = vec4(col, 1.0);",
    "}"
  ].join("\n");

  function shader(gl, type, src) {
    var s = gl.createShader(type);
    gl.shaderSource(s, src);
    gl.compileShader(s);
    return gl.getShaderParameter(s, gl.COMPILE_STATUS) ? s : null;
  }

  function voidLayer() {
    var cv = document.getElementById("fx");
    if (!cv) return;
    var gl = cv.getContext("webgl", { antialias: false, alpha: false, depth: false })
          || cv.getContext("experimental-webgl");
    // No WebGL: the CSS ground already looks correct, so just leave it.
    if (!gl) { cv.style.display = "none"; return; }

    var vs = shader(gl, gl.VERTEX_SHADER, VERT);
    var fs = shader(gl, gl.FRAGMENT_SHADER, FRAG);
    if (!vs || !fs) { cv.style.display = "none"; return; }

    var pr = gl.createProgram();
    gl.attachShader(pr, vs); gl.attachShader(pr, fs); gl.linkProgram(pr);
    if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) { cv.style.display = "none"; return; }
    gl.useProgram(pr);

    gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1, 3,-1, -1,3]), gl.STATIC_DRAW);
    var loc = gl.getAttribLocation(pr, "p");
    gl.enableVertexAttribArray(loc);
    gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

    var uRes = gl.getUniformLocation(pr, "u_res"),
        uT   = gl.getUniformLocation(pr, "u_t"),
        uM   = gl.getUniformLocation(pr, "u_m"),
        uRev = gl.getUniformLocation(pr, "u_rev");

    // Half resolution is plenty for a soft field, and keeps laptops quiet.
    var dpr = Math.min(devicePixelRatio || 1, 1.5) * 0.5;
    function size() {
      cv.width  = Math.max(2, Math.floor(innerWidth  * dpr));
      cv.height = Math.max(2, Math.floor(innerHeight * dpr));
      gl.viewport(0, 0, cv.width, cv.height);
      gl.uniform2f(uRes, cv.width, cv.height);
    }
    addEventListener("resize", size, { passive: true });
    size();

    var mx = 0, my = 0, tx = 0, ty = 0;
    addEventListener("pointermove", function (e) {
      tx = (e.clientX / innerWidth  - 0.5) * 2;
      ty = -(e.clientY / innerHeight - 0.5) * 2;
    }, { passive: true });

    var visible = true;
    document.addEventListener("visibilitychange", function () { visible = !document.hidden; });

    var t0 = performance.now();
    (function frame(now) {
      requestAnimationFrame(frame);
      if (!visible) return;
      mx += (tx - mx) * 0.045;
      my += (ty - my) * 0.045;
      gl.uniform2f(uM, mx, my);
      gl.uniform1f(uT, REDUCED ? 8.0 : (now - t0) / 1000);
      gl.uniform1f(uRev, STATE.reveal);
      gl.drawArrays(gl.TRIANGLES, 0, 3);
    })(t0);
  }

  /* ── 2. the plan, drawn as a diagram ────────────────────────────── */
  var GLYPHS = "NØX · НИЖНЬОЮРКІВСЬКА 31 · 50.4665 30.4999 · 215 M² · 300–350 · 18+ · ";

  function svgEl(name, attrs) {
    var e = document.createElementNS("http://www.w3.org/2000/svg", name);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }

  function diagram(host, opts) {
    var R = 500;
    var svg = svgEl("svg", {
      viewBox: "-" + R + " -" + R + " " + (R * 2) + " " + (R * 2),
      role: "img",
      "aria-label": "План залу nøx: танцювальна зона з колонами, барна стійка 9,6 метра, санвузол, гардероб"
    });

    var defs = svgEl("defs");
    defs.appendChild(svgEl("path", {
      id: "ring-" + opts.id,
      d: "M 0,-405 A 405,405 0 1,1 -0.01,-405",
      fill: "none"
    }));
    svg.appendChild(defs);

    // outer rings
    [[470, ".18", "1"], [452, ".10", "1"], [352, ".14", "1"]].forEach(function (r) {
      svg.appendChild(svgEl("circle", {
        cx: 0, cy: 0, r: r[0], fill: "none",
        stroke: "#2E9BF0", "stroke-opacity": r[1], "stroke-width": r[2]
      }));
    });

    // ticking ring — 72 marks, every ninth one long
    var g = svgEl("g", { stroke: "#2E9BF0", "stroke-opacity": ".34", "stroke-width": "1" });
    for (var i = 0; i < 72; i++) {
      var a = (i / 72) * Math.PI * 2, lng = i % 9 === 0;
      var r1 = 452, r2 = lng ? 428 : 442;
      g.appendChild(svgEl("line", {
        x1: Math.cos(a) * r1, y1: Math.sin(a) * r1,
        x2: Math.cos(a) * r2, y2: Math.sin(a) * r2
      }));
    }
    svg.appendChild(g);

    // slow counter-rotating dashed ring
    var spin = svgEl("g", { class: "spin" });
    spin.appendChild(svgEl("circle", {
      cx: 0, cy: 0, r: 418, fill: "none",
      stroke: "#B9E2FF", "stroke-opacity": ".22",
      "stroke-width": "1", "stroke-dasharray": "2 22"
    }));
    svg.appendChild(spin);

    // the address running around the circle
    var textG = svgEl("g", { class: "spin-slow" });
    var txt = svgEl("text", {
      "font-family": "JetBrains Mono, ui-monospace, monospace",
      "font-size": "17", "letter-spacing": "5.5",
      fill: "#9A9486", "fill-opacity": ".62"
    });
    var tp = svgEl("textPath", { href: "#ring-" + opts.id });
    tp.setAttributeNS("http://www.w3.org/1999/xlink", "xlink:href", "#ring-" + opts.id);
    tp.textContent = GLYPHS + GLYPHS;
    txt.appendChild(tp); textG.appendChild(txt); svg.appendChild(textG);

    // cardinal reticles
    [[0, -470], [470, 0], [0, 470], [-470, 0]].forEach(function (p) {
      svg.appendChild(svgEl("circle", {
        cx: p[0], cy: p[1], r: 3.5, fill: "#2E9BF0", "fill-opacity": ".8"
      }));
    });

    // the real room in the middle
    var w = opts.planWidth || 630, h = w * (13400 / 20200);
    svg.appendChild(svgEl("image", {
      href: "/assets/plan.svg", x: -w / 2, y: -h / 2, width: w, height: h,
      opacity: opts.planOpacity || ".9"
    }));

    host.appendChild(svg);
    return svg;
  }

  // The hero carries the mark itself, with the inscription turning around it.
  function heroSigil(host) {
    var R = 500;
    var svg = svgEl("svg", { viewBox: "-500 -500 1000 1000", "aria-hidden": "true" });

    var defs = svgEl("defs");
    defs.appendChild(svgEl("path", { id: "ring-hero", d: "M 0,-462 A 462,462 0 1,1 -0.01,-462", fill: "none" }));
    svg.appendChild(defs);

    var textG = svgEl("g", { class: "spin-slow" });
    var txt = svgEl("text", {
      "font-family": "JetBrains Mono, ui-monospace, monospace",
      "font-size": "16", "letter-spacing": "6",
      fill: "#9A9486", "fill-opacity": ".5"
    });
    var tp = svgEl("textPath", { href: "#ring-hero" });
    tp.setAttributeNS("http://www.w3.org/1999/xlink", "xlink:href", "#ring-hero");
    tp.textContent = GLYPHS + GLYPHS;
    txt.appendChild(tp); textG.appendChild(txt); svg.appendChild(textG);

    var spin = svgEl("g", { class: "spin" });
    spin.appendChild(svgEl("circle", {
      cx: 0, cy: 0, r: 492, fill: "none",
      stroke: "#B9E2FF", "stroke-opacity": ".16", "stroke-width": "1", "stroke-dasharray": "2 26"
    }));
    svg.appendChild(spin);

    host.appendChild(svg);

    // The mark is not drawn flat — it is dithered, and the dither is driven
    // by scroll, so the sigil assembles out of pixels as the page moves.
    var mark = document.createElement("div");
    mark.className = "mark";
    mark.setAttribute("aria-hidden", "true");
    var a = document.createElement("canvas"); a.className = "dith a";
    mark.appendChild(a);
    host.appendChild(mark);
  }

  /* ── the dither ─────────────────────────────────────────────────────
     Ordered 8x8 Bayer over a coarse grid, so the dots read as dither rather
     than vanish into the pixel density. The threshold is driven by scroll:
     at the top almost nothing clears it and the screen is dark; as the page
     moves, more cells light until the mark stands whole. Pixels near the
     centre clear it sooner, so the mark grows outward instead of fading in. */
  var BAYER = [
    [ 0,48,12,60, 3,51,15,63],
    [32,16,44,28,35,19,47,31],
    [ 8,56, 4,52,11,59, 7,55],
    [40,24,36,20,43,27,39,23],
    [ 2,50,14,62, 1,49,13,61],
    [34,18,46,30,33,17,45,29],
    [10,58, 6,54, 9,57, 5,53],
    [42,26,38,22,41,25,37,21]
  ];
  var GAS = [46, 155, 240];
  var SIZE = 300;

  // Builds a paint(progress) for one canvas. The source alpha and each cell's
  // distance from the centre are measured once; a repaint is then just a
  // threshold comparison over 90k cells, which is cheap enough per frame.
  function markPainter(canvas, ready) {
    var img = new Image();
    img.decoding = "async";
    img.onload = function () {
      var g = document.createElement("canvas");
      g.width = g.height = SIZE;
      var gx = g.getContext("2d");
      gx.drawImage(img, 0, 0, SIZE, SIZE);
      var src = gx.getImageData(0, 0, SIZE, SIZE).data;

      var n = SIZE * SIZE;
      var alpha = new Float32Array(n);
      var rad = new Float32Array(n);
      var thr = new Float32Array(n);
      var half = SIZE / 2;
      for (var y = 0; y < SIZE; y++) {
        for (var x = 0; x < SIZE; x++) {
          var i = y * SIZE + x;
          alpha[i] = src[i * 4 + 3] / 255;
          rad[i] = Math.sqrt((x - half) * (x - half) + (y - half) * (y - half)) / half;
          thr[i] = (BAYER[y & 7][x & 7] + 0.5) / 64;
        }
      }

      canvas.width = canvas.height = SIZE;
      var ctx = canvas.getContext("2d");
      var out = ctx.createImageData(SIZE, SIZE);
      var od = out.data;

      function paint(p) {
        var eased = p * p * (3 - 2 * p);
        for (var i = 0; i < n; i++) {
          var a = alpha[i], j = i * 4;
          if (a === 0) { od[j + 3] = 0; continue; }
          var r = rad[i];
          // settled shape: solid core, dissolving rim
          var settled = 1 - Math.min(1, Math.max(0, (r - 0.46) / 0.60)) * 0.58;
          // arrival: the centre clears the threshold first
          var arriving = eased * (1.35 - 0.55 * r);
          var keep = a * Math.min(arriving, settled);
          if (keep > thr[i]) {
            od[j] = GAS[0]; od[j + 1] = GAS[1]; od[j + 2] = GAS[2]; od[j + 3] = 255;
          } else {
            od[j + 3] = 0;
          }
        }
        ctx.putImageData(out, 0, 0);
      }

      ready(paint);
    };
    img.src = "/assets/logo-mark.svg";
  }

  /* ── the opening: darkness, then the mark assembles ─────────────── */
  function revealScroll() {
    var host = document.getElementById("reveal");
    if (!host) return;
    var pin = host.querySelector(".pin");
    var mark = host.querySelector(".mark");
    var canvas = mark && mark.querySelector("canvas");
    if (!pin || !canvas) return;

    mark.classList.add("scrolled");

    markPainter(canvas, function (paint) {
      if (REDUCED) {
        pin.style.setProperty("--p", "1");
        mark.classList.add("done");
        paint(1);
        return;
      }

      var last = -1, queued = false;

      function measure() {
        queued = false;
        var span = host.offsetHeight - innerHeight;
        var p = span > 0 ? -host.getBoundingClientRect().top / span : 1;
        p = Math.min(1, Math.max(0, p));

        pin.style.setProperty("--p", p.toFixed(3));
        STATE.reveal = p;
        mark.classList.toggle("done", p > 0.985);

        // 48 steps is finer than the dither grid can show, and skips
        // ~19 of every 20 repaints during a fast scroll.
        var step = Math.round(p * 48);
        if (step !== last) { last = step; paint(step / 48); }
      }

      function schedule() { if (!queued) { queued = true; requestAnimationFrame(measure); } }

      addEventListener("scroll", schedule, { passive: true });
      addEventListener("resize", schedule, { passive: true });
      measure();
    });
  }

  /* ── the waveform ───────────────────────────────────────────────────
     Stacked horizontal lines in the left gutter. Flat at the top of the
     page; scrolling raises a ridge through the stack, tallest at its middle.
     Every line breathes slightly out of phase with its neighbours, so the
     stack reads as a rhythm rather than a static graph. */
  function waveform() {
    var cv = document.getElementById("wave");
    if (!cv) return;
    var ctx = cv.getContext("2d");
    if (!ctx) return;

    var LINES = 34, STEPS = 44;
    var dpr = Math.min(devicePixelRatio || 1, 2);
    var W = 0, H = 0;

    function size() {
      var r = cv.getBoundingClientRect();
      W = r.width; H = r.height;
      cv.width = Math.max(1, Math.round(W * dpr));
      cv.height = Math.max(1, Math.round(H * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    addEventListener("resize", size, { passive: true });
    size();

    function progress() {
      var span = document.documentElement.scrollHeight - innerHeight;
      return span > 0 ? Math.min(1, Math.max(0, scrollY / span)) : 0;
    }

    var visible = true;
    document.addEventListener("visibilitychange", function () { visible = !document.hidden; });

    var p = 0;
    (function frame(now) {
      requestAnimationFrame(frame);
      if (!visible || W < 8) return;

      var t = now / 1000;
      p += (progress() - p) * 0.08;            // the ridge follows, it does not snap
      ctx.clearRect(0, 0, W, H);

      var gap = H / (LINES + 1);
      for (var i = 0; i < LINES; i++) {
        var y0 = gap * (i + 1);
        var d = i / (LINES - 1) - 0.5;
        var env = Math.exp(-d * d * 7);        // the ridge peaks mid-stack
        var beat = REDUCED ? 1 : 0.80 + 0.20 * Math.sin(t * 2.1 + i * 0.36);
        var amp = gap * 3.1 * p * env * beat;

        ctx.beginPath();
        for (var s = 0; s <= STEPS; s++) {
          var x = W * s / STEPS;
          var u = x / W - 0.46;
          var ridge = Math.exp(-u * u * 15);
          var wob = REDUCED ? 0
            : (Math.sin(x * 0.13 + t * 1.25 + i) + Math.sin(x * 0.058 - t * 0.85 + i * 2)) * 0.5;
          var y = y0 - amp * ridge - wob * (0.7 + 2.6 * p * env);
          if (s) ctx.lineTo(x, y); else ctx.moveTo(x, y);
        }
        ctx.strokeStyle = "rgba(46,155,240," + (0.09 + 0.34 * env * p).toFixed(3) + ")";
        ctx.lineWidth = 1;
        ctx.stroke();
      }
    })(performance.now());
  }

  /* ── 3. the wordmark stutters now and then ──────────────────────── */
  function glitch() {
    var stage = document.querySelector(".stagein");
    var h1 = stage && stage.querySelector("h1");
    if (!h1 || REDUCED) return;
    var word = h1.textContent.trim();
    h1.innerHTML = '<span class="base">' + word + '</span>'
      + '<span class="g r" aria-hidden="true">' + word + '</span>'
      + '<span class="g c" aria-hidden="true">' + word + '</span>';

    var fire = function () {
      stage.classList.add("glitch");
      setTimeout(function () { stage.classList.remove("glitch"); }, 460);
      setTimeout(fire, 3200 + Math.random() * 6500);
    };
    setTimeout(fire, 900);
  }

  /* ── 4. reveals ─────────────────────────────────────────────────── */
  function reveals() {
    var nodes = document.querySelectorAll(".rise");
    if (!nodes.length) return;
    if (REDUCED || !("IntersectionObserver" in window)) {
      nodes.forEach(function (n) { n.classList.add("in"); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add("in"); io.unobserve(en.target); }
      });
    }, { rootMargin: "0px 0px -12% 0px" });
    nodes.forEach(function (n) { io.observe(n); });
  }

  // Published before boot: site.js can paint from a cached or stubbed response
  // before DOMContentLoaded, and must not fall back to the plain plan then.
  window.noxDiagram = diagram;

  function boot() {
    voidLayer();
    waveform();
    glitch();
    reveals();
    var sig = document.querySelector(".sigil");
    if (sig) heroSigil(sig);
    revealScroll();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else { boot(); }
})();
