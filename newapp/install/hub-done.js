// BT133: the finish screen of the main installation. The "Open" button unlocks once both key backups were downloaded.
// A file of its own because the site's Content-Security-Policy allows no inline script.
(function () {
  var got = {};
  function unlock() { var b = document.getElementById('hubOpen'); if (!b) return; b.style.pointerEvents = ''; b.style.opacity = '1'; b.textContent = 'Open ITOps →'; }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('[data-hub-dl]') : null; if (!a) return;
    got[a.getAttribute('data-hub-dl')] = 1; a.textContent = '✓ ' + a.textContent.replace(/^✓ /, '');
    if (got.license && got.release) unlock();
  });
})();
