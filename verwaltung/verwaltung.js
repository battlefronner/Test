/* Verwaltung: Rückfragen, Texteditor, Foto-Verkleinerung, Warnung bei
   ungespeicherten Änderungen. Ohne Fremdbibliotheken. */
(function () {
  'use strict';

  var ungespeichert = false;

  // Rückfrage vor folgenreichen Knöpfen
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  // Ungespeicherte Änderungen
  document.querySelectorAll('form[data-warnen]').forEach(function (f) {
    f.addEventListener('input', function () { ungespeichert = true; });
    f.addEventListener('change', function () { ungespeichert = true; });
  });
  document.addEventListener('submit', function (e) {
    ungespeichert = false;
    var knopf = e.submitter;
    if (knopf && !knopf.hasAttribute('formnovalidate')) {
      // Doppeltes Absenden verhindern
      setTimeout(function () { knopf.disabled = true; }, 0);
      if (knopf.name) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = knopf.name; h.value = knopf.value;
        e.target.appendChild(h);
      }
    }
  });
  window.addEventListener('beforeunload', function (e) {
    if (ungespeichert) { e.preventDefault(); e.returnValue = ''; }
  });

  // Zeichenzähler für Seitentitel und Beschreibung
  document.querySelectorAll('[data-zaehler]').forEach(function (feld) {
    var ideal = parseInt(feld.getAttribute('data-zaehler'), 10);
    var anzeige = document.createElement('span');
    anzeige.className = 'leise klein';
    feld.insertAdjacentElement('afterend', anzeige);
    function zaehle() {
      var n = feld.value.length;
      anzeige.textContent = n + ' Zeichen' + (n > ideal ? ' — wird bei Google eventuell gekürzt' : '');
    }
    feld.addEventListener('input', zaehle);
    zaehle();
  });

  // ---------------------------------------------------------------------
  // Texteditor
  // ---------------------------------------------------------------------
  var form = document.getElementById('texte-form');
  if (form) {
    var felder = Array.prototype.slice.call(form.querySelectorAll('.te'));
    var start = new Map();
    var hinweis = form.querySelector('[data-geaendert]');
    felder.forEach(function (el) { start.set(el, el.innerHTML); });

    function zaehleAenderungen() {
      var n = 0;
      felder.forEach(function (el) {
        var anders = el.innerHTML !== start.get(el);
        el.classList.toggle('geaendert', anders);
        if (anders) n++;
      });
      if (hinweis) hinweis.textContent = n === 0 ? 'Keine Änderungen' : (n === 1 ? '1 Text geändert' : n + ' Texte geändert');
      return n;
    }
    form.addEventListener('input', function () { ungespeichert = true; zaehleAenderungen(); });

    felder.forEach(function (el) {
      el.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!el.hasAttribute('data-einzeilig')) document.execCommand('insertLineBreak');
      });
      // Nur reinen Text einfügen — keine Formatierung aus Word oder Webseiten
      el.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
        if (el.hasAttribute('data-einzeilig')) text = text.replace(/\s+/g, ' ');
        document.execCommand('insertText', false, text);
      });
      el.addEventListener('drop', function (e) { e.preventDefault(); });
    });

    function aktuellesFeld() {
      var sel = window.getSelection();
      if (!sel || !sel.rangeCount) return null;
      var n = sel.anchorNode;
      while (n && !(n.nodeType === 1 && n.classList.contains('te'))) n = n.parentNode;
      return n;
    }
    function entferneGold(feld) {
      var sel = window.getSelection();
      feld.querySelectorAll('span.gold').forEach(function (s) {
        if (sel.containsNode(s, true)) {
          while (s.firstChild) s.parentNode.insertBefore(s.firstChild, s);
          s.remove();
        }
      });
    }
    form.querySelectorAll('[data-cmd]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var feld = aktuellesFeld();
        if (!feld) return;
        var cmd = b.getAttribute('data-cmd');
        if (cmd === 'bold' || cmd === 'italic') {
          document.execCommand(cmd);
        } else if (cmd === 'gold') {
          var sel = window.getSelection();
          var r = sel.getRangeAt(0);
          if (r.collapsed) return;
          var span = document.createElement('span');
          span.className = 'gold';
          span.appendChild(r.extractContents());
          r.insertNode(span);
          sel.removeAllRanges();
          var neu = document.createRange();
          neu.selectNodeContents(span);
          sel.addRange(neu);
        } else if (cmd === 'plain') {
          document.execCommand('removeFormat');
          entferneGold(feld);
        }
        ungespeichert = true;
        zaehleAenderungen();
      });
    });

    form.addEventListener('submit', function () {
      form.querySelectorAll('input[data-te]').forEach(function (i) { i.remove(); });
      felder.forEach(function (el) {
        if (el.innerHTML === start.get(el)) return;
        [['t', el.innerHTML], ['h', el.getAttribute('data-hash')]].forEach(function (p) {
          var i = document.createElement('input');
          i.type = 'hidden';
          i.name = p[0] + '[' + el.getAttribute('data-i') + ']';
          i.value = p[1];
          i.setAttribute('data-te', '');
          form.appendChild(i);
        });
      });
    });
  }

  // ---------------------------------------------------------------------
  // Fotos: im Browser verkleinern, bevor sie hochgeladen werden. Handyfotos
  // haben oft 5–10 MB; viele Server nehmen nur 2 MB an.
  // ---------------------------------------------------------------------
  document.querySelectorAll('input[data-datei]').forEach(function (input) {
    var box = input.closest('.person-karte__foto, .feld');
    var name = box && box.querySelector('[data-dateiname]');
    var bild = box && box.querySelector('img, .foto-leer');
    input.addEventListener('change', function () {
      var datei = input.files && input.files[0];
      if (!datei) return;
      if (name) name.textContent = datei.name;
      if (!window.createImageBitmap || !window.DataTransfer) return;
      createImageBitmap(datei).then(function (bmp) {
        var max = 1600;
        var f = Math.min(1, max / Math.max(bmp.width, bmp.height));
        var c = document.createElement('canvas');
        c.width = Math.round(bmp.width * f);
        c.height = Math.round(bmp.height * f);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        if (bild) {
          var vorschau = new Image();
          vorschau.alt = '';
          vorschau.width = 150; vorschau.height = 188;
          vorschau.src = c.toDataURL('image/jpeg', 0.7);
          bild.replaceWith(vorschau);
          bild = vorschau;
        }
        c.toBlob(function (blob) {
          if (!blob) return;
          var dt = new DataTransfer();
          dt.items.add(new File([blob], 'foto.jpg', { type: 'image/jpeg' }));
          input.files = dt.files;
          if (name) name.textContent = datei.name + ' — bereit, mit „Speichern“ übernehmen';
        }, 'image/jpeg', 0.9);
      }).catch(function () { /* Original hochladen */ });
    });
  });
})();
