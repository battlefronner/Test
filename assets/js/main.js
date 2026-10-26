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

  /* --- Schalter für Bewegung ---------------------------------------------
     Ohne Wahl gilt die Geräteeinstellung „Bewegung reduzieren". Der Schalter
     übersteuert sie in beide Richtungen und ist zugleich die Möglichkeit,
     laufende Animationen anzuhalten (WCAG 2.2.2). */
  var bewegung = document.querySelector('[data-motion-toggle]');
  if (bewegung) {
    var systemRuhig = window.matchMedia('(prefers-reduced-motion: reduce)');
    var wurzel2 = document.documentElement;
    function bewegungAus() {
      var w = wurzel2.getAttribute('data-motion');
      if (w === 'off') return true;
      if (w === 'on') return false;
      return systemRuhig.matches;
    }
    function anzeigen() {
      var angehalten = bewegungAus();
      bewegung.setAttribute('aria-pressed', angehalten ? 'true' : 'false');
      bewegung.setAttribute('aria-label', angehalten ? 'Animationen abspielen' : 'Animationen anhalten');
      bewegung.setAttribute('data-paused', angehalten ? 'true' : 'false');
    }
    anzeigen();
    bewegung.addEventListener('click', function () {
      var neu = bewegungAus() ? 'on' : 'off';
      wurzel2.setAttribute('data-motion', neu);
      try { window.localStorage.setItem('fw-motion', neu); } catch (e) {}
      anzeigen();
      window.dispatchEvent(new CustomEvent('fw:motionchange'));
    });
    systemRuhig.addEventListener('change', function () {
      if (!wurzel2.getAttribute('data-motion')) { anzeigen(); window.dispatchEvent(new CustomEvent('fw:motionchange')); }
    });
  }
})();
