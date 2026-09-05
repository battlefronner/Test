/* Synchron im <head> geladen: verhindert Theme-Flackern (FOUC).
   Bewusst als eigene Datei statt Inline-Script, damit die CSP ohne
   'unsafe-inline' auskommt. */
(function () {
  var root = document.documentElement;
  root.classList.remove('no-js');
  try {
    var saved = localStorage.getItem('fw-theme');
    if (saved === 'light' || saved === 'dark') {
      root.setAttribute('data-theme', saved);
    }
  } catch (e) {
    /* localStorage kann blockiert sein (Private Mode, Site-Data gesperrt) */
  }
})();
