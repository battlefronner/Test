/* Kontaktformular: progressive Verbesserung.
   Ohne JS wird das Formular klassisch an api/kontakt.php gepostet,
   das serverseitig validiert und auf eine Statusseite weiterleitet. */
(function () {
  'use strict';

  var form = document.getElementById('kontaktformular');
  if (!form) return;

  var statusBox = document.getElementById('form-status');
  var submitBtn = form.querySelector('[type="submit"]');
  var startedAt = Date.now();

  // Zeitstempel gegen Bots, die das Formular sofort abschicken
  var tsField = form.querySelector('input[name="render_ts"]');
  if (tsField) tsField.value = String(Math.floor(startedAt / 1000));

  // CSRF-Token der Sitzung nachladen. Schlägt das fehl, bleibt das Feld leer;
  // der Server prüft dann die Herkunft der Anfrage (Origin/Referer).
  var tokenField = form.querySelector('input[name="csrf_token"]');
  if (tokenField && window.fetch) {
    fetch('/api/token.php', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (json) {
        if (json && typeof json.token === 'string') tokenField.value = json.token;
      })
      .catch(function () { /* Formular bleibt ohne Token nutzbar */ });
  }

  function setStatus(state, message) {
    if (!statusBox) return;
    statusBox.setAttribute('data-state', state);
    statusBox.textContent = message;
    statusBox.setAttribute('role', state === 'err' ? 'alert' : 'status');
  }

  function fieldError(input, message) {
    var holder = document.getElementById(input.name + '-error');
    if (holder) holder.textContent = message || '';
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  // Browser-Standardmeldungen auf Deutsch vereinheitlichen
  function validate(input) {
    if (input.validity.valid) { fieldError(input, ''); return true; }
    var msg = 'Bitte prüfen Sie diese Eingabe.';
    if (input.validity.valueMissing) msg = 'Dieses Feld wird benötigt.';
    else if (input.validity.typeMismatch && input.type === 'email') msg = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
    else if (input.validity.tooShort) msg = 'Bitte mindestens ' + input.minLength + ' Zeichen eingeben.';
    else if (input.validity.tooLong) msg = 'Bitte höchstens ' + input.maxLength + ' Zeichen eingeben.';
    else if (input.validity.patternMismatch) msg = 'Das Format stimmt nicht.';
    fieldError(input, msg);
    return false;
  }

  form.querySelectorAll('input, textarea, select').forEach(function (input) {
    if (input.type === 'hidden') return;
    input.addEventListener('blur', function () { validate(input); });
    input.addEventListener('input', function () {
      if (input.getAttribute('aria-invalid') === 'true') validate(input);
    });
  });

  form.addEventListener('submit', function (e) {
    var firstInvalid = null;
    form.querySelectorAll('input, textarea, select').forEach(function (input) {
      if (input.type === 'hidden') return;
      if (!validate(input) && !firstInvalid) firstInvalid = input;
    });

    if (firstInvalid) {
      e.preventDefault();
      setStatus('err', 'Bitte korrigieren Sie die markierten Felder.');
      firstInvalid.focus();
      return;
    }

    // Ohne fetch-Unterstützung: normaler Formular-POST
    if (!window.fetch) return;

    e.preventDefault();
    setStatus('', '');
    if (submitBtn) {
      submitBtn.setAttribute('aria-busy', 'true');
      submitBtn.dataset.label = submitBtn.textContent;
      submitBtn.textContent = 'Wird gesendet …';
    }

    var data = new FormData(form);
    data.set('js', '1');

    fetch(form.action, {
      method: 'POST',
      body: data,
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (json) { return { ok: res.ok, json: json }; });
      })
      .then(function (result) {
        if (result.ok && result.json.status === 'ok') {
          form.hidden = true;
          setStatus('ok', result.json.message || 'Vielen Dank. Ihre Nachricht ist eingegangen.');
          if (statusBox) statusBox.focus();
          return;
        }
        // Feldbezogene Fehler vom Server anzeigen
        if (result.json.fields) {
          Object.keys(result.json.fields).forEach(function (name) {
            var input = form.elements[name];
            if (input) fieldError(input, result.json.fields[name]);
          });
        }
        setStatus('err', result.json.message || 'Das Senden hat nicht geklappt. Bitte versuchen Sie es erneut.');
      })
      .catch(function () {
        setStatus('err', 'Verbindungsfehler. Bitte versuchen Sie es später erneut oder rufen Sie uns an.');
      })
      .then(function () {
        if (submitBtn) {
          submitBtn.removeAttribute('aria-busy');
          if (submitBtn.dataset.label) submitBtn.textContent = submitBtn.dataset.label;
        }
      });
  });
})();
