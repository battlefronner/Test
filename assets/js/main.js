/* Finanzwächter — UI-Verhalten. Ohne externe Abhängigkeiten. */
(function () {
  'use strict';

  var root = document.documentElement;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Theme-Umschalter ---------------------------------------------- */
  var toggle = document.querySelector('[data-theme-toggle]');
  if (toggle) {
    var systemDark = window.matchMedia('(prefers-color-scheme: dark)');

    function currentTheme() {
      return root.getAttribute('data-theme') || (systemDark.matches ? 'dark' : 'light');
    }
    function syncLabel() {
      var isDark = currentTheme() === 'dark';
      toggle.setAttribute('aria-pressed', String(isDark));
      toggle.setAttribute(
        'aria-label',
        isDark ? 'Zu hellem Design wechseln' : 'Zu dunklem Design wechseln'
      );
    }
    toggle.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('fw-theme', next); } catch (e) { /* ignorieren */ }
      syncLabel();
    });
    // Systemwechsel nur übernehmen, solange nichts explizit gewählt wurde
    systemDark.addEventListener('change', function () {
      if (!root.hasAttribute('data-theme')) syncLabel();
    });
    syncLabel();
  }

  /* ---- Mobile Navigation --------------------------------------------- */
  var burger = document.querySelector('[data-nav-toggle]');
  var menu = document.getElementById('nav-menu');

  if (burger && menu) {
    function setMenu(open) {
      burger.setAttribute('aria-expanded', String(open));
      menu.setAttribute('data-open', String(open));
    }
    burger.addEventListener('click', function () {
      setMenu(burger.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && burger.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        burger.focus();
      }
    });
    // Klick außerhalb schließt das Menü
    document.addEventListener('click', function (e) {
      if (burger.getAttribute('aria-expanded') !== 'true') return;
      if (menu.contains(e.target) || burger.contains(e.target)) return;
      setMenu(false);
    });
    // Beim Wechsel auf Desktop zurücksetzen
    window.matchMedia('(min-width: 60.0625rem)').addEventListener('change', function (e) {
      if (e.matches) setMenu(false);
    });
  }

  /* ---- Header-Zustand beim Scrollen ----------------------------------- */
  var header = document.querySelector('.site-header');
  if (header) {
    var ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        header.setAttribute('data-scrolled', String(window.scrollY > 8));
        ticking = false;
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---- Scroll-Reveal --------------------------------------------------- */
  var revealables = document.querySelectorAll('.reveal');
  if (revealables.length) {
    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealables.forEach(function (el) { el.classList.add('is-visible'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
      revealables.forEach(function (el) { io.observe(el); });

      // Sicherheitsnetz: Inhalt darf niemals dauerhaft unsichtbar bleiben,
      // etwa wenn der Observer in einer Umgebung nicht auslöst.
      window.setTimeout(function () {
        revealables.forEach(function (el) { el.classList.add('is-visible'); });
      }, 2500);
    }
  }

  /* ---- Aktuelles Jahr im Footer --------------------------------------- */
  document.querySelectorAll('[data-current-year]').forEach(function (el) {
    el.textContent = String(new Date().getFullYear());
  });
})();
