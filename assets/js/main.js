/* Grundverhalten: Navigation und Kopfbereich. */
(function () {
  'use strict';

  /* --- Mobile Navigation ------------------------------------------------ */
  var burger = document.querySelector('[data-nav-toggle]');
  var menu = document.getElementById('nav-menu');

  if (burger && menu) {
    function setMenu(open) {
      burger.setAttribute('aria-expanded', String(open));
      menu.setAttribute('data-open', String(open));
      burger.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
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

    document.addEventListener('click', function (e) {
      if (burger.getAttribute('aria-expanded') !== 'true') return;
      if (menu.contains(e.target) || burger.contains(e.target)) return;
      setMenu(false);
    });

    window.matchMedia('(min-width: 62.0625rem)').addEventListener('change', function (e) {
      if (e.matches) setMenu(false);
    });
  }

  /* --- Kopfbereich beim Scrollen ---------------------------------------- */
  var header = document.querySelector('.site-header');
  if (header) {
    var ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        header.setAttribute('data-scrolled', String(window.scrollY > 10));
        ticking = false;
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* --- Jahreszahl im Fußbereich ----------------------------------------- */
  document.querySelectorAll('[data-current-year]').forEach(function (el) {
    el.textContent = String(new Date().getFullYear());
  });

  /* --- Umschalter hell/dunkel -------------------------------------------
     Angewendet wird die Wahl bereits in boot.js, noch vor dem ersten
     Bildaufbau. Hier geht es nur um das Umschalten und das Merken. */
  var schalter = document.querySelector('[data-theme-toggle]');
  if (schalter) {
    var wurzel = document.documentElement;
    var farbmarke = document.querySelector('meta[name="theme-color"]');

    function anwenden(modus, merken) {
      var hell = modus === 'light';
      if (hell) { wurzel.setAttribute('data-theme', 'light'); }
      else { wurzel.setAttribute('data-theme', 'dark'); }

      schalter.setAttribute('aria-pressed', hell ? 'true' : 'false');
      schalter.setAttribute('aria-label', hell
        ? 'Dunkle Darstellung einschalten'
        : 'Helle Darstellung einschalten');

      // Die Adressleiste mobiler Browser faerbt sich nach diesem Wert
      if (farbmarke) farbmarke.setAttribute('content', hell ? '#FAF8F4' : '#0B0B0F');

      if (merken) {
        try { window.localStorage.setItem('fw-theme', hell ? 'light' : 'dark'); }
        catch (e) { /* Ohne Speicher gilt die Wahl nur fuer diesen Seitenaufruf */ }
      }
    }

    // Ausgangszustand aus dem, was boot.js gesetzt hat
    anwenden(wurzel.getAttribute('data-theme') === 'light' ? 'light' : 'dark', false);

    schalter.addEventListener('click', function () {
      anwenden(wurzel.getAttribute('data-theme') === 'light' ? 'dark' : 'light', true);
      // Die Bühne zeichnet mit eigenen Farben und muss sie neu einlesen
      window.dispatchEvent(new CustomEvent('fw:themechange'));
    });
  }
})();
