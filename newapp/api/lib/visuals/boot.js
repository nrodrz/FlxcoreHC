/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* OpsHub Help Center visuals — start-up (BT90): the list of systems comes as data (a JSON block, not a script), and the
   theme follows the Help Center (a message from the page that holds the frame only: "light" or "dark"). */
(function(){
  try { var el = document.getElementById('opsh-systems'); window.OPSH_SYSTEMS = el ? JSON.parse(el.textContent || '[]') : []; } catch (e) { window.OPSH_SYSTEMS = []; }
  window.addEventListener('message', function (e) {
    if (e.source !== window.parent || !e.data || e.data.type !== 'opsh-theme') return;
    var t = e.data.theme; if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
  });
})();
