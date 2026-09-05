/* ==========================================================================
   Bühnengrafik — zwei Ebenen auf Canvas, ohne externe Bibliothek.

   1. Ein Netz aus Knoten und Verbindungen, das auf den Zeiger reagiert.
   2. Ein dreidimensionales Drahtmodell aus Ring und Schild, das sich dreht.
      Die Projektion ist von Hand gerechnet: Rotationsmatrix, dann
      perspektivische Teilung. Das spart eine 600 KB große 3D-Bibliothek
      und hält die Content-Security-Policy eng.
   ========================================================================== */
(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

  /* Bereitet einen Canvas auf die Pixeldichte des Geräts vor.
     onChange wird immer dann gerufen, wenn sich die Größe ändert — auch wenn
     das Element beim Laden noch keine hatte. */
  function setup(canvas, onChange) {
    var ctx = canvas.getContext('2d');
    var dpr = Math.min(window.devicePixelRatio || 1, 2);

    function resize() {
      var r = canvas.getBoundingClientRect();
      canvas.width  = Math.max(1, Math.round(r.width  * dpr));
      canvas.height = Math.max(1, Math.round(r.height * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      return { w: r.width, h: r.height };
    }

    if (typeof onChange === 'function') {
      if ('ResizeObserver' in window) {
        new ResizeObserver(function () { onChange(resize()); }).observe(canvas);
      } else {
        window.addEventListener('resize', function () { onChange(resize()); });
      }
      // Schriften ändern das Layout nachträglich
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () { onChange(resize()); });
      }
    }

    return { ctx: ctx, resize: resize };
  }

  /* Führt eine Zeichenschleife, die nur läuft, solange das Element sichtbar
     ist — im Hintergrund liegende Seiten kosten so keine Rechenzeit. */
  function loop(canvas, draw) {
    var running = false, raf = 0, t0 = performance.now();

    function frame(now) {
      if (!running) return;
      draw((now - t0) / 1000);
      raf = requestAnimationFrame(frame);
    }
    function start() { if (running) return; running = true; raf = requestAnimationFrame(frame); }
    function stop()  { running = false; cancelAnimationFrame(raf); }

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (es) {
        es[0].isIntersecting ? start() : stop();
      }, { threshold: 0 }).observe(canvas);
    } else {
      start();
    }
    document.addEventListener('visibilitychange', function () {
      document.hidden ? stop() : start();
    });
    return { start: start, stop: stop };
  }

  /* ---------------------------------------------------------------------
     Ebene 1 — Knotennetz
     --------------------------------------------------------------------- */
  function network(canvas) {
    var nodes = [];
    var pointer = { x: -9999, y: -9999, active: false };
    var size;
    var s = setup(canvas, function (dim) { size = dim; build(); });
    var ctx = s.ctx;
    size = s.resize();

    function build() {
      size = s.resize();
      var count = Math.round(Math.min(84, (size.w * size.h) / 15000));
      nodes = [];
      for (var i = 0; i < count; i++) {
        nodes.push({
          x: Math.random() * size.w,
          y: Math.random() * size.h,
          vx: (Math.random() - 0.5) * 0.16,
          vy: (Math.random() - 0.5) * 0.16,
          r: Math.random() * 1.3 + 0.5
        });
      }
    }

    build();

    canvas.parentElement.addEventListener('pointermove', function (e) {
      var r = canvas.getBoundingClientRect();
      pointer.x = e.clientX - r.left;
      pointer.y = e.clientY - r.top;
      pointer.active = true;
    });
    canvas.parentElement.addEventListener('pointerleave', function () { pointer.active = false; });

    var LINK = 132;

    function draw() {
      if (!size || size.w < 2 || size.h < 2) return;
      ctx.clearRect(0, 0, size.w, size.h);

      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        n.x += n.vx; n.y += n.vy;
        if (n.x < 0 || n.x > size.w) n.vx *= -1;
        if (n.y < 0 || n.y > size.h) n.vy *= -1;

        // Sanfte Anziehung zum Zeiger
        if (pointer.active) {
          var dx = pointer.x - n.x, dy = pointer.y - n.y;
          var d2 = dx * dx + dy * dy;
          if (d2 < 26000 && d2 > 1) {
            var f = 0.00022;
            n.vx += dx * f; n.vy += dy * f;
            var sp = Math.hypot(n.vx, n.vy);
            if (sp > 0.7) { n.vx = n.vx / sp * 0.7; n.vy = n.vy / sp * 0.7; }
          }
        }
      }

      // Verbindungen
      for (var a = 0; a < nodes.length; a++) {
        for (var b = a + 1; b < nodes.length; b++) {
          var p = nodes[a], q = nodes[b];
          var ddx = p.x - q.x, ddy = p.y - q.y;
          var dist = Math.hypot(ddx, ddy);
          if (dist > LINK) continue;
          var alpha = (1 - dist / LINK) * 0.3;
          ctx.strokeStyle = 'rgba(212,167,60,' + alpha.toFixed(3) + ')';
          ctx.lineWidth = 0.7;
          ctx.beginPath();
          ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y);
          ctx.stroke();
        }
      }

      // Knoten
      for (var k = 0; k < nodes.length; k++) {
        var m = nodes[k];
        ctx.fillStyle = 'rgba(227,191,98,.62)';
        ctx.beginPath();
        ctx.arc(m.x, m.y, m.r, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    if (reduced.matches) { draw(); return; }
    loop(canvas, draw);
  }

  /* ---------------------------------------------------------------------
     Ebene 2 — Drahtmodell aus Ring und Schild
     --------------------------------------------------------------------- */
  function object3d(canvas) {
    var size;
    var s = setup(canvas, function (dim) { size = dim; });
    var ctx = s.ctx;
    size = s.resize();

    // Zeigerposition steuert die Neigung
    var aim = { x: 0, y: 0 }, cur = { x: 0, y: 0 };
    window.addEventListener('pointermove', function (e) {
      aim.x = (e.clientX / window.innerWidth  - 0.5) * 0.55;
      aim.y = (e.clientY / window.innerHeight - 0.5) * 0.42;
    });

    // --- Geometrie ------------------------------------------------------
    // Schildumriss in der xy-Ebene, danach in z-Richtung ausgeformt.
    var outline = [
      [  0, -108], [ 62,  -82], [ 62,  -6],
      [ 47,   52], [  0,  104], [-47,  52],
      [-62,   -6], [-62,  -82]
    ];

    var shield = { pts: [], edges: [] };
    var DEPTH = 17;
    outline.forEach(function (p) { shield.pts.push([p[0], p[1], -DEPTH]); });
    outline.forEach(function (p) { shield.pts.push([p[0], p[1],  DEPTH]); });
    var N = outline.length;
    for (var i = 0; i < N; i++) {
      shield.edges.push([i, (i + 1) % N]);                    // Vorderkante
      shield.edges.push([N + i, N + ((i + 1) % N)]);          // Rückkante
      shield.edges.push([i, N + i]);                          // Verbindung
    }

    // Das W aus der Wortmarke, als Linienzug in der Schildmitte
    var wPath = [[-34, -18], [-17, 38], [0, -2], [17, 38], [34, -18]];
    var wIdx = shield.pts.length;
    wPath.forEach(function (p) { shield.pts.push([p[0], p[1], DEPTH + 4]); });
    for (var w = 0; w < wPath.length - 1; w++) shield.edges.push([wIdx + w, wIdx + w + 1]);

    // Umlaufender Ring
    var ring = { pts: [], edges: [] };
    var RSEG = 64, RAD = 150;
    for (var r = 0; r < RSEG; r++) {
      var a = (r / RSEG) * Math.PI * 2;
      ring.pts.push([Math.cos(a) * RAD, Math.sin(a) * RAD, 0]);
      ring.edges.push([r, (r + 1) % RSEG]);
    }

    // Frei schwebende Punkte als Tiefenandeutung
    var motes = [];
    for (var m = 0; m < 34; m++) {
      var th = Math.random() * Math.PI * 2, ph = Math.acos(2 * Math.random() - 1);
      var rr = 175 + Math.random() * 95;
      motes.push({
        p: [rr * Math.sin(ph) * Math.cos(th), rr * Math.sin(ph) * Math.sin(th), rr * Math.cos(ph)],
        sp: 0.15 + Math.random() * 0.4
      });
    }

    // --- Projektion -----------------------------------------------------
    function rotate(p, rx, ry) {
      var x = p[0], y = p[1], z = p[2];
      var cy = Math.cos(ry), sy = Math.sin(ry);
      var x1 = x * cy + z * sy, z1 = -x * sy + z * cy;
      var cx = Math.cos(rx), sx = Math.sin(rx);
      var y1 = y * cx - z1 * sx, z2 = y * sx + z1 * cx;
      return [x1, y1, z2];
    }

    function project(p, cxp, cyp, scale) {
      var FOV = 620;
      var f = FOV / (FOV + p[2] + 300);
      return [cxp + p[0] * f * scale, cyp + p[1] * f * scale, f];
    }

    // Bildet die tatsächliche Tiefenspanne auf einen gut sichtbaren
    // Deckkraftbereich ab: hinten 0,22 — vorn 1,0.
    var NEAR = 0.82, FAR = 0.57;
    function depthAlpha(d) {
      var t = (d - FAR) / (NEAR - FAR);
      return 0.22 + Math.max(0, Math.min(1, t)) * 0.78;
    }

    var wProgress = 1;

    function drawFrame(geo, rx, ry, cxp, cyp, scale, baseAlpha, width) {
      var proj = geo.pts.map(function (p) { return project(rotate(p, rx, ry), cxp, cyp, scale); });

      ctx.lineCap = 'round';
      geo.edges.forEach(function (e, ei) {
        // Die letzten Kanten bilden das W — es erscheint zuletzt und zeichnet sich nach
        var isW = geo === shield && ei >= geo.edges.length - (wPath.length - 1);
        if (isW) {
          var seg = (wPath.length - 1);
          var mine = (ei - (geo.edges.length - seg)) / seg;
          if (wProgress <= mine) return;
        }
        var A = proj[e[0]], B = proj[e[1]];
        var depth = (A[2] + B[2]) / 2;              // vorn = größer
        var alpha = depthAlpha(depth) * baseAlpha;
        if (alpha < 0.02) return;

        // Vordere Kanten glimmen — gibt dem Drahtmodell Tiefe
        ctx.shadowBlur = 14 * Math.max(0, (depth - 0.68) * 3);
        ctx.shadowColor = 'rgba(212,167,60,.55)';
        ctx.strokeStyle = 'rgba(240,208,124,' + alpha.toFixed(3) + ')';
        ctx.lineWidth = width * (0.55 + depth);
        ctx.beginPath();
        ctx.moveTo(A[0], A[1]); ctx.lineTo(B[0], B[1]);
        ctx.stroke();
      });
      ctx.shadowBlur = 0;
      return proj;
    }

    // Eröffnung: Ring zuerst, dann das Schild, zuletzt das W.
    // Ohne die Sequenz wirkt das Signet wie ein statisches Bild, das sich dreht;
    // mit ihr wie ein Zeichen, das gerade entsteht.
    var INTRO = reduced.matches ? 0 : 2.6;
    function ease(x) { return x <= 0 ? 0 : x >= 1 ? 1 : 1 - Math.pow(1 - x, 3); }

    function draw(t) {
      if (!size || size.w < 2 || size.h < 2) return;
      ctx.clearRect(0, 0, size.w, size.h);

      var cxp = size.w / 2, cyp = size.h / 2;
      var scale = Math.min(size.w, size.h) / 255;

      cur.x += (aim.x - cur.x) * 0.045;
      cur.y += (aim.y - cur.y) * 0.045;

      var intro = INTRO ? Math.min(1, t / INTRO) : 1;
      var ringIn   = ease(intro / 0.42);
      var shieldIn = ease((intro - 0.22) / 0.46);
      var wIn      = ease((intro - 0.55) / 0.45);

      // Das Schild pendelt um die Frontalansicht, statt voll durchzudrehen —
      // sonst steht das W auf der Kante und verschwindet.
      // Der Ring dreht weiter voll; der Gegensatz erzeugt die Tiefe.
      //
      // Wichtig: Pendeln und Zeigereinfluss addieren sich. Ohne die Klemme
      // unten summierten sie sich auf über 60 Grad — und genau dann kippt das
      // W weg. Der Gesamtwinkel bleibt deshalb hart begrenzt.
      var MAX_TURN = 0.46;                    // rund 26 Grad
      var spin = reduced.matches ? 0.45 : t * 0.24;
      var sway = reduced.matches ? 0.30 : Math.sin(t * 0.42) * 0.30;
      var turn = sway + cur.x * 0.34;
      if (turn >  MAX_TURN) turn =  MAX_TURN;
      if (turn < -MAX_TURN) turn = -MAX_TURN;
      var ry = turn + (1 - ease(intro)) * 1.1;   // Anschwung nur während der Eröffnung
      var rx = -0.14 + Math.max(-0.20, Math.min(0.20, cur.y * 0.5));
      scale *= 0.86 + 0.14 * ease(intro);

      // Ring liegt quer und dreht gegenläufig
      if (ringIn > 0.01) drawFrame(ring, rx + 1.32, -spin * 0.7 + cur.x, cxp, cyp, scale, 0.72 * ringIn, 1.1);

      // Schild
      wProgress = wIn;
      var proj = shieldIn > 0.01 ? drawFrame(shield, rx, ry, cxp, cyp, scale, 0.95 * shieldIn, 1.5) : [];
      if (!proj.length) { drawMotes(rx, spin, cxp, cyp, scale, ringIn); return; }

      // Eckpunkte des Schildes hervorheben
      for (var i = 0; i < N * 2; i++) {
        var P = proj[i];
        if (P[2] < 0.66) continue;
        ctx.shadowBlur = 10; ctx.shadowColor = 'rgba(247,227,161,.8)';
        ctx.fillStyle = 'rgba(250,236,190,' + depthAlpha(P[2]).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(P[0], P[1], 1.6 + 1.4 * P[2], 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;
      }

      drawMotes(rx, spin, cxp, cyp, scale, 1);
    }

    function drawMotes(rx, spin, cxp, cyp, scale, alpha) {
      motes.forEach(function (mo, i) {
        var q = rotate(mo.p, rx * 0.5, spin * mo.sp + i);
        var Q = project(q, cxp, cyp, scale);
        if (Q[2] < 0.58) return;
        ctx.fillStyle = 'rgba(212,167,60,' + (depthAlpha(Q[2]) * 0.55 * alpha).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(Q[0], Q[1], 0.9 + 1.1 * Q[2], 0, Math.PI * 2);
        ctx.fill();
      });
    }

    if (reduced.matches) { draw(0); return; }
    loop(canvas, draw);
  }

  /* --- Start ------------------------------------------------------------ */
  var net = document.querySelector('[data-scene="network"]');
  if (net) network(net);

  var obj = document.querySelector('[data-scene="object"]');
  if (obj) object3d(obj);
})();
