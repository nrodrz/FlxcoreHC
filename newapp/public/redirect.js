// Sends /public/ to the app, keeping the #page part. A file of its own: the site's Content-Security-Policy allows no inline script.
location.replace('../' + location.hash);
