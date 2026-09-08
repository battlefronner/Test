/* Läuft synchron im <head>, noch bevor der Inhalt gezeichnet wird.

   Zwei Aufgaben:
   1. Die Markierung setzen, an der das Stylesheet erkennt, dass Animationen
      möglich sind. Ohne JavaScript bleibt jeder Inhalt sofort sichtbar.
   2. Die gespeicherte Darstellungswahl anwenden — vor dem ersten Bildaufbau,
      damit kein Umschlagen sichtbar wird.

   Die Marke ist dunkel: Ohne Auswahl bleibt es dabei. Wer einmal auf hell
   gestellt hat, bekommt hell — auf diesem Gerät, in diesem Browser. */
(function () {
  'use strict';

  var wurzel = document.documentElement;
  wurzel.classList.add('js');

  var wahl = null;
  try { wahl = window.localStorage.getItem('fw-theme'); }
  catch (e) { /* Privater Modus oder gesperrter Speicher — dann eben dunkel */ }

  if (wahl === 'light' || wahl === 'dark') {
    wurzel.setAttribute('data-theme', wahl);
  }
})();
