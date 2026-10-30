OpsHub — the source of the app (BT90)
=====================================
This folder is closed to the web (src/.htaccess). The browser never loads it.

  app.jsx   the app (React with JSX). index.html loads the compiled app.js.
  help.jsx  the public knowledge-base page (help.html loads help.js).
  build.js  compiles both into plain JavaScript and stamps the script links of the pages with a content version.

The app used to be compiled in each browser (with Babel from a CDN). Since 0621-BT90 it is compiled before it is
shipped, so the page policy allows no 'unsafe-eval' and no inline scripts, and no code comes from another site.

To build (only needed after changing app.jsx or help.jsx):
  1. In any folder: npm install @babel/standalone@7.24.7
  2. From the OpsHub folder: NODE_PATH=<that folder>/node_modules node src/build.js
The libraries the app uses are in ../vendor (licenses in vendor/LICENSES.txt).
