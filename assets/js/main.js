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
})();
