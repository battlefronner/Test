/* ==========================================================================
   Erstgespräch-Assistent (Funnel)
   Vier Fragen → Prioritätenliste → Terminwunsch → Versand.

   Rückkopplung ist das Prinzip: Jede Antwort bekommt sofort eine Reaktion,
   das Ergebnis spiegelt die Antworten wider, und der Weg zurück ist immer offen.
   Antworten bleiben bis zum Absenden ausschließlich im Browser (sessionStorage).
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-funnel]');
  if (!root) return;
  root.hidden = false;

  var STEPS = ['fuer', 'lage', 'sorgen', 'vertraege', 'ergebnis', 'termin', 'fertig'];
  var QUESTIONS = 4;
  var steps = {};
  root.querySelectorAll('[data-fstep]').forEach(function (s) { steps[s.getAttribute('data-fstep')] = s; });

  var bar = root.querySelector('[data-bar]');
  var stepline = root.querySelector('[data-stepline]');
  var eta = root.querySelector('[data-eta]');
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var KEY = 'fw-funnel';
  var state = load() || { fuer: null, lage: null, sorgen: [], vertraege: null };
  var current = 0;

  function load() {
    try { var s = sessionStorage.getItem(KEY); return s ? JSON.parse(s) : null; } catch (e) { return null; }
  }
  function save() {
    try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* ohne Speicher weiter */ }
  }
  function clear() { try { sessionStorage.removeItem(KEY); } catch (e) {} }

  /* ---- Bewertung: einfach, nachvollziehbar, liegt offen ------------------ */
  var PILLAR = {
    schutz:      { label: 'Schutz',      thema: 'Schutz' },
    vorsorge:    { label: 'Vorsorge',    thema: 'Vorsorge' },
    vermoegen:   { label: 'Vermögen',    thema: 'Vermoegen' },
    unternehmen: { label: 'Unternehmen', thema: 'Unternehmen' }
  };

  function score(a) {
    var s = { schutz: 40, vorsorge: 30, vermoegen: 20, unternehmen: 0 };
    var has = function (k) { return a.sorgen.indexOf(k) !== -1; };

    if (a.fuer === 'partner' || a.fuer === 'familie') s.schutz += 25;
    if (a.lage === 'selbst') { s.schutz += 15; s.vorsorge += 20; s.unternehmen += 10; }
    if (has('ausfall')) s.schutz += 25;
    if (a.lage === 'ruhestand') { s.schutz -= 25; s.vorsorge += 25; }
    if (a.lage === 'angestellt') s.vorsorge += 10;
    if (has('alter')) s.vorsorge += 30;
    if (has('vertraege')) s.vermoegen += 35;
    if (a.vertraege === 'viele' || a.vertraege === 'unklar') s.vermoegen += 20;
    if (has('vermoegen')) s.vermoegen += 15;
    if (a.fuer === 'betrieb' || a.lage === 'unternehmer') s.unternehmen += 60;
    if (has('betrieb')) s.unternehmen += 30;

    var max = 0;
    Object.keys(s).forEach(function (k) { s[k] = Math.max(8, Math.min(100, s[k])); max = Math.max(max, s[k]); });
    Object.keys(s).forEach(function (k) { s[k] = Math.round(s[k] / max * 100); });
    return s;
  }

  function reasons(a, top) {
    var r = [];
    if (top === 'schutz') {
      if (a.fuer === 'familie') r.push('Bei einer Familie hängt am meisten an Ihrem Einkommen.');
      else if (a.fuer === 'partner') r.push('Zu zweit trifft der Wegfall eines Einkommens beide.');
      if (a.lage === 'selbst') r.push('Als Selbstständiger gibt es keine automatische Absicherung der Arbeitskraft.');
      if (a.sorgen.indexOf('ausfall') !== -1) r.push('Die Frage „Was, wenn ich ausfalle?“ steht bei Ihnen ohnehin im Raum.');
      if (!r.length) r.push('Die Absicherung der Arbeitskraft ist die Grundlage für alles Weitere.');
    } else if (top === 'vorsorge') {
      if (a.lage === 'ruhestand') r.push('Kurz vor oder im Ruhestand geht es um Versorgung statt um Absicherung der Arbeitskraft.');
      if (a.sorgen.indexOf('alter') !== -1) r.push('Ob das Geld im Alter reicht, ist eine Rechnung — die machen wir zuerst.');
      if (a.lage === 'selbst') r.push('Ohne Arbeitgeber gibt es keine betriebliche Vorsorge, die nebenher läuft.');
      if (!r.length) r.push('Die Lücke zwischen dem, was später kommt, und dem, was gebraucht wird, sollte bekannt sein.');
    } else if (top === 'vermoegen') {
      if (a.sorgen.indexOf('vertraege') !== -1) r.push('Sie fragen sich selbst, ob Ihre Verträge taugen — genau da fangen wir an.');
      if (a.vertraege === 'viele') r.push('Über Jahre gesammelte Verträge enthalten fast immer Doppelungen oder veraltete Bedingungen.');
      if (a.vertraege === 'unklar') r.push('Wenn der Überblick fehlt, ist die Bestandsaufnahme der erste Wert, den Sie bekommen.');
      if (!r.length) r.push('Bevor etwas Neues sinnvoll ist, gehört Bestehendes auf den Tisch.');
    } else {
      if (a.fuer === 'betrieb' || a.lage === 'unternehmer') r.push('Mit Betrieb und Mitarbeitenden hängt mehr an einer Entscheidung als der eigene Haushalt.');
      if (a.sorgen.indexOf('betrieb') !== -1) r.push('Haftung und Betriebsrisiken beschäftigen Sie bereits.');
      if (!r.length) r.push('Betriebliche Risiken haben Vorrang, weil sie den privaten Schutz mit tragen.');
    }
    return r.slice(0, 2).join(' ');
  }

  var TITLES = {
    schutz: 'Zuerst: Schutz.',
    vorsorge: 'Zuerst: Vorsorge.',
    vermoegen: 'Zuerst: Ihre bestehenden Verträge.',
    unternehmen: 'Zuerst: Ihr Betrieb.'
  };

  /* ---- Ergebnisgrafik: vier waagerechte Balken, eine Messgröße ------------ */
  function drawPrio(scores, order) {
    var svg = root.querySelector('[data-prio-svg]');
    var W = 400, rowH = 40, labelW = 118, barX = labelW + 8, barMax = W - barX - 44;
    var ns = 'http://www.w3.org/2000/svg';
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + (rowH * order.length + 16));

    order.forEach(function (k, i) {
      var y = 8 + i * rowH;
      var v = scores[k];
      var w = Math.max(6, barMax * v / 100);

      var rank = document.createElementNS(ns, 'text');
      rank.setAttribute('x', 0); rank.setAttribute('y', y + 25);
      rank.setAttribute('class', 'prio__rank');
      rank.textContent = String(i + 1);
      svg.appendChild(rank);

      var label = document.createElementNS(ns, 'text');
      label.setAttribute('x', 22); label.setAttribute('y', y + 25);
      label.setAttribute('class', 'prio__label' + (i === 0 ? ' is-top' : ''));
      label.textContent = PILLAR[k].label;
      svg.appendChild(label);

      var track = document.createElementNS(ns, 'rect');
      track.setAttribute('x', barX); track.setAttribute('y', y + 14);
      track.setAttribute('width', barMax); track.setAttribute('height', 8);
      track.setAttribute('rx', 4); track.setAttribute('class', 'prio__track');
      svg.appendChild(track);

      var barEl = document.createElementNS(ns, 'rect');
      barEl.setAttribute('x', barX); barEl.setAttribute('y', y + 14);
      barEl.setAttribute('width', reduced ? w : 6); barEl.setAttribute('height', 8);
      barEl.setAttribute('rx', 4); barEl.setAttribute('class', 'prio__bar');
      svg.appendChild(barEl);

      var val = document.createElementNS(ns, 'text');
      val.setAttribute('x', W); val.setAttribute('y', y + 25);
      val.setAttribute('text-anchor', 'end'); val.setAttribute('class', 'prio__value');
      val.textContent = v + '\u202F%';   // schmales geschütztes Leerzeichen
      svg.appendChild(val);

      if (!reduced) {
        setTimeout(function () {
          barEl.style.transition = 'width 900ms cubic-bezier(.16,1,.3,1)';
          barEl.setAttribute('width', w);
        }, 120 + i * 110);
      }
    });

    var table = root.querySelector('[data-prio-table]');
    table.textContent = 'Gewichtung: ' + order.map(function (k) { return PILLAR[k].label + ' ' + scores[k] + ' Prozent'; }).join(', ') + '.';
  }

  /* ---- Navigation --------------------------------------------------------- */
  function progress(i) {
    var q = Math.min(i, QUESTIONS);
    var pct = i >= QUESTIONS ? 100 : Math.round((q / QUESTIONS) * 100);
    bar.style.transform = 'scaleX(' + (pct / 100) + ')';
    if (i < QUESTIONS) {
      stepline.textContent = 'Frage ' + (i + 1) + ' von ' + QUESTIONS;
      eta.textContent = i === 0 ? 'etwa 90 Sekunden' : 'noch etwa ' + Math.max(15, (QUESTIONS - i) * 20) + ' Sekunden';
    } else if (STEPS[i] === 'ergebnis') {
      stepline.textContent = 'Ihr Ergebnis'; eta.textContent = 'Kein Termin nötig, um es zu behalten.';
    } else if (STEPS[i] === 'termin') {
      stepline.textContent = 'Terminwunsch'; eta.textContent = 'Zwei Angaben, dann sind wir dran.';
    } else {
      stepline.textContent = 'Fertig'; eta.textContent = '';
    }
  }

  function show(i, back) {
    current = i;
    var name = STEPS[i];
    Object.keys(steps).forEach(function (k) {
      var el = steps[k];
      if (k === name) {
        el.hidden = false;
        el.classList.remove('is-in', 'is-back');
        void el.offsetWidth;
        el.classList.add(back ? 'is-back' : 'is-in');
      } else {
        el.hidden = true;
      }
    });
    progress(i);
    restoreSelection(name);
    var focusEl = steps[name].querySelector('h2');
    if (focusEl) { focusEl.setAttribute('tabindex', '-1'); focusEl.focus({ preventScroll: true }); }
    root.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });
  }

  function restoreSelection(name) {
    var el = steps[name];
    if (!el) return;
    el.querySelectorAll('.fopt').forEach(function (b) {
      var v = b.getAttribute('data-value');
      var on = Array.isArray(state[name]) ? state[name].indexOf(v) !== -1 : state[name] === v;
      b.classList.toggle('is-on', on);
      if (b.hasAttribute('aria-pressed')) b.setAttribute('aria-pressed', String(on));
    });
    var next = el.querySelector('[data-next]');
    if (next && el.hasAttribute('data-multi')) next.disabled = !(state[name] && state[name].length);
  }

  function next() { if (current < STEPS.length - 1) show(current + 1, false); }
  function back() { if (current > 0) show(current - 1, true); }

  /* ---- Einzelauswahl: sofortige Rückmeldung, dann weiter ------------------ */
  Object.keys(steps).forEach(function (name) {
    var el = steps[name];
    var multi = el.hasAttribute('data-multi');
    var limit = parseInt(el.getAttribute('data-multi') || '0', 10);
    var box = el.querySelector('[data-feedback-box]');

    el.querySelectorAll('.fopt').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var v = btn.getAttribute('data-value');
        if (multi) {
          state[name] = state[name] || [];
          var idx = state[name].indexOf(v);
          if (idx !== -1) state[name].splice(idx, 1);
          else if (state[name].length < limit) state[name].push(v);
          else { state[name].shift(); state[name].push(v); }
          save(); restoreSelection(name);
          if (box) box.textContent = state[name].length === limit ? 'Zwei Schwerpunkte — das reicht für eine klare Einordnung.' : (state[name].length ? 'Sie können noch einen zweiten wählen.' : '');
          return;
        }
        state[name] = v; save(); restoreSelection(name);
        var fb = btn.getAttribute('data-feedback');
        var line = el.querySelector('.fstep__feedback');
        if (!line) { line = document.createElement('p'); line.className = 'fstep__feedback'; line.setAttribute('aria-live', 'polite'); el.appendChild(line); }
        line.textContent = fb || '';
        line.classList.add('is-on');
        setTimeout(next, reduced ? 250 : 900);
      });
    });

    var n = el.querySelector('[data-next]'); if (n) n.addEventListener('click', function () {
      if (name === 'ergebnis') { show(STEPS.indexOf('termin'), false); return; }
      next();
    });
    var b = el.querySelector('[data-back]'); if (b) b.addEventListener('click', function () {
      if (name === 'ergebnis') { show(0, true); return; }
      back();
    });
  });

  /* ---- Ergebnis berechnen, wenn es erreicht wird --------------------------- */
  var resultStep = steps.ergebnis;
  var observer = new MutationObserver(function () {
    if (resultStep.hidden) return;
    var s = score(state);
    var order = Object.keys(s).sort(function (a, b) { return s[b] - s[a]; });
    var top = order[0];
    resultStep.querySelector('[data-result-title]').textContent = TITLES[top];
    resultStep.querySelector('[data-result-why]').textContent = reasons(state, top);
    drawPrio(s, order);
    state.top = top; save();
  });
  observer.observe(resultStep, { attributes: true, attributeFilter: ['hidden'] });

  /* ---- Terminformular ------------------------------------------------------ */
  var form = document.getElementById('funnelform');
  var statusBox = document.getElementById('form-status');
  var telReq = form.querySelector('[data-tel-req]');
  var telHint = form.querySelector('[data-tel-hint]');
  var telInput = form.querySelector('input[name="telefon"]');

  form.querySelectorAll('input[name="kontaktweg"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var needTel = r.value === 'telefon';
      telInput.required = needTel;
      telReq.hidden = !needTel;
      telHint.textContent = needTel ? 'Unter dieser Nummer rufen wir Sie an.' : 'Für Rückruf oder Telefontermin.';
      form.querySelector('input[name="rueckruf"]').value = needTel ? '1' : '0';
    });
  });

  var ts = form.querySelector('input[name="render_ts"]');
  if (ts) ts.value = String(Math.floor(Date.now() / 1000));
  var tokenField = form.querySelector('input[name="csrf_token"]');
  var canFetch = window.fetch && /^https?:$/.test(window.location.protocol);
  if (tokenField && canFetch) {
    fetch('/api/token.php', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.token) tokenField.value = j.token; })
      .catch(function () {});
  }

  function fieldError(name, msg) {
    var holder = document.getElementById(name + '-error');
    if (holder) holder.textContent = msg || '';
    var input = form.querySelector('[name="' + name + '"]');
    if (input && input.type !== 'radio') input.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }
  function setStatus(state, msg) {
    statusBox.setAttribute('data-state', state); statusBox.textContent = msg;
    statusBox.setAttribute('role', state === 'err' ? 'alert' : 'status');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ok = true;
    ['kontaktweg', 'zeitfenster'].forEach(function (n) {
      var chosen = form.querySelector('input[name="' + n + '"]:checked');
      fieldError(n, chosen ? '' : 'Bitte auswählen.'); if (!chosen) ok = false;
    });
    ['vorname', 'nachname', 'email', 'telefon', 'datenschutz'].forEach(function (n) {
      var inp = form.querySelector('[name="' + n + '"]');
      if (!inp.checkValidity()) {
        ok = false;
        fieldError(n, inp.validity.valueMissing ? 'Dieses Feld wird benötigt.' : 'Bitte prüfen Sie diese Eingabe.');
      } else fieldError(n, '');
    });
    if (!ok) { setStatus('err', 'Bitte ergänzen Sie die markierten Angaben.'); return; }

    // Zusammenfassung als Nachricht — lesbar für Menschen, nicht nur für Systeme
    var top = state.top || 'schutz';
    var way = form.querySelector('input[name="kontaktweg"]:checked').value;
    var when = form.querySelector('input[name="zeitfenster"]:checked').value;
    var summary = 'Anfrage über den Erstgespräch-Assistenten.\n' +
      'Schwerpunkt laut Einordnung: ' + PILLAR[top].label + '.\n' +
      'Antworten: für ' + state.fuer + ', Lage ' + state.lage + ', Themen ' + (state.sorgen || []).join('+') + ', Verträge ' + state.vertraege + '.\n' +
      'Kontaktweg: ' + way + ', Zeitfenster: ' + when + '.';
    form.querySelector('input[name="thema"]').value = PILLAR[top].thema;
    form.querySelector('input[name="nachricht"]').value = summary;
    form.querySelector('input[name="antworten"]').value = [state.fuer, state.lage, (state.sorgen || []).join('+'), state.vertraege].join('|');

    var btn = form.querySelector('[type="submit"]');
    btn.setAttribute('aria-busy', 'true'); btn.dataset.label = btn.textContent; btn.textContent = 'Wird gesendet …';
    setStatus('', '');

    var data = new FormData(form); data.set('js', '1');
    fetch(form.action, { method: 'POST', body: data, headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j.status === 'ok') {
          steps.fertig.querySelector('[data-done-name]').textContent = form.querySelector('[name="vorname"]').value.trim();
          steps.fertig.querySelector('[data-done-way]').textContent = way === 'telefon' ? 'Telefon' : 'E-Mail';
          clear();
          show(STEPS.indexOf('fertig'), false);
          return;
        }
        if (res.j.fields) Object.keys(res.j.fields).forEach(function (n) { fieldError(n, res.j.fields[n]); });
        setStatus('err', res.j.message || 'Das Senden hat nicht geklappt. Bitte versuchen Sie es erneut.');
      })
      .catch(function () { setStatus('err', 'Verbindungsfehler. Bitte versuchen Sie es später erneut oder rufen Sie uns an.'); })
      .then(function () { btn.removeAttribute('aria-busy'); if (btn.dataset.label) btn.textContent = btn.dataset.label; });
  });

  /* ---- Einstieg: gespeicherten Stand fortsetzen ---------------------------- */
  // Wer auf der Startseite bereits geantwortet hat, steigt hier bei Frage 2 ein.
  var head = document.querySelector('.funnel-head .lead');
  if (head && state.fuer && !state.lage) {
    head.textContent = 'Frage 1 haben Sie schon beantwortet. Noch drei — dann sehen Sie Ihre Prioritätenliste.';
  }

  var resume = 0;
  if (state.fuer) resume = 1;
  if (state.fuer && state.lage) resume = 2;
  if (state.fuer && state.lage && state.sorgen && state.sorgen.length) resume = 3;
  if (state.fuer && state.lage && state.sorgen && state.sorgen.length && state.vertraege) resume = 4;
  show(resume, false);
})();
