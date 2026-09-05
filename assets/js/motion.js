/* ==========================================================================
   Bewegung im Seitenaufbau.
   Alles hier ist Zugabe: Ohne JavaScript oder bei reduzierter Bewegung
   bleibt die Seite vollständig lesbar und bedienbar.
   ========================================================================== */
(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* --- Einblenden beim Scrollen ---------------------------------------- */
  var targets = document.querySelectorAll('.reveal, .line-in');

  if (reduced || !('IntersectionObserver' in window)) {
    targets.forEach(function (el) { el.classList.add('shown'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('shown');
        io.unobserve(e.target);
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.06 });

    targets.forEach(function (el) { io.observe(el); });

    // Sicherheitsnetz: Inhalt darf nie dauerhaft unsichtbar bleiben.
    window.setTimeout(function () {
      targets.forEach(function (el) { el.classList.add('shown'); });
    }, 2600);
  }

  /* --- Signet läuft beim Scrollen langsamer als die Seite ---------------- */
  var stage = document.querySelector('.hero__stage');
  if (stage && !reduced) {
    var pRaf = false;
    function parallax() {
      if (pRaf) return; pRaf = true;
      requestAnimationFrame(function () {
        var y = window.scrollY;
        if (y < window.innerHeight * 1.2) {
          stage.style.transform = 'translate3d(0,' + (y * 0.18).toFixed(1) + 'px,0)';
          stage.style.opacity = String(Math.max(0, 1 - y / (window.innerHeight * 0.9)));
        }
        pRaf = false;
      });
    }
    window.addEventListener('scroll', parallax, { passive: true });
  }

  /* --- Lichtkegel folgt dem Zeiger über der Bühne ------------------------ */
  var hero = document.querySelector('.hero');
  var light = document.querySelector('.hero__light');
  if (hero && light && finePointer && !reduced) {
    var lRaf = 0;
    hero.addEventListener('pointermove', function (e) {
      if (lRaf) return;
      lRaf = requestAnimationFrame(function () {
        var r = hero.getBoundingClientRect();
        light.style.setProperty('--lx', ((e.clientX - r.left) / r.width * 100).toFixed(1) + '%');
        light.style.setProperty('--ly', ((e.clientY - r.top) / r.height * 100).toFixed(1) + '%');
        lRaf = 0;
      });
    });
  }

  /* --- Handlungsleiste auf kleinen Schirmen ------------------------------- */
  var sticky = document.querySelector('[data-sticky-cta]');
  if (sticky && !document.querySelector('[data-funnel]')) {
    var sRaf = false;
    function stickyCheck() {
      if (sRaf) return; sRaf = true;
      requestAnimationFrame(function () {
        var nearEnd = window.innerHeight + window.scrollY > document.documentElement.scrollHeight - 320;
        sticky.classList.toggle('is-on', window.scrollY > window.innerHeight * 0.9 && !nearEnd);
        sRaf = false;
      });
    }
    window.addEventListener('scroll', stickyCheck, { passive: true });
    stickyCheck();
  } else if (sticky) {
    sticky.remove();
  }

  /* --- Fortschrittsbalken ---------------------------------------------- */
  var bar = document.querySelector('.scroll-progress');
  if (bar) {
    var ticking = false;
    function progress() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        var max = document.documentElement.scrollHeight - window.innerHeight;
        bar.style.transform = 'scaleX(' + (max > 0 ? window.scrollY / max : 0) + ')';
        ticking = false;
      });
    }
    window.addEventListener('scroll', progress, { passive: true });
    progress();
  }

  /* --- Zahlen zählen hoch ----------------------------------------------- */
  var counters = document.querySelectorAll('[data-count]');
  if (counters.length) {
    function run(el) {
      var target = parseFloat(el.getAttribute('data-count'));
      var suffix = el.getAttribute('data-suffix') || '';
      var prefix = el.getAttribute('data-prefix') || '';
      var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);

      if (reduced || isNaN(target)) {
        el.textContent = prefix + target.toLocaleString('de-DE', {
          minimumFractionDigits: decimals, maximumFractionDigits: decimals
        }) + suffix;
        return;
      }

      var start = performance.now(), dur = 1500;
      (function step(now) {
        var p = Math.min(1, (now - start) / dur);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = prefix + (target * eased).toLocaleString('de-DE', {
          minimumFractionDigits: decimals, maximumFractionDigits: decimals
        }) + suffix;
        if (p < 1) requestAnimationFrame(step);
      })(start);
    }

    if (!('IntersectionObserver' in window)) {
      counters.forEach(run);
    } else {
      var cio = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (!e.isIntersecting) return;
          run(e.target);
          cio.unobserve(e.target);
        });
      }, { threshold: 0.4 });
      counters.forEach(function (el) { cio.observe(el); });
    }
  }

  /* --- Karten kippen leicht in Richtung Zeiger --------------------------- */
  // Nur auf Geräten mit echtem Zeiger; auf Touch wäre das nur störend.

  if (finePointer && !reduced) {
    document.querySelectorAll('.tilt').forEach(function (card) {
      var raf = 0;

      card.addEventListener('pointermove', function (e) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
          var r = card.getBoundingClientRect();
          var px = (e.clientX - r.left) / r.width  - 0.5;
          var py = (e.clientY - r.top)  / r.height - 0.5;
          card.style.transform =
            'perspective(900px) rotateX(' + (-py * 7).toFixed(2) + 'deg) ' +
            'rotateY(' + (px * 9).toFixed(2) + 'deg) translateY(-4px)';
          raf = 0;
        });
      });

      card.addEventListener('pointerleave', function () {
        cancelAnimationFrame(raf); raf = 0;
        card.style.transform = '';
      });
    });
  }

  /* --- Hauptschaltflächen folgen dem Zeiger leicht ----------------------- */
  if (finePointer && !reduced) {
    document.querySelectorAll('[data-magnetic]').forEach(function (btn) {
      var raf = 0;
      btn.addEventListener('pointermove', function (e) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
          var r = btn.getBoundingClientRect();
          var dx = (e.clientX - (r.left + r.width  / 2)) * 0.16;
          var dy = (e.clientY - (r.top  + r.height / 2)) * 0.22;
          btn.style.transform = 'translate(' + dx.toFixed(1) + 'px,' + dy.toFixed(1) + 'px)';
          raf = 0;
        });
      });
      btn.addEventListener('pointerleave', function () {
        cancelAnimationFrame(raf); raf = 0;
        btn.style.transform = '';
      });
    });
  }
})();
