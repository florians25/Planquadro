/* Planquadro – obere Navigation: passt die Leiste nicht in die Breite, werden die
   Menüpunkte zu Symbolen (Text als Tooltip und für Screenreader); der aktive
   Punkt behält seinen Text. Keine Inline-Handler (CSP). */
(function () {
  var nav = document.getElementById('navLinks');
  if (!nav) return;
  nav.querySelectorAll('.nav-link').forEach(function (a) {
    var s = a.querySelector('span:not(.nav-beta)');
    var t = s ? s.textContent.trim() : '';
    if (t && !a.getAttribute('title')) a.setAttribute('title', t);
    if (t && !a.getAttribute('aria-label')) a.setAttribute('aria-label', t);
  });
  function fit() {
    nav.classList.remove('nav-compact');
    if (nav.scrollWidth > nav.clientWidth + 1) nav.classList.add('nav-compact');
  }
  var raf = 0;
  window.addEventListener('resize', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(fit); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  fit();
})();
