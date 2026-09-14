/* Strategie-Einordnung: fuenf Fragen, sechs moegliche Ergebnisse.

   Bewusste Entscheidungen:
   - Alle sechs Ergebnisbeschreibungen stehen fertig in der Seite. Dieses Skript
     blendet eine davon ein und setzt keinen Text zusammen. Damit kann hier kein
     Markup entstehen und die Beschreibungen sind auch ohne Skript im Quelltext
     nachlesbar.
   - Die Antworten bleiben im Browser (sessionStorage) und werden nirgends
     abgeschickt.
   - Zwei Schutzregeln gehen jeder Punktrechnung vor. Wer bei 30 Prozent Minus
     aussteigen wuerde oder das Geld in unter zehn Jahren braucht, bekommt keine
     Strategie mit hohem Aktienanteil vorgeschlagen — unabhaengig davon, was die
     uebrigen Antworten ergeben wuerden. */
(function () {
  'use strict';

  var wurzel = document.querySelector('[data-strategie]');
  if (!wurzel) return;
  wurzel.hidden = false;

  var SCHRITTE = ['horizont', 'minus30', 'anspruch', 'esg', 'aerger', 'ergebnis'];
  var FRAGEN = SCHRITTE.length - 1;
  var SPEICHER = 'fw-strategie';

  /* ---- Punkte je Antwort auf die sechs Strategien ------------------------ */
  var PUNKTE = {
    horizont: {
      kurz:       { 4: 6, 3: 1 },
      mittel:     { 3: 2, 4: 2, 2: 1, 5: 1, 6: 1 },
      lang:       { 1: 2, 2: 2, 5: 2, 6: 2, 3: 1 }
    },
    minus30: {
      raus:       { 4: 6, 3: 1 },
      halten:     { 2: 2, 3: 2, 6: 2, 5: 1, 4: 1 },
      nachkaufen: { 1: 3, 5: 3, 6: 2, 2: 2 }
    },
    anspruch: {
      einfach:    { 2: 5, 4: 1 },
      regeln:     { 5: 5, 2: 1 },
      bewertung:  { 6: 5 },
      themen:     { 1: 5 }
    },
    esg: {
      wichtig:    { 3: 6 },
      egal:       { 3: 1 },
      nein:       { 3: -2 }
    },
    aerger: {
      inflation:  { 1: 1, 2: 2, 5: 2, 6: 2 },
      schwanken:  { 4: 4, 3: 1 },
      verpasst:   { 1: 4 }
    }
  };

  /* Bei Gleichstand: breiter gestreut und ruhiger zuerst. */
  var GLEICHSTAND = [2, 6, 5, 3, 4, 1];

  var TITEL = {
    1: 'Zukunftswachstum passt zu Ihnen.',
    2: 'ETF Weltportfolio+ passt zu Ihnen.',
    3: 'Nachhaltig & Wirkung passt zu Ihnen.',
    4: 'Stabilität & Balance passt zu Ihnen.',
    5: 'Faktor & Evidenz passt zu Ihnen.',
    6: 'Substanz & Qualität passt zu Ihnen.'
  };

  var GRUENDE = {
    'schutz-verkauf': 'Ausschlaggebend ist Ihre Antwort zu einem Rückgang von 30 Prozent. '
      + 'Wer in so einer Phase aussteigen würde, verliert genau dort Geld, wo andere warten. '
      + 'Deshalb steht bei Ihnen ein ruhigerer Verlauf vor der höchsten Chance.',
    'schutz-horizont': 'Ausschlaggebend ist Ihr Zeitrahmen. Unter zehn Jahren bleibt zu wenig '
      + 'Zeit, um einen schlechten Börsenabschnitt auszusitzen — deshalb zählt hier '
      + 'Planbarkeit mehr als die höchste Chance.',
    'punkte': 'Diese Einordnung ergibt sich aus dem Zusammenspiel Ihrer fünf Antworten — '
      + 'vor allem aus Zeitrahmen, Schwankungstoleranz und Ihrem Anspruch an die Bauweise.'
  };

  /* ---- Zustand ----------------------------------------------------------- */
  var antworten = {};
  try {
    var alt = window.sessionStorage.getItem(SPEICHER);
    if (alt) antworten = JSON.parse(alt) || {};
  } catch (e) { antworten = {}; }

  function sichern() {
    try { window.sessionStorage.setItem(SPEICHER, JSON.stringify(antworten)); }
    catch (e) { /* ohne Speicher gilt die Antwort nur fuer diesen Durchgang */ }
  }

  /* ---- Auswertung -------------------------------------------------------- */
  function auswerten() {
    if (antworten.minus30 === 'raus')  return { nr: 4, grund: 'schutz-verkauf' };
    if (antworten.horizont === 'kurz') return { nr: 4, grund: 'schutz-horizont' };

    var s = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0 };
    Object.keys(PUNKTE).forEach(function (frage) {
      var wert = antworten[frage];
      var tafel = wert && PUNKTE[frage][wert];
      if (!tafel) return;
      Object.keys(tafel).forEach(function (nr) { s[nr] += tafel[nr]; });
    });

    var best = -Infinity;
    Object.keys(s).forEach(function (nr) { if (s[nr] > best) best = s[nr]; });
    for (var i = 0; i < GLEICHSTAND.length; i++) {
      if (s[GLEICHSTAND[i]] === best) return { nr: GLEICHSTAND[i], grund: 'punkte' };
    }
    return { nr: 2, grund: 'punkte' };
  }

  /* ---- Anzeige ----------------------------------------------------------- */
  var stellen = {};
  SCHRITTE.forEach(function (name) {
    stellen[name] = wurzel.querySelector('[data-fstep="' + name + '"]');
  });
  var bar = wurzel.querySelector('[data-bar]');
  var stepline = wurzel.querySelector('[data-stepline]');
  var eta = wurzel.querySelector('[data-eta]');
  var rueckmeldung = wurzel.querySelector('[data-feedback]');
  var nav = wurzel.querySelector('[data-nav]');
  var aktuell = 0;

  function zeigen(i, fokus) {
    aktuell = i;
    SCHRITTE.forEach(function (name, k) {
      if (stellen[name]) stellen[name].hidden = k !== i;
    });

    var fortschritt = Math.min(i, FRAGEN) / FRAGEN;
    if (bar) bar.style.transform = 'scaleX(' + fortschritt.toFixed(3) + ')';

    if (SCHRITTE[i] === 'ergebnis') {
      if (stepline) stepline.textContent = 'Ihre Einordnung';
      if (eta) eta.textContent = 'Sie können jederzeit neu anfangen.';
      if (nav) nav.hidden = true;
    } else {
      if (stepline) stepline.textContent = 'Frage ' + (i + 1) + ' von ' + FRAGEN;
      if (eta) eta.textContent = 'etwa 60 Sekunden';
      if (nav) nav.hidden = i === 0;
    }

    if (fokus !== false) {
      var h = stellen[SCHRITTE[i]] && stellen[SCHRITTE[i]].querySelector('h2');
      if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
    }
  }

  function ergebnisZeigen() {
    var e = auswerten();
    var titel = wurzel.querySelector('[data-strat-titel]');
    var warum = wurzel.querySelector('[data-warum]');
    if (titel) titel.textContent = TITEL[e.nr];
    if (warum) warum.textContent = GRUENDE[e.grund];

    Array.prototype.forEach.call(wurzel.querySelectorAll('[data-strat]'), function (el) {
      el.hidden = el.getAttribute('data-strat') !== String(e.nr);
    });
    zeigen(SCHRITTE.indexOf('ergebnis'));
  }

  /* ---- Bedienung --------------------------------------------------------- */
  Array.prototype.forEach.call(wurzel.querySelectorAll('.fopt'), function (knopf) {
    knopf.addEventListener('click', function () {
      var schritt = knopf.closest('[data-fstep]');
      var name = schritt && schritt.getAttribute('data-fstep');
      if (!name) return;

      antworten[name] = knopf.getAttribute('data-value');
      sichern();

      Array.prototype.forEach.call(schritt.querySelectorAll('.fopt'), function (b) {
        b.classList.toggle('is-on', b === knopf);
      });
      if (rueckmeldung) rueckmeldung.textContent = knopf.getAttribute('data-feedback') || '';

      var i = SCHRITTE.indexOf(name);
      if (i === FRAGEN - 1) ergebnisZeigen();
      else zeigen(i + 1);
    });
  });

  var zurueck = wurzel.querySelector('[data-back]');
  if (zurueck) {
    zurueck.addEventListener('click', function () {
      if (aktuell > 0) { if (rueckmeldung) rueckmeldung.textContent = ''; zeigen(aktuell - 1); }
    });
  }

  var neustart = wurzel.querySelector('[data-reset]');
  if (neustart) {
    neustart.addEventListener('click', function () {
      antworten = {};
      try { window.sessionStorage.removeItem(SPEICHER); } catch (e) {}
      Array.prototype.forEach.call(wurzel.querySelectorAll('.fopt'), function (b) {
        b.classList.remove('is-on');
      });
      if (rueckmeldung) rueckmeldung.textContent = '';
      zeigen(0);
    });
  }

  /* ---- Einstieg: bereits gegebene Antworten wieder markieren ------------- */
  Object.keys(antworten).forEach(function (name) {
    var schritt = stellen[name];
    if (!schritt) return;
    var b = schritt.querySelector('.fopt[data-value="' + antworten[name] + '"]');
    if (b) b.classList.add('is-on');
  });

  var ersteOffene = 0;
  for (var i = 0; i < FRAGEN; i++) {
    if (!antworten[SCHRITTE[i]]) { ersteOffene = i; break; }
    ersteOffene = i + 1;
  }
  if (ersteOffene >= FRAGEN) ergebnisZeigen();
  else zeigen(ersteOffene, false);
})();
