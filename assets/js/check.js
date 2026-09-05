/* Einstieg in den Erstgespräch-Assistenten.
   Die erste Frage steht auf der Startseite. Wer sie beantwortet, hat den
   Assistenten bereits begonnen — die Antwort wandert über den Sitzungsspeicher
   mit, damit niemand dieselbe Frage zweimal beantwortet. */
(function () {
  'use strict';

  var entry = document.querySelector('[data-entry]');
  if (!entry) return;

  var KEY = 'fw-funnel';
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var target = entry.getAttribute('data-entry-target') || '/erstgespraech.html';

  entry.querySelectorAll('.fopt').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var value = btn.getAttribute('data-value');

      entry.querySelectorAll('.fopt').forEach(function (b) { b.classList.remove('is-on'); });
      btn.classList.add('is-on');

      try {
        var state = JSON.parse(sessionStorage.getItem(KEY) || '{}');
        state.fuer = value;
        sessionStorage.setItem(KEY, JSON.stringify(state));
      } catch (e) {
        /* Ohne Sitzungsspeicher beginnt der Assistent eben bei Frage 1 */
      }

      var note = entry.querySelector('[data-entry-note]');
      if (note) {
        note.textContent = btn.getAttribute('data-feedback') || '';
        note.classList.add('is-on');
      }

      window.setTimeout(function () { window.location.href = target; }, reduced ? 120 : 850);
    });
  });
})();
