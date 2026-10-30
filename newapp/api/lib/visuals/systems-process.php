<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/* BT50: a Help Center visual. BT51: the systems come from the shared list (visuals/systems.php, window.OPSH_SYSTEMS),
   the same list as the Integration map; light and dark theme. Served only through api/routes/help.php to the people it is given to; requested
   directly, this file prints nothing. */
return <<<'OPSH_VISUAL'
<title>Company Systems Process</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..125,400..800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Layout: a 3D stage (six stations in a row on the OpsHub platform, each system as a small object of its own around
   it, the HR or IT user in front) with a reading panel beside it; under it, the same process as tables, so nothing
   depends on the 3D view. Light and dark (BT51): the Help Center tells the page which one. */
:root{
  color-scheme:light;
  --bg:#F2F5F9; --surface:#FFFFFF; --ground:#E2E9F1; --ink:#16222E; --ink2:#44566A; --ink3:#6B7C8F; --line:#C9D4DF;
  --accent:#2F6FAE; --accentSoft:#D9E7F5; --hub:#F7FAFC; --hubBase:#1F4E7C; --tile:#C7DBEE; --tileOn:#F3C98B;
  --in:#0F8A7C; --out:#C96A12; --manual:#7E8B99; --live:#1F7A4D; --plan:#8A6BB0; --soon:#A9772A; --stand:#FFFFFF; --mat:#F4E7CC; --warn:#A8323A;
  --f-display:'Archivo','Arial Narrow',Arial,sans-serif; --f-body:'IBM Plex Sans','Segoe UI',system-ui,sans-serif; --f-mono:'IBM Plex Mono',ui-monospace,Menlo,Consolas,monospace;
}
@media (prefers-color-scheme: dark){ :root:not([data-theme="light"]){
  --bg:#0F1720; --surface:#17222E; --ground:#1B2836; --ink:#E8EEF4; --ink2:#B4C2D0; --ink3:#8496A8; --line:#2C3C4D;
  --accent:#6BA6DE; --accentSoft:#1D3550; --hub:#2A3B4D; --hubBase:#3F7DB8; --tile:#35526F; --tileOn:#B8873A;
  --in:#3CC4B4; --out:#F0A050; --manual:#8B98A6; --live:#4CC38A; --plan:#B79BE0; --soon:#E0B060; --stand:#2A3B4D; --mat:#3A3324; --warn:#E06A72; color-scheme:dark } }
:root[data-theme="dark"]{
  --bg:#0F1720; --surface:#17222E; --ground:#1B2836; --ink:#E8EEF4; --ink2:#B4C2D0; --ink3:#8496A8; --line:#2C3C4D;
  --accent:#6BA6DE; --accentSoft:#1D3550; --hub:#2A3B4D; --hubBase:#3F7DB8; --tile:#35526F; --tileOn:#B8873A;
  --in:#3CC4B4; --out:#F0A050; --manual:#8B98A6; --live:#4CC38A; --plan:#B79BE0; --soon:#E0B060; --stand:#2A3B4D; --mat:#3A3324; --warn:#E06A72; color-scheme:dark }
*{box-sizing:border-box}
body{background:var(--bg);color:var(--ink);font-family:var(--f-body);font-size:15px;line-height:1.55}
.wrap{max-width:1280px;margin:0 auto;padding-inline:20px;padding-block:28px 56px;display:flex;flex-direction:column;gap:28px}
h1,h2,h3{font-family:var(--f-display);text-wrap:balance;margin:0;line-height:1.15}
h1{font-size:clamp(28px,4.2vw,44px);font-weight:800;font-stretch:88%;letter-spacing:-.01em}
h2{font-size:22px;font-weight:700;font-stretch:92%}
h3{font-size:16px;font-weight:700}
p{margin:0}
a{color:var(--accent)}
.eyebrow{font-family:var(--f-mono);font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
.lede{max-width:66ch;color:var(--ink2);font-size:16px;margin-top:10px}
.head{display:flex;flex-direction:column;gap:6px}
.legend{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:13px;color:var(--ink2);margin-top:12px}
.legend span{display:inline-flex;align-items:center;gap:7px}
.sw{width:22px;height:5px;border-radius:3px;display:inline-block;flex:none}
.sw.dash{background:repeating-linear-gradient(90deg,var(--ink3) 0 5px,transparent 5px 9px)}
.dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex:none}
.ring{width:13px;height:13px;border-radius:50%;border:3px solid;display:inline-block;flex:none;background:var(--surface)}
.chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.chips .lbl{font-family:var(--f-mono);font-size:11.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-right:4px}
.chip{font:500 13.5px var(--f-body);color:var(--ink2);background:var(--surface);border:1px solid var(--line);border-radius:999px;padding:6px 14px;cursor:pointer}
.chip b{font-family:var(--f-mono);font-weight:500;margin-right:6px;color:var(--ink3)}
.chip:hover{border-color:var(--accent);color:var(--ink)}
.chip[aria-pressed="true"]{background:var(--accent);border-color:var(--accent);color:var(--bg);font-weight:600}
.chip[aria-pressed="true"] b{color:var(--bg)}
.chip:focus-visible,.tag:focus-visible,.stage canvas:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.board{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:stretch}
.stage{position:relative;min-width:0;border:1px solid var(--line);border-radius:14px;background:var(--surface);overflow:hidden;aspect-ratio:16/10.5;max-width:100%;touch-action:pan-y}
.stage canvas{display:block;width:100%;height:100%;cursor:grab}
.stage canvas:active{cursor:grabbing}
.tags{position:absolute;inset:0;pointer-events:none;overflow:hidden}
.tag{position:absolute;left:0;top:0;transform:translate(-50%,-100%);pointer-events:auto;white-space:nowrap;font:600 11px var(--f-body);color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:7px;padding:2px 7px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;box-shadow:0 2px 6px rgba(10,20,30,.12)}
.tag:hover,.tag.on{border-color:var(--accent)}
.tag.on{background:var(--accent);color:var(--bg)}
.tag.dim{opacity:.38}
.tag .dot{width:7px;height:7px}
.tag.hubtag{font:800 15px var(--f-display);font-stretch:90%;padding:5px 12px}
.mod{position:absolute;left:0;top:0;transform:translate(-50%,-50%);font:500 10.5px var(--f-mono);letter-spacing:.02em;color:var(--ink);text-align:center;white-space:nowrap;pointer-events:none;line-height:1.25}
.mod b{display:block;font:800 15px var(--f-display)}
.mod.on{color:var(--ink)}
.note3d{position:absolute;left:0;top:0;transform:translate(-50%,-50%);font:500 10.5px var(--f-mono);color:var(--ink2);white-space:nowrap;pointer-events:none;background:var(--surface);border:1px dashed var(--line);border-radius:6px;padding:2px 7px}
.note3d.who{font:600 12.5px var(--f-body);color:var(--ink);border-style:solid;padding:3px 9px;box-shadow:0 2px 8px rgba(10,20,30,.12)}
.hint{position:absolute;right:10px;bottom:8px;font:400 11.5px var(--f-mono);color:var(--ink3);pointer-events:none}
.fallback{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;color:var(--ink2)}
.panel{min-width:0;border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:18px 18px 20px;display:flex;flex-direction:column;gap:12px}
.panel h3{font-size:19px}
.panel p{font-size:14px;color:var(--ink2)}
.panel dl{margin:0;display:flex;flex-direction:column;gap:10px}
.panel dt{font-family:var(--f-mono);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);display:flex;align-items:center;gap:7px}
.panel dd{margin:2px 0 0;font-size:14px;color:var(--ink)}
.panel ol,.panel ul{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:6px;font-size:14px}
.status{display:inline-flex;align-items:center;gap:6px;font:500 12px var(--f-mono);border:1px solid var(--line);border-radius:999px;padding:2px 9px;white-space:nowrap}
.flowlist{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px;font-size:13.5px}
.flowlist li{display:grid;grid-template-columns:22px minmax(0,1fr);gap:8px;align-items:start}
.flowlist .sw{margin-top:9px}
.navrow{display:flex;gap:8px;margin-top:auto;padding-top:6px}
.navrow button{font:500 13px var(--f-body);color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:6px 12px;cursor:pointer}
.navrow button:hover{border-color:var(--accent)}
.navrow button:disabled{opacity:.4;cursor:default}
section{display:flex;flex-direction:column;gap:14px}
section>p{max-width:72ch;color:var(--ink2)}
.tw{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:var(--surface)}
table{border-collapse:collapse;width:100%;min-width:920px;font-size:13.5px}
th{font:500 11px var(--f-mono);letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);text-align:left;padding:10px 12px;border-bottom:1px solid var(--line)}
td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
td:first-child{font-weight:600}
td ol{margin:0;padding-left:18px}
.num{font-family:var(--f-mono);color:var(--ink3);margin-right:6px}
.rules{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px 28px;max-width:1100px}
.rules p{font-size:14px;color:var(--ink2)}
.rules b{display:block;color:var(--ink);font-family:var(--f-display);font-size:15px;margin-bottom:2px}
.foot{font-size:13px;color:var(--ink3);max-width:80ch}
@media (max-width:1000px){ .board{grid-template-columns:minmax(0,1fr)} .stage{aspect-ratio:1/1} }
@media (max-width:520px){ .wrap{padding-inline:16px} .stage{aspect-ratio:1/1} .tag{font-size:10.5px;padding:3px 6px} .mod span{display:none} .mod b{font-size:12px} .hint{display:none} .note3d{font-size:9px} }
</style>

<div class="wrap">
  <header class="head">
    <div class="eyebrow">OpsHub · company systems · build 0621-BT51 · 8 October 2026</div>
    <h1>From switch-on to a verified exit</h1>
    <p class="lede">Every system a person has an account in goes through the same six steps in OpsHub. What changes from system to system is who does the work: OpsHub by itself, OpsHub by reading, or a person who answers in OpsHub. These are the same systems as on the Integration map.</p>
    <div class="legend" aria-label="Legend">
      <span><i class="sw" style="background:var(--out)"></i>OpsHub changes it there</span>
      <span><i class="sw" style="background:var(--in)"></i>OpsHub reads it</span>
      <span><i class="sw dash"></i>a person does it there, then answers in OpsHub</span>
      <span><i class="ring" style="border-color:var(--live)"></i>OpsHub acts by itself</span>
      <span><i class="ring" style="border-color:var(--in)"></i>connected, read only</span>
      <span><i class="ring" style="border-color:var(--plan)"></i>tracked by hand, connection planned</span>
      <span><i class="ring" style="border-color:var(--soon)"></i>planned, not in use yet</span>
      <span><i class="ring" style="border-color:var(--manual)"></i>no connection, follows Microsoft 365</span>
    </div>
  </header>

  <div class="chips" role="group" aria-label="Show one step of the process">
    <span class="lbl">Step</span>
    <button class="chip" id="chip-all" data-step="" aria-pressed="true">Whole picture</button>
    <button class="chip" id="chip-1" data-step="1" aria-pressed="false"><b>1</b>Set up</button>
    <button class="chip" id="chip-2" data-step="2" aria-pressed="false"><b>2</b>Accounts become known</button>
    <button class="chip" id="chip-3" data-step="3" aria-pressed="false"><b>3</b>Onboarding</button>
    <button class="chip" id="chip-4" data-step="4" aria-pressed="false"><b>4</b>Offboarding</button>
    <button class="chip" id="chip-5" data-step="5" aria-pressed="false"><b>5</b>Verification</button>
    <button class="chip" id="chip-6" data-step="6" aria-pressed="false"><b>6</b>The record</button>
  </div>

  <div class="board">
    <div class="stage" id="stage">
      <canvas id="gl" tabindex="0" aria-label="3D picture of the six steps on the OpsHub platform, the company’s systems around it, each drawn as an object of its own, and the HR or IT user in front. Drag to turn it. The same information is in the tables below."></canvas>
      <div class="tags" id="tags"></div>
      <div class="hint">drag to turn · click a system or a step</div>
      <div class="fallback" id="fallback" hidden>This browser could not draw the 3D view. Everything it shows is in the tables below.</div>
    </div>
    <aside class="panel" id="panel" aria-live="polite"></aside>
  </div>

  <section>
    <h2>The six steps, by who does the work</h2>
    <div class="tw"><table id="steps"></table></div>
  </section>

  <section>
    <h2>What each page shows</h2>
    <div class="tw"><table id="pages"></table></div>
  </section>

  <section>
    <h2>Connecting each system</h2>
    <p>For the systems a person has an account in, the same steps are on each system’s set-up page in OpsHub, in the box “How to connect”. They were taken from each vendor’s own help. Only JustCall has a place to paste a key in this build.</p>
    <div class="tw"><table id="connect"></table></div>
  </section>

  <section>
    <h2>What keeps a reading honest</h2>
    <div class="rules">
      <p><b>“Could not read” is never “nothing there”</b>A reading that fails changes nothing, and a step or a verification that depends on it stays open.</p>
      <p><b>A list must be complete</b>If a later page fails, or the system says it has more users than were read, the whole reading is refused.</p>
      <p><b>A list that shrinks too much is held back</b>If most of the known users are missing at once, nobody is closed until a person looks and accepts the list.</p>
      <p><b>People are matched by exact email</b>Never by name. When the email does not tell, a person assigns the account, and a reading never undoes that.</p>
      <p><b>A key is never shown again</b>It is stored encrypted, never sent back to a browser, and never written to a log, also when the other system repeats it in an error.</p>
      <p><b>Everything is on the record</b>Each step, answer, reading and correction is in the Audit Log with who, when, the person, the system, and the old and new values.</p>
    </div>
  </section>

  <p class="foot">Microsoft 365 and vTiger are run by OpsHub. JustCall and WHM are read. HubSpot, eFax, Jotform and the phone system are tracked by hand until their connections are built. Paylocity and the hosting provider are planned. A system added under Settings → Company systems appears here and on the Integration map by itself: both pages show the same list. The plan is the project document OpsHub/INTEGRATIONS-ROADMAP.md.</p>
</div>

<script src="vendor/three.min.js?v=r128"></script>
<script src="api/help_script/systems-process"></script>

OPSH_VISUAL;
