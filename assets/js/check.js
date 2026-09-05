/* Bedarfs-Check: drei Fragen, eine Einordnung. Läuft vollständig im Browser —
   es wird nichts gespeichert und nichts übertragen. */
(function () {
  'use strict';

  var form = document.querySelector('[data-check]');
  if (!form) return;

  var steps = Array.prototype.slice.call(form.querySelectorAll('.check__step'));
  var result = form.querySelector('[data-result]');
  var next = form.querySelector('[data-check-next]');
  var back = form.querySelector('[data-check-back]');
  var reset = form.querySelector('[data-check-reset]');
  var bars = form.querySelectorAll('[data-progress] i');
  var nav = form.querySelector('.check__nav');
  var current = 0;

  /* Die vier Säulen mit Anschlusstext. Die Zuordnung ist bewusst einfach und
     transparent — sie sortiert vor, sie entscheidet nichts. */
  var PILLARS = {
    schutz: {
      title: 'Zuerst: Schutz.',
      text: 'Bei Ihnen hängt Einkommen an Ihrer Arbeitskraft — und andere hängen an Ihrem Einkommen. Die erste Frage ist deshalb, was im Ernstfall monatlich fehlen würde. Alles andere baut darauf auf.',
      link: '/kontakt.html#thema-schutz', more: '/leistungen.html#schutz'
    },
    vorsorge: {
      title: 'Zuerst: Vorsorge.',
      text: 'Die Rechnung ist einfach, aber sie muss gemacht werden: Was kommt später rein, was wird gebraucht, was fehlt? Erst danach lohnt der Blick auf einzelne Verträge.',
      link: '/kontakt.html#thema-vorsorge', more: '/leistungen.html#vorsorge'
    },
    vermoegen: {
      title: 'Zuerst: Ihre bestehenden Verträge.',
      text: 'Bevor irgendetwas Neues sinnvoll ist, gehört Bestehendes auf den Tisch: Kosten, Flexibilität, Laufzeit. Alte Verträge sind oft besser als ihr Ruf — manchmal aber auch das Gegenteil.',
      link: '/kontakt.html#thema-vertragscheck', more: '/leistungen.html#vermoegen'
    },
    unternehmen: {
      title: 'Zuerst: Ihr Betrieb.',
      text: 'Haftung, Ausfall, Belegschaft — bei Unternehmern hängt mehr an einer Entscheidung als der eigene Haushalt. Wir gehen Ihre Abläufe durch, bevor wir über Deckungen sprechen.',
      link: '/kontakt.html#thema-unternehmen', more: '/leistungen.html#unternehmen'
    }
  };

  function classify(a) {
    if (a.q1 === 'betrieb' || a.q2 === 'unternehmer' || a.q3 === 'betrieb') return 'unternehmen';
    if (a.q3 === 'vertraege') return 'vermoegen';
    if (a.q2 === 'ruhestand' || a.q3 === 'alter') return 'vorsorge';
    return 'schutz';
  }

  function answers() {
    var out = {};
    ['q1', 'q2', 'q3'].forEach(function (q) {
      var el = form.querySelector('input[name="' + q + '"]:checked');
      out[q] = el ? el.value : null;
    });
    return out;
  }

  function show(i) {
    current = i;
    steps.forEach(function (s, k) { s.hidden = k !== i; });
    result.hidden = true;
    nav.hidden = false;
    back.hidden = i === 0;
    next.textContent = i === steps.length - 1 ? 'Einordnung anzeigen' : 'Weiter';
    bars.forEach(function (b, k) { b.classList.toggle('on', k <= i); });
    var first = steps[i].querySelector('input');
    if (first && document.activeElement !== document.body) first.focus();
  }

  function finish() {
    var key = classify(answers());
    var p = PILLARS[key];
    result.querySelector('[data-result-title]').textContent = p.title;
    result.querySelector('[data-result-text]').textContent = p.text;
    result.querySelector('[data-result-link]').setAttribute('href', p.link);
    result.querySelector('[data-result-more]').setAttribute('href', p.more);
    steps.forEach(function (s) { s.hidden = true; });
    nav.hidden = true;
    result.hidden = false;
    bars.forEach(function (b) { b.classList.add('on'); });
    result.querySelector('h3').focus && result.querySelector('h3').setAttribute('tabindex', '-1');
    result.querySelector('h3').focus();
  }

  next.addEventListener('click', function () {
    var chosen = steps[current].querySelector('input:checked');
    if (!chosen) {
      steps[current].classList.add('check__step--shake');
      setTimeout(function () { steps[current].classList.remove('check__step--shake'); }, 400);
      var f = steps[current].querySelector('input'); if (f) f.focus();
      return;
    }
    if (current < steps.length - 1) show(current + 1); else finish();
  });
  back.addEventListener('click', function () { if (current > 0) show(current - 1); });
  reset.addEventListener('click', function () { form.reset(); show(0); });

  // Auswahl per Tastatur: Enter geht weiter
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); next.click(); }
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); next.click(); });

  show(0);
})();
