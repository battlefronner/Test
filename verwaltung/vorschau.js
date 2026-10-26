/* Vorschau: Formulare senden hier nichts ab. */
document.addEventListener('submit', function (e) {
  e.preventDefault();
  e.stopImmediatePropagation();
  window.alert('Vorschau: Formulare sind hier abgeschaltet.');
}, true);
