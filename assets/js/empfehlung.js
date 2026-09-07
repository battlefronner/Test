/* Empfehlungsseite: Vorbelegung, Terminfenster, Pflicht-Telefon.
   Das Absenden übernimmt form.js — diese Datei ergänzt nur, was allein
   auf dieser Seite gebraucht wird. Ohne JavaScript bleibt das Formular
   vollständig nutzbar; der Server prüft dieselben Regeln noch einmal. */
(function () {
  'use strict';

  var form = document.getElementById('empfehlungsformular');
  if (!form) return;

  /* ---- 1. Empfehlungsgeber aus dem Link übernehmen -------------------------
     Persönliche Links der Form /empfehlung.html?von=Vorname+Nachname sparen
     dem Besucher einen Schritt. Der Wert wird hart gefiltert und
     ausschließlich über textContent bzw. value gesetzt, niemals als HTML. */
  function nameFromQuery() {
    var m = /[?&]von=([^&#]*)/.exec(window.location.search);
    if (!m) return '';
    var raw;
    try { raw = decodeURIComponent(m[1].replace(/\+/g, ' ')); }
    catch (e) { return ''; }
    // Nur Buchstaben, Leerzeichen und die in Namen üblichen Trennzeichen.
    // Bewusst ohne Unicode-Eigenschaften, damit die Datei auch in älteren
    // Browsern noch geparst wird und nicht komplett ausfällt.
    raw = raw.replace(/[^A-Za-zÀ-ÖØ-öø-ÿĀ-ſ\s.'’-]/g, ' ').replace(/\s+/g, ' ').trim();
    return raw.length >= 2 ? raw.slice(0, 80) : '';
  }

  var von = nameFromQuery();
  if (von) {
    var feld = form.elements['empfehler_name'];
    if (feld && !feld.value) feld.value = von;

    var box = document.querySelector('[data-von-box]');
    var slot = document.querySelector('[data-von-name]');
    if (box && slot) { slot.textContent = von; box.hidden = false; }

    var lead = document.querySelector('[data-von-lead]');
    if (lead) {
      lead.textContent = von + ' hat Ihnen unseren Namen genannt. Das ist der '
        + 'ehrlichste Weg, wie wir zu neuen Mandaten kommen — und der Grund, '
        + 'warum wir bei Empfehlungen besonders genau hinsehen.';
    }
  }

  /* ---- 2. Wunschtag begrenzen ---------------------------------------------
     Frühestens der nächste Tag, spätestens in vier Monaten. Dieselben Grenzen
     prüft der Server noch einmal, damit sie auch ohne JavaScript gelten. */
  var tag = form.elements['wunschtag'];
  if (tag) {
    var iso = function (d) { return d.toISOString().slice(0, 10); };
    var morgen = new Date();
    morgen.setDate(morgen.getDate() + 1);
    tag.min = iso(morgen);
    var spaetestens = new Date();
    spaetestens.setDate(spaetestens.getDate() + 120);
    tag.max = iso(spaetestens);
  }

  /* ---- 3. Telefon wird zur Pflicht, wenn telefonisch gesprochen werden soll */
  var telefon = form.elements['telefon'];
  var marker = document.querySelector('[data-tel-req]');
  var hinweis = document.querySelector('[data-tel-hint]');

  function telefonPruefen() {
    var weg = form.elements['kontaktweg'];
    var wert = weg ? weg.value : '';
    var noetig = wert === 'telefon';
    if (telefon) telefon.required = noetig;
    if (marker) marker.hidden = !noetig;
    if (hinweis) {
      hinweis.textContent = noetig
        ? 'Unter dieser Nummer rufen wir Sie an.'
        : 'Für Rückruf oder Telefontermin.';
    }
  }

  Array.prototype.forEach.call(
    form.querySelectorAll('input[name="kontaktweg"]'),
    function (radio) { radio.addEventListener('change', telefonPruefen); }
  );
  telefonPruefen();
})();
