/* Läuft synchron im <head>, noch bevor der Inhalt gezeichnet wird.
   Setzt die Markierung, an der das Stylesheet erkennt, dass Animationen
   möglich sind. Ohne JavaScript bleibt jeder Inhalt sofort sichtbar. */
document.documentElement.classList.add('js');
