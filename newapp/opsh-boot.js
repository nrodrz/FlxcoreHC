/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* OpsHub — runs first on the app page (BT90). The pop-out editor uses the app's own dropdowns; and what older builds kept in
   this browser is removed: the copy of the compiled app (the app is now compiled before it is shipped) and the sign-in token
   (the session now lives only in an HttpOnly cookie the page cannot read). */
window.OPSH_SELECT_MANUAL = true;
try { localStorage.removeItem('opsh_code'); localStorage.removeItem('opsh_key'); localStorage.removeItem('opsh_token'); } catch (e) {}
/* BT135: a fresh upload (nothing installed yet) goes to the installer for an organization. The main installation
   (License Hub) is installed from install/hub.php, opened by hand. */
try { fetch('api/health', { cache: 'no-store', credentials: 'same-origin' })
  .then(function (r) { return r.status === 503 ? r.json() : null; })
  .then(function (j) { if (j && j.error && j.error.not_installed) location.replace('install/'); })
  .catch(function () {}); } catch (e) {}
