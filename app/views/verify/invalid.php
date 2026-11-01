<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** Standalone — invalid, expired, or revoked share link. */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Link unavailable</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,900&family=Space+Grotesk:wght@400;500&family=IBM+Plex+Mono:wght@400&display=swap');
  *{margin:0;padding:0;box-sizing:border-box}
  body{background:#e9e7e0;color:#141b2e;font-family:'Space Grotesk',system-ui,sans-serif;
       min-height:100vh;display:grid;place-items:center;padding:20px}
  .card{max-width:440px;text-align:center;background:#fff;border-radius:16px;padding:44px 38px;
        box-shadow:0 12px 40px rgba(20,27,46,.14)}
  .icon{width:56px;height:56px;border-radius:13px;background:#f7e9e5;display:grid;place-items:center;margin:0 auto 22px}
  .icon svg{width:30px;height:30px;stroke:#c0492e;stroke-width:2.2;fill:none}
  h1{font-family:'Fraunces',serif;font-weight:900;font-size:24px;letter-spacing:-.02em}
  p{color:#6b7488;font-size:14px;margin-top:12px;line-height:1.55}
  .foot{font-family:'IBM Plex Mono',monospace;font-size:9px;letter-spacing:.1em;text-transform:uppercase;
        color:#a7a49a;margin-top:26px}
</style>
</head>
<body>
<div class="card">
  <div class="icon"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></div>
  <h1>This link is no longer available</h1>
  <p>It may have expired or been revoked by the organization that shared it. Ask them to send a fresh verification link.</p>
  <div class="foot">Powered by FlxCoreHC</div>
</div>
</body>
</html>
