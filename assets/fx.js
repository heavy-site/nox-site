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
  function svgEl(name, attrs) {
    var e = document.createElementNS("http://www.w3.org/2000/svg", name);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }

  /* ── the ring of marks ───────────────────────────────────────────────
     The turning rings used to carry the address and the coordinates as text
     on a path, which read as a caption rather than a seal. What turns now is
     a vocabulary of small marks — bar, cross, dots, chevron, ring, slash,
     arrow, gate. The sequence comes from a seeded hash, so a given ring is
     the same seal on every load rather than fresh noise each time. */
  function seeded(seed) {
    var s = seed >>> 0;
    return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; };
  }

  function runeRing(r, n, size, colour, opacity, seed) {
    var g = svgEl("g", {
      fill: "none", stroke: colour, "stroke-opacity": opacity,
      "stroke-width": "1", "stroke-linecap": "square"
    });
    var rnd = seeded(seed), s = size;
    var f = function (v) { return v.toFixed(1); };

    for (var i = 0; i < n; i++) {
      var a = (i / n) * Math.PI * 2;
      var m = svgEl("g", {
        transform: "translate(" + f(Math.cos(a) * r) + "," + f(Math.sin(a) * r) + ") " +
                   "rotate(" + f(a * 180 / Math.PI + 90) + ")"
      });
      var kind = (rnd() * 8) | 0, d = "";

      if (kind === 5) {                       // ring
        m.appendChild(svgEl("circle", { cx: 0, cy: 0, r: f(s * 0.42) }));
      } else if (kind === 6) {                // two dots
        m.appendChild(svgEl("circle", { cx: 0, cy: f(-s * 0.5), r: "1.1", fill: colour, "fill-opacity": opacity, stroke: "none" }));
        m.appendChild(svgEl("circle", { cx: 0, cy: f(s * 0.5),  r: "1.1", fill: colour, "fill-opacity": opacity, stroke: "none" }));
      } else {
        if (kind === 0) d = "M0," + f(-s) + "V" + f(s);
        if (kind === 1) d = "M0," + f(-s) + "V" + f(s) + "M" + f(-s * 0.5) + ",0H" + f(s * 0.5);
        if (kind === 2) d = "M" + f(-s * 0.5) + "," + f(-s * 0.45) + "L0," + f(s * 0.45) + "L" + f(s * 0.5) + "," + f(-s * 0.45);
        if (kind === 3) d = "M" + f(-s * 0.5) + "," + f(-s * 0.5) + "L" + f(s * 0.5) + "," + f(s * 0.5);
        if (kind === 4) d = "M0," + f(-s) + "V" + f(s * 0.2) +
                            "M" + f(-s * 0.45) + "," + f(s * 0.2) + "L0," + f(s) + "L" + f(s * 0.45) + "," + f(s * 0.2);
        if (kind === 7) d = "M" + f(-s * 0.45) + "," + f(-s * 0.6) + "H" + f(s * 0.45) +
                            "M" + f(-s * 0.45) + "," + f(s * 0.6) + "H" + f(s * 0.45) +
                            "M0," + f(-s * 0.6) + "V" + f(s * 0.6);
        m.appendChild(svgEl("path", { d: d }));
      }
      g.appendChild(m);
    }
    return g;
  }

  /* ── figures in the margins ──────────────────────────────────────────
     The centre carries the seal; these stand out at the sides, fixed to the
     window rather than the page, so they hold still while everything scrolls
     past them. Old geometry drawn plainly: a vesica, three linked rings, a
     triangle in a circle, earth's downward triangle, a squared circle, an eye.
     Each is drawn in its own -100…100 box and placed by CSS, so nothing is
     stretched and the margins decide the size. */
  function circle(g, cx, cy, r, op) {
    g.appendChild(svgEl("circle", { cx: cx, cy: cy, r: r, "stroke-opacity": op || "1" }));
  }
  function tri(g, r, down, op) {
    var pts = [], base = down ? Math.PI / 2 : -Math.PI / 2;
    for (var i = 0; i < 3; i++) {
      var a = base + (i / 3) * Math.PI * 2;
      pts.push((Math.cos(a) * r).toFixed(1) + "," + (Math.sin(a) * r).toFixed(1));
    }
    g.appendChild(svgEl("polygon", { points: pts.join(" "), "stroke-opacity": op || "1" }));
  }

  var MARGIN_FIGURES = [
    // vesica piscis: two circles meeting through each other's centre
    ["vesica", function (g) { circle(g, -32, 0, 64); circle(g, 32, 0, 64); }],
    // three rings, linked
    ["rings", function (g) {
      for (var i = 0; i < 3; i++) {
        var a = -Math.PI / 2 + (i / 3) * Math.PI * 2;
        circle(g, (Math.cos(a) * 30).toFixed(1), (Math.sin(a) * 30).toFixed(1), 54);
      }
    }],
    // a triangle held in a circle
    ["held", function (g) { circle(g, 0, 0, 86); tri(g, 86, false); }],
    // earth: the downward triangle, barred
    ["earth", function (g) {
      tri(g, 88, true);
      g.appendChild(svgEl("line", { x1: -46, y1: 28, x2: 46, y2: 28 }));
    }],
    // the squared circle: circle, square on its corner, circle again
    ["squared", function (g) {
      circle(g, 0, 0, 88);
      g.appendChild(svgEl("polygon", { points: "0,-88 88,0 0,88 -88,0" }));
      circle(g, 0, 0, 44);
    }],
    // an eye: two arcs and a pupil
    ["eye", function (g) {
      g.appendChild(svgEl("path", { d: "M-90,0 A 96,96 0 0,1 90,0 A 96,96 0 0,1 -90,0 Z" }));
      circle(g, 0, 0, 22);
      g.appendChild(svgEl("circle", { cx: 0, cy: 0, r: 7, fill: "#2E9BF0", "fill-opacity": ".5", stroke: "none" }));
    }]
  ];

  function marginSigils() {
    if (document.querySelector(".glyphs")) return;
    var host = document.createElement("div");
    host.className = "glyphs";
    host.setAttribute("aria-hidden", "true");

    // A turn and a little size on each, off one seed: arbitrary enough that
    // the six do not read as a set, fixed enough that the page looks the same
    // every time it opens. The stroke is divided by the scale so a figure
    // blown up does not come with a fatter line.
    var rnd = seeded(90126);
    MARGIN_FIGURES.forEach(function (f) {
      var turn = rnd() * 360, scale = 0.88 + rnd() * 0.3;
      var svg = svgEl("svg", {
        viewBox: "-100 -100 200 200",
        class: "gl gl-" + f[0],
        fill: "none", stroke: "#2E9BF0",
        "stroke-width": (1.15 / scale).toFixed(2)
      });
      svg.style.transform = "rotate(" + turn.toFixed(1) + "deg) scale(" + scale.toFixed(3) + ")";
      f[1](svg);
      host.appendChild(svg);
    });

    // Behind the content but in front of the vignette: the burn darkens the
    // edges hard, which is exactly where these live.
    var burn = document.querySelector(".burn");
    if (burn && burn.parentNode) burn.parentNode.insertBefore(host, burn.nextSibling);
    else document.body.appendChild(host);
  }

  /* ── the still geometry ──────────────────────────────────────────────
     Figures that do not turn, so the turning ones have something fixed to
     turn against: a hexagram, a square standing on its corner, a crown of
     spokes. Everything is a whisper — one pixel wide, a tenth opaque. */
  function sacred(o) {
    var g = svgEl("g", { fill: "none", stroke: o.colour, "stroke-width": "1" });

    function poly(r, sides, phase, op) {
      if (!r) return;
      var pts = [];
      for (var i = 0; i < sides; i++) {
        var a = phase + (i / sides) * Math.PI * 2;
        pts.push((Math.cos(a) * r).toFixed(1) + "," + (Math.sin(a) * r).toFixed(1));
      }
      g.appendChild(svgEl("polygon", { points: pts.join(" "), "stroke-opacity": op }));
    }

    poly(o.star, 3, -Math.PI / 2, o.starOp);   // the two triangles of a hexagram
    poly(o.star, 3, Math.PI / 2, o.starOp);
    poly(o.square, 4, 0, o.squareOp);          // on its corner, being at phase 0

    if (o.spokes) {
      var sp = svgEl("g", { "stroke-opacity": o.spokeOp });
      for (var k = 0; k < o.spokes; k++) {
        var b = (k / o.spokes) * Math.PI * 2, r1 = o.spokeR, r2 = r1 + o.spokeLen;
        sp.appendChild(svgEl("line", {
          x1: (Math.cos(b) * r1).toFixed(1), y1: (Math.sin(b) * r1).toFixed(1),
          x2: (Math.cos(b) * r2).toFixed(1), y2: (Math.sin(b) * r2).toFixed(1)
        }));
      }
      g.appendChild(sp);
    }
    return g;
  }

  function diagram(host, opts) {
    var R = 500;
    var svg = svgEl("svg", {
      viewBox: "-" + R + " -" + R + " " + (R * 2) + " " + (R * 2),
      role: "img",
      "aria-label": "План залу nøx: танцювальна зона з колонами, барна стійка 9,6 метра, санвузол, гардероб"
    });

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

    // marks turning around the plan, where the address used to run
    var runes = svgEl("g", { class: "spin-slow" });
    runes.appendChild(runeRing(405, 44, 9, "#B9E2FF", ".40", 31122026));
    svg.appendChild(runes);

    // a crown of spokes outside everything, fixed
    svg.appendChild(sacred({
      colour: "#2E9BF0", spokes: 36, spokeR: 484, spokeLen: 11, spokeOp: ".22"
    }));

    // cardinal reticles
    [[0, -470], [470, 0], [0, 470], [-470, 0]].forEach(function (p) {
      svg.appendChild(svgEl("circle", {
        cx: p[0], cy: p[1], r: 3.5, fill: "#2E9BF0", "fill-opacity": ".8"
      }));
    });

    // the real room in the middle
    var w = opts.planWidth || 745, h = w * (13400 / 20200);
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

    // Still first, so everything that turns turns over it. The hexagram and
    // the standing square sit inside the mark's own ring, which is hollow,
    // so they read through it.
    svg.appendChild(sacred({
      colour: "#2E9BF0",
      star: 248, starOp: ".13",
      square: 336, squareOp: ".10",
      spokes: 24, spokeR: 474, spokeLen: 15, spokeOp: ".20"
    }));

    var ring = svgEl("g", { class: "spin-slow" });
    ring.appendChild(runeRing(462, 54, 9, "#B9E2FF", ".46", 20260901));
    svg.appendChild(ring);

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
  /* The mark is the brightest thing on the screen; what burns off it is
     dimmer than it is, and dimmer again the further it gets. */
  var GAS = [46, 155, 240];    // the mark itself
  var EMB = [34, 112, 178];    // the burning edge, and a spark while it lives
  var ASH = [22, 74, 124];     // the far reach of a tongue, and a spark going out
  var SIZE = 300, PAD = 26;   // room around the mark for the flame to work in

  /* A tileable field of soft blobs. White noise smoothed a few times with a
     wrap at every edge, so it can be sampled with a bitmask and drift for
     ever without a seam. Two of these at different scales, drifting upward at
     different speeds, are what makes the mark look like it is burning. */
  function tileNoise(size, seed) {
    var n = size * size, a = new Float32Array(n), b = new Float32Array(n), i;
    var s = seed >>> 0;
    for (i = 0; i < n; i++) { s = (s * 1664525 + 1013904223) >>> 0; a[i] = s / 4294967296; }
    for (var pass = 0; pass < 3; pass++) {
      for (var y = 0; y < size; y++) {
        var yc = y * size,
            yn = ((y - 1 + size) % size) * size,
            yp = ((y + 1) % size) * size;
        for (var x = 0; x < size; x++) {
          var xn = (x - 1 + size) % size, xp = (x + 1) % size;
          b[yc + x] = (a[yc + x] * 4 + a[yc + xn] + a[yc + xp] + a[yn + x] + a[yp + x]) / 8;
        }
      }
      a.set(b);
    }
    var lo = Infinity, hi = -Infinity;
    for (i = 0; i < n; i++) { if (a[i] < lo) lo = a[i]; if (a[i] > hi) hi = a[i]; }
    var k = hi > lo ? 1 / (hi - lo) : 1;
    for (i = 0; i < n; i++) a[i] = (a[i] - lo) * k;
    return a;
  }
  var FLAME = tileNoise(128, 20260831);   // the tongues
  var EMBER = tileNoise(32, 7734);        // the finer crawl inside them

  // Builds a paint(progress) for one canvas. The source alpha and each cell's
  // distance from the centre are measured once; a repaint is then just a
  // threshold comparison over 90k cells, which is cheap enough per frame.
  function markPainter(canvas, ready) {
    var img = new Image();
    img.decoding = "async";
    img.onload = function () {
      // The mark is drawn inset, not edge to edge: the artwork all but touches
      // the top of its own viewBox, and tongues and sparks need somewhere to
      // go. The canvas is shown correspondingly larger, so the mark itself is
      // the same size on the screen as it ever was.
      var g = document.createElement("canvas");
      g.width = g.height = SIZE;
      var gx = g.getContext("2d");
      gx.drawImage(img, PAD, PAD, SIZE - PAD * 2, SIZE - PAD * 2);
      var src = gx.getImageData(0, 0, SIZE, SIZE).data;

      var n = SIZE * SIZE;
      var alpha = new Float32Array(n);
      var rad = new Float32Array(n);
      var thr = new Float32Array(n);
      var mid = SIZE / 2, half = (SIZE - PAD * 2) / 2;
      for (var y = 0; y < SIZE; y++) {
        for (var x = 0; x < SIZE; x++) {
          var i = y * SIZE + x;
          alpha[i] = src[i * 4 + 3] / 255;
          rad[i] = Math.sqrt((x - mid) * (x - mid) + (y - mid) * (y - mid)) / half;
          thr[i] = (BAYER[y & 7][x & 7] + 0.5) / 64;
        }
      }

      // The silhouette: solid cells with a hollow neighbour. This is where the
      // tongues stand and where the sparks leave from.
      var rimList = [];
      for (var ry = 1; ry < SIZE - 1; ry++) {
        for (var rx = 1; rx < SIZE - 1; rx++) {
          var ri = ry * SIZE + rx;
          if (alpha[ri] > 0.5 && (alpha[ri - 1] < 0.5 || alpha[ri + 1] < 0.5 ||
                                  alpha[ri - SIZE] < 0.5 || alpha[ri + SIZE] < 0.5)) rimList.push(ri);
        }
      }
      var RIM = new Int32Array(rimList);

      // The halo the tongues stand in. It leans upward: each pass draws more
      // from the cell below than the one above, so the reach above the mark is
      // roughly three times the reach beneath it. Flames rise.
      var spill = new Float32Array(alpha), sTmp = new Float32Array(n);
      for (var sp = 0; sp < 8; sp++) {
        for (var sy = 1; sy < SIZE - 1; sy++) {
          for (var sx = 1; sx < SIZE - 1; sx++) {
            var si = sy * SIZE + sx;
            sTmp[si] = (spill[si] * 2 + spill[si - 1] + spill[si + 1]
                      + spill[si - SIZE] * 0.45 + spill[si + SIZE] * 2.1) / 6.55;
          }
        }
        spill.set(sTmp);
      }

      canvas.width = canvas.height = SIZE;
      var ctx = canvas.getContext("2d");
      var out = ctx.createImageData(SIZE, SIZE);
      var od = out.data;

      /* The mark burns at its edge. Two tileable fields of soft blobs drift
         upward at different speeds; how deep a cell sits inside the solid
         decides how much they can move its threshold, so the body of the mark
         holds its own colour and only the rim is worked on.

         Above the silhouette the same fields light small tongues in the halo,
         and sparks leave the rim and rise until they go out. The hot colours
         are kept to where a tongue is actually passing — judging by the margin
         alone peppered the whole mark with embers and turned it white.

         Nothing is ever lit below FLOOR. Without it the flame could push a
         threshold under zero and the mark would show through before the
         scroll had begun to assemble it. */
      var FLOOR = 0.02, BURN = 0.22, TONGUE = 11.5;
      var SPARKS = 130, spark = [], spawn = 0, tPrev = -1;

      function paint(p, t) {
        var eased = p * p * (3 - 2 * p);
        var o1 = (t * 13) | 0, o2 = (t * 5) | 0;
        var dt = tPrev < 0 ? 0 : Math.min(0.12, t - tPrev);
        tPrev = t;

        for (var y = 0; y < SIZE; y++) {
          var row = y * SIZE;
          var r1 = ((y + o1) & 127) << 7, r2 = ((y + o2) & 31) << 5;
          for (var x = 0; x < SIZE; x++) {
            var i = row + x, j = i << 2;
            var flame = FLAME[r1 + (x & 127)] * 0.64 + EMBER[r2 + (x & 31)] * 0.36;
            var a = alpha[i];

            if (a === 0) {
              // a tongue: only where the halo still reaches and the field is
              // near its crest, so they stay short and come in patches
              var lick = spill[i] * TONGUE * Math.max(0, flame - 0.46) * eased;
              if (lick > thr[i]) {
                var ct = lick > thr[i] * 2.2 ? EMB : ASH;
                od[j] = ct[0]; od[j + 1] = ct[1]; od[j + 2] = ct[2]; od[j + 3] = 255;
              } else {
                od[j + 3] = 0;
              }
              continue;
            }

            var r = rad[i];
            // settled shape: solid core, dissolving rim
            var settled = 1 - Math.min(1, Math.max(0, (r - 0.46) / 0.60)) * 0.58;
            // arrival: the centre clears the threshold first
            var arriving = eased * (1.35 - 0.55 * r);
            var keep = a * Math.min(arriving, settled);

            var edge = 1 - keep; if (edge < 0) edge = 0;
            var lim = thr[i] + BURN * edge * edge * (flame - 0.42);
            if (lim < FLOOR) lim = FLOOR;

            if (keep > lim) {
              var c = GAS;
              if (edge > 0.24 && flame > 0.70 && keep - lim < 0.07) c = EMB;
              od[j] = c[0]; od[j + 1] = c[1]; od[j + 2] = c[2]; od[j + 3] = 255;
            } else {
              od[j + 3] = 0;
            }
          }
        }

        // ── sparks ────────────────────────────────────────────────────────
        // They leave the silhouette, rise, wander, and go out. A spark is one
        // cell: at this scale that is a couple of screen pixels, which is the
        // whole point — it should read as an ember, not as a dot of paint.
        if (RIM.length && eased > 0.35) {
          spawn += dt * (7 + 22 * eased);
          while (spawn >= 1) {
            spawn -= 1;
            if (spark.length >= SPARKS) break;
            var seed = RIM[(Math.random() * RIM.length) | 0];
            spark.push({
              x: seed % SIZE, y: (seed / SIZE) | 0,
              vx: (Math.random() - 0.5) * 7,
              vy: -(9 + Math.random() * 19),
              age: 0, life: 1.8 + Math.random() * 2.6,
              wob: Math.random() * 6.28
            });
          }
        }
        for (var k = spark.length - 1; k >= 0; k--) {
          var s = spark[k];
          s.age += dt;
          s.vy -= 3.5 * dt;                     // they keep gathering height
          s.x += (s.vx + Math.sin(t * 1.05 + s.wob) * 5) * dt;
          s.y += s.vy * dt;
          var sxi = s.x | 0, syi = s.y | 0;
          if (s.age >= s.life || sxi < 0 || syi < 0 || sxi >= SIZE || syi >= SIZE) {
            spark.splice(k, 1);
            continue;
          }
          var u = s.age / s.life;
          // an ember does not fade, it starts missing beats and then stops
          if (u > 0.5 && (((t * 11 + k) | 0) & 1)) continue;
          var cs = u < 0.45 ? EMB : ASH;
          var js = ((syi * SIZE) + sxi) << 2;
          od[js] = cs[0]; od[js + 1] = cs[1]; od[js + 2] = cs[2]; od[js + 3] = 255;
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

    // The menu waits for the mark. Only this page has an opening, and only the
    // script veils the bar, so every other page — and a page with no JS — keeps
    // its navigation from the first frame.
    var bar = document.getElementById("bar");
    if (bar) bar.classList.add("veiled");

    markPainter(canvas, function (paint) {
      if (REDUCED) {
        pin.style.setProperty("--p", "1");
        mark.classList.add("done");
        if (bar) bar.classList.add("shown");
        paint(1, 0);
        return;
      }

      var target = 0, cur = 0, running = false;

      function read() {
        var span = host.offsetHeight - innerHeight;
        var p = span > 0 ? -host.getBoundingClientRect().top / span : 1;
        return Math.min(1, Math.max(0, p));
      }

      // The mark assembles over the first 45% of the scene and then simply
      // stands there. The words do not start until .58, so there is a real
      // beat where the whole mark is the only thing on the screen.
      var ASSEMBLE = 0.45;
      var built = 0;                       // how far the mark itself has come

      function apply(p) {
        var d = Math.min(1, p / ASSEMBLE);
        built = d;
        pin.style.setProperty("--p", p.toFixed(4));
        STATE.reveal = d;
        mark.classList.toggle("done", d > 0.995);
        // The bar arrives with the mark whole, a beat before the first words.
        if (bar) bar.classList.toggle("shown", d > 0.98);
      }

      // The mark is never a still image: the dither keeps working whether the
      // page is moving or not. ~22 fps — a shimmer at full frame rate reads as
      // noise, and this is a third of the work.
      var t0 = performance.now(), drawn = 0, awake = true;
      document.addEventListener("visibilitychange", function () { awake = !document.hidden; });
      (function shimmer(now) {
        requestAnimationFrame(shimmer);
        if (!awake || now - drawn < 45) return;
        // Nothing to keep alive once the scene has left the screen — and in
        // the one-file preview a hidden route measures as a zero-height box.
        var r = host.getBoundingClientRect();
        if (r.bottom <= 0 || r.top >= innerHeight) return;
        drawn = now;
        paint(built, (now - t0) / 1000);
      })(t0);

      // The opening trails the scroll instead of snapping to it, so a wheel
      // notch reads as a glide rather than a jump.
      function tick() {
        cur += (target - cur) * 0.22;
        if (Math.abs(target - cur) < 0.0004) { cur = target; running = false; }
        else requestAnimationFrame(tick);
        apply(cur);
      }

      function schedule() {
        target = read();
        if (!running) { running = true; requestAnimationFrame(tick); }
      }

      addEventListener("scroll", schedule, { passive: true });
      addEventListener("resize", schedule, { passive: true });
      // The one-file preview keeps every route in one document behind one bar,
      // and switching route need not scroll. Remeasure so the bar is not left
      // veiled on a page that has no opening of its own.
      document.addEventListener("nox:route", schedule);
      target = cur = read();
      apply(cur);
      paint(built, 0);
    });
  }

  /* ── 2b. the wheel gets some weight ──────────────────────────────────
     A mouse wheel arrives in coarse notches and the page lurches one notch at
     a time. Here the notches only set a destination and the page glides to it.
     Anything that already sends a smooth stream is left alone: a trackpad's
     small or fractional deltas, a pinch or a modifier, a reader who asked for
     less motion, and any scrollable box under the cursor all fall through to
     the browser untouched. */
  function inertia() {
    if (REDUCED) return;

    var target = scrollY, running = false, own = false;

    // We ease the page ourselves now; leaving the CSS easing on would put the
    // browser's smoothing on top of every frame of ours and drag the page.
    document.documentElement.style.scrollBehavior = "auto";

    function limit() {
      return Math.max(0, document.documentElement.scrollHeight - innerHeight);
    }

    function scrollableUnder(node) {
      for (var n = node; n && n !== document.body; n = n.parentElement) {
        if (n.scrollHeight > n.clientHeight + 1) {
          var ov = getComputedStyle(n).overflowY;
          if (ov === "auto" || ov === "scroll") return true;
        }
      }
      return false;
    }

    function tick() {
      var d = target - scrollY;
      if (Math.abs(d) < 0.25) { running = false; own = false; return; }
      scrollTo(0, scrollY + d * 0.038);
      requestAnimationFrame(tick);
    }

    addEventListener("wheel", function (e) {
      if (e.ctrlKey || e.altKey || e.metaKey || e.shiftKey || e.defaultPrevented) return;
      var dy = e.deltaY;
      if (!dy) return;

      // A notch: whole lines/pages, or a whole-pixel delta big enough that no
      // trackpad would send it. Everything else is already smooth.
      var notch = e.deltaMode !== 0 || (Math.abs(dy) >= 40 && dy === Math.round(dy));
      if (!notch) { own = false; return; }
      if (scrollableUnder(e.target)) return;

      e.preventDefault();
      if (!own) { target = scrollY; own = true; }
      var px = e.deltaMode === 1 ? dy * 42 : e.deltaMode === 2 ? dy * innerHeight : dy;
      target = Math.min(limit(), Math.max(0, target + px * 1.55));
      if (!running) { running = true; requestAnimationFrame(tick); }
    }, { passive: false });

    // Any scroll we did not drive — a drag of the bar, a key, a jump to an
    // anchor — becomes the new starting point.
    addEventListener("scroll", function () { if (!running) target = scrollY; }, { passive: true });
  }

  /* Who owns the scroll. On the site it is the page itself. The one-file
     preview keeps three routes in one document and gives a route its own box
     to scroll — then the document stands still while the reader moves, and
     anything that reads the page's scroll reads nothing. A box that owns the
     scroll says so with data-nox-scroller. */
  function scrollerEl() {
    return document.querySelector("[data-nox-scroller]:not([hidden])");
  }

  function scrollProgress() {
    var el = scrollerEl();
    var y = el ? el.scrollTop : (pageYOffset || document.documentElement.scrollTop || 0);
    var span = el ? (el.scrollHeight - el.clientHeight)
                  : (document.documentElement.scrollHeight - innerHeight);
    return span > 0 ? Math.min(1, Math.max(0, y / span)) : 0;
  }

  /* ── the waveform ───────────────────────────────────────────────────
     A dense stack of short horizontal dashes in the left gutter, all anchored
     to the left edge. At rest they are stubs. The swell rides the scroll
     position down the stack: the lines the reader is level with grow out
     across the gutter and shrink back once the playhead has passed. Nothing
     bends — the only thing that changes is length. */
  function waveform() {
    var cv = document.getElementById("wave");
    if (!cv) return;
    var ctx = cv.getContext("2d");
    if (!ctx) return;

    var LINES = 110,      // how many dashes fill the gutter
        SPREAD = 9,       // how many lines either side of the playhead react
        REST = 0.30,      // length at rest, as a fraction of the gutter
        REST_MIN = 12;    // …but never shorter than this, the gutter is narrow
    var dpr = Math.min(devicePixelRatio || 1, 2);
    var W = 0, H = 0;

    // A stable per-line ripple so the stack reads as a signal, not an envelope.
    var jitter = new Float32Array(LINES);
    for (var j = 0; j < LINES; j++) {
      var s = Math.sin(j * 12.9898) * 43758.5453;
      jitter[j] = 0.62 + 0.38 * (s - Math.floor(s));
    }

    function size() {
      var r = cv.getBoundingClientRect();
      W = r.width; H = r.height;
      cv.width = Math.max(1, Math.round(W * dpr));
      cv.height = Math.max(1, Math.round(H * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    addEventListener("resize", size, { passive: true });
    size();

    var visible = true;
    document.addEventListener("visibilitychange", function () { visible = !document.hidden; });

    var p = scrollProgress();
    (function frame(now) {
      requestAnimationFrame(frame);
      if (!visible || W < 8) return;

      var t = now / 1000;
      p += (scrollProgress() - p) * 0.09;    // the swell follows, it does not snap
      ctx.clearRect(0, 0, W, H);
      ctx.lineWidth = 1;

      var gap = H / (LINES + 1);
      var focus = p * (LINES - 1);           // which line the reader is level with
      var rest = Math.min(W * 0.62, Math.max(REST_MIN, W * REST));

      for (var i = 0; i < LINES; i++) {
        var y = Math.round(gap * (i + 1)) + 0.5;   // crisp hairlines
        var away = (i - focus) / SPREAD;
        var env = Math.exp(-away * away);          // long at the playhead, stubs away from it
        var beat = REDUCED ? 1 : 0.80 + 0.20 * Math.sin(t * 2.3 + i * 0.42);
        var breath = REDUCED ? 0 : 0.018 * Math.sin(t * 1.4 + i * 0.9);

        var len = rest * (1 + breath) + (W - rest) * env * beat * jitter[i];
        if (len < 2) len = 2;

        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(len, y);
        ctx.strokeStyle = "rgba(46,155,240," + (0.22 + 0.58 * env).toFixed(3) + ")";
        ctx.stroke();
      }
    })(performance.now());
  }

  /* ── 3. the wordmark stutters now and then ──────────────────────── */
  function glitch() {
    var stage = document.querySelector(".stagein");
    var h1 = stage && stage.querySelector("h1");
    // The opening now shows the sigil alone; the wordmark is there for
    // semantics only, and there is nothing on screen for it to stutter.
    if (!h1 || REDUCED || h1.classList.contains("off")) return;
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

  /* ── the page opens at the top ───────────────────────────────────────
     A browser restores the last scroll position on reload, and the one-file
     preview restores its own from sessionStorage — neither is how the opening
     is meant to be met. We take the top back on boot and again once the load
     handlers have had their say. */
  function fromTheTop() {
    try { history.scrollRestoration = "manual"; } catch (e) {}

    // One shot is not enough: the browser restores after load, and the preview
    // shell restores again when the page is promoted from thumbnail to full
    // view. So we hold the top for a couple of seconds — and let go the moment
    // the reader touches anything, so we never fight a real gesture.
    var held = true;
    function release() { held = false; }
    ["wheel", "touchstart", "keydown", "pointerdown"].forEach(function (t) {
      addEventListener(t, release, { passive: true, once: true });
    });

    function top() { if (held && scrollY !== 0) scrollTo(0, 0); }
    addEventListener("scroll", top, { passive: true });

    var until = (performance.now ? performance.now() : Date.now()) + 2200;
    (function hold() {
      top();
      var now = performance.now ? performance.now() : Date.now();
      if (held && now < until) requestAnimationFrame(hold);
      else removeEventListener("scroll", top);
    })();
  }

  function boot() {
    fromTheTop();
    voidLayer();
    marginSigils();
    waveform();
    glitch();
    reveals();
    inertia();
    var sig = document.querySelector(".sigil");
    if (sig) heroSigil(sig);
    revealScroll();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else { boot(); }
})();
