/* Runs in <head> before the page paints, so the saved theme is applied with no flash. */
(function () {
  var t = null;
  try { t = localStorage.getItem('lb-theme'); } catch (e) { /* storage blocked: fall back to system setting */ }
  if (t !== 'dark' && t !== 'light') { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
  document.documentElement.setAttribute('data-theme', t);
})();
