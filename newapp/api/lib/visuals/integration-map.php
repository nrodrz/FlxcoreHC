<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/* BT50: a Help Center visual. BT51: the systems come from the shared list (visuals/systems.php, window.ITOPS_SYSTEMS),
   the same list as "Company systems: the whole process"; light and dark theme. Served only through api/routes/help.php to the people it is given to; requested
   directly, this file prints nothing. */
return <<<'ITOPS_VISUAL'
<title>ITOps Integration Map</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..125,400..800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Layout: a wide 3D stage with a reading panel beside it; under it, the same facts as tables so nothing depends on the 3D view. */
:root{
  --bg:#EEF2F6; --surface:#FFFFFF; --ground:#D3DDE8; --ink:#16222E; --ink2:#44566A; --ink3:#6B7C8F; --line:#C9D4DF;
  --accent:#2F6FAE; --accentSoft:#D9E7F5; --hub:#F7FAFC; --hubBase:#1F4E7C; --tile:#C7DBEE;
  --in:#0F8A7C; --out:#C96A12; --manual:#7E8B99; --live:#1F7A4D; --plan:#8A6BB0; --soon:#A9772A; --stand:#FFFFFF; --zone:#EADFCB; --wall:#B9A27A; --block:#FBFCFD; --blockPlan:#CFC3E0;
  --f-display:'Archivo','Arial Narrow',Arial,sans-serif; --f-body:'IBM Plex Sans','Segoe UI',system-ui,sans-serif; --f-mono:'IBM Plex Mono',ui-monospace,Menlo,Consolas,monospace;
}
@media (prefers-color-scheme: dark){ :root:not([data-theme="light"]){
  --bg:#0F1720; --surface:#17222E; --ground:#1B2836; --ink:#E8EEF4; --ink2:#B4C2D0; --ink3:#8496A8; --line:#2C3C4D;
  --accent:#6BA6DE; --accentSoft:#1D3550; --hub:#2A3B4D; --hubBase:#3F7DB8; --tile:#35526F;
  --in:#3CC4B4; --out:#F0A050; --manual:#8B98A6; --live:#4CC38A; --plan:#B79BE0; --soon:#E0B060; --stand:#2A3B4D; --zone:#3A3324; --wall:#8C7A55; --block:#33475C; --blockPlan:#4A3F63; color-scheme:dark } }
:root[data-theme="dark"]{
  --bg:#0F1720; --surface:#17222E; --ground:#1B2836; --ink:#E8EEF4; --ink2:#B4C2D0; --ink3:#8496A8; --line:#2C3C4D;
  --accent:#6BA6DE; --accentSoft:#1D3550; --hub:#2A3B4D; --hubBase:#3F7DB8; --tile:#35526F;
  --in:#3CC4B4; --out:#F0A050; --manual:#8B98A6; --live:#4CC38A; --plan:#B79BE0; --soon:#E0B060; --stand:#2A3B4D; --zone:#3A3324; --wall:#8C7A55; --block:#33475C; --blockPlan:#4A3F63; color-scheme:dark }
*{box-sizing:border-box}
body{background:var(--bg);color:var(--ink);font-family:var(--f-body);font-size:15px;line-height:1.55}
.wrap{max-width:1280px;margin:0 auto;padding-inline:20px;padding-block:28px 56px;display:flex;flex-direction:column;gap:28px}
h1,h2,h3{font-family:var(--f-display);text-wrap:balance;margin:0;line-height:1.15}
h1{font-size:clamp(28px,4.2vw,44px);font-weight:800;font-stretch:88%;letter-spacing:-.01em}
h2{font-size:22px;font-weight:700;font-stretch:92%}
h3{font-size:16px;font-weight:700}
p{margin:0}
.eyebrow{font-family:var(--f-mono);font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
.lede{max-width:66ch;color:var(--ink2);font-size:16px;margin-top:10px}
.head{display:flex;flex-direction:column;gap:6px}
.legend{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:13px;color:var(--ink2);margin-top:12px}
.legend span{display:inline-flex;align-items:center;gap:7px}
.sw{width:22px;height:5px;border-radius:3px;display:inline-block}
.sw.dash{background:repeating-linear-gradient(90deg,var(--ink3) 0 5px,transparent 5px 9px)}
.dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex:none}
.ring{width:13px;height:13px;border-radius:50%;border:3px solid;display:inline-block;flex:none;background:var(--surface)}
.chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.chips .lbl{font-family:var(--f-mono);font-size:11.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-right:4px}
.chip{font:500 13.5px var(--f-body);color:var(--ink2);background:var(--surface);border:1px solid var(--line);border-radius:999px;padding:6px 14px;cursor:pointer}
.chip:hover{border-color:var(--accent);color:var(--ink)}
.chip[aria-pressed="true"]{background:var(--accent);border-color:var(--accent);color:var(--bg);font-weight:600}
.chip:focus-visible,.tag:focus-visible,.stage canvas:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.board{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:stretch}
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
.mod{position:absolute;left:0;top:0;transform:translate(-50%,-50%);font:500 10.5px var(--f-mono);letter-spacing:.02em;color:var(--ink);text-align:center;white-space:nowrap;pointer-events:none;text-shadow:0 0 4px var(--tile),0 0 4px var(--tile)}
.note3d{position:absolute;left:0;top:0;transform:translate(-50%,-50%);font:500 10.5px var(--f-mono);color:var(--ink2);white-space:nowrap;pointer-events:none;background:var(--surface);border:1px dashed var(--line);border-radius:6px;padding:2px 7px}
.stepn{position:absolute;left:0;top:0;transform:translate(-50%,-50%);width:22px;height:22px;border-radius:50%;background:var(--ink);color:var(--bg);font:600 12px var(--f-mono);display:flex;align-items:center;justify-content:center;pointer-events:none}
.hint{position:absolute;right:10px;bottom:8px;font:400 11.5px var(--f-mono);color:var(--ink3);pointer-events:none}
.fallback[hidden]{display:none}
.fallback{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;color:var(--ink2)}
.panel{min-width:0;border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:18px 18px 20px;display:flex;flex-direction:column;gap:12px}
.panel h3{font-size:19px}
.panel .how{font-family:var(--f-mono);font-size:12px;color:var(--ink2);background:var(--bg);border-radius:8px;padding:8px 10px;overflow-wrap:anywhere}
.panel dl{margin:0;display:flex;flex-direction:column;gap:10px}
.panel dt{font-family:var(--f-mono);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3)}
.panel dd{margin:2px 0 0;font-size:14px;color:var(--ink)}
.panel ol{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:7px;font-size:14px}
.status{display:inline-flex;align-items:center;gap:6px;font:500 12px var(--f-mono);border:1px solid var(--line);border-radius:999px;padding:2px 9px;white-space:nowrap}
.flowlist{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px;font-size:13.5px}
.flowlist li{display:grid;grid-template-columns:22px minmax(0,1fr);gap:8px;align-items:start}
.flowlist .sw{margin-top:9px}
section{display:flex;flex-direction:column;gap:14px}
.tw{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:var(--surface)}
table{border-collapse:collapse;width:100%;min-width:900px;font-size:13.5px}
th{font:500 11px var(--f-mono);letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);text-align:left;padding:10px 12px;border-bottom:1px solid var(--line)}
td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
td:first-child{font-weight:600;white-space:nowrap}
td code,.panel code{font-family:var(--f-mono);font-size:12px;overflow-wrap:anywhere}
.scen{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:22px 28px}
.scen>div{min-width:0;border-top:2px solid var(--ink);padding-top:12px;display:flex;flex-direction:column;gap:8px}
.scen ol{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:6px;font-size:14px;color:var(--ink2)}
.rules{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px 28px;max-width:1100px}
.rules p{font-size:14px;color:var(--ink2)}
.rules b{display:block;color:var(--ink);font-family:var(--f-display);font-size:15px;margin-bottom:2px}
.foot{font-size:13px;color:var(--ink3);max-width:80ch}
@media (max-width:960px){ .board{grid-template-columns:minmax(0,1fr)} .stage{aspect-ratio:1/1} }
@media (max-width:520px){ .wrap{padding-inline:16px} .stage{aspect-ratio:4/5} .tag{font-size:11px;padding:3px 7px} .mod{font-size:9px} .hint{display:none} }
</style>

<div class="wrap">
  <header class="head">
    <div class="eyebrow">ITOps · integrations · build 0621-BT51 · 8 October 2026</div>
    <h1>One person, every account</h1>
    <p class="lede">ITOps sits in the middle. Each system around it either tells ITOps something, takes a change from it, or both. These are the same systems as on the page “Company systems: the whole process”. Each system stands on a round stand; its ring shows how ITOps works with it.</p>
    <div class="legend" aria-label="Legend">
      <span><i class="sw" style="background:var(--in)"></i>into ITOps (it reads or receives)</span>
      <span><i class="sw" style="background:var(--out)"></i>out of ITOps (it changes something)</span>
      <span><i class="sw" style="background:var(--accent)"></i>both ways</span>
      <span><i class="sw dash"></i>planned, or done by hand</span>
      <span><i class="ring" style="border-color:var(--live)"></i>ITOps acts by itself</span>
      <span><i class="ring" style="border-color:var(--in)"></i>connected, ITOps reads it</span>
      <span><i class="ring" style="border-color:var(--plan)"></i>tracked by hand, connection planned</span>
      <span><i class="ring" style="border-color:var(--soon)"></i>planned, not in use yet</span>
      <span><i class="ring" style="border-color:var(--manual)"></i>no connection, follows Microsoft 365</span>
    </div>
  </header>

  <div class="chips" role="group" aria-label="Follow one thing through the map">
    <span class="lbl">Follow</span>
    <button class="chip" id="chip-all" data-sc="" aria-pressed="true">Everything</button>
    <button class="chip" id="chip-hire" data-sc="hire" aria-pressed="false">A new hire</button>
    <button class="chip" id="chip-leave" data-sc="leave" aria-pressed="false">A departure</button>
    <button class="chip" id="chip-call" data-sc="call" aria-pressed="false">A missed call</button>
    <button class="chip" id="chip-sign" data-sc="sign" aria-pressed="false">A signature change</button>
    <button class="chip" id="chip-host" data-sc="host" aria-pressed="false">Hosting</button>
  </div>

  <div class="board">
    <div class="stage" id="stage">
      <canvas id="gl" tabindex="0" aria-label="3D map of ITOps and the systems it connects to. Drag to turn it. The same information is in the tables below."></canvas>
      <div class="tags" id="tags"></div>
      <div class="hint">drag to turn · click a system</div>
      <div class="fallback" id="fallback" hidden>This browser could not draw the 3D view. Everything it shows is in the tables below.</div>
    </div>
    <aside class="panel" id="panel" aria-live="polite"></aside>
  </div>

  <section>
    <h2>What each connection can do</h2>
    <div class="tw"><table id="matrix"></table></div>
  </section>

  <section>
    <h2>How they work together</h2>
    <div class="scen" id="scen"></div>
  </section>

  <section>
    <h2>Rules that hold for every connection</h2>
    <div class="rules">
      <p><b>Read first, write second</b>Each service ships read-only. Anything that changes the other system comes later, behind its own permission and a confirmation that names what will change.</p>
      <p><b>Nothing runs by itself</b>An event from Paylocity creates a draft that a person reviews. It never disables an account on its own.</p>
      <p><b>By hand until connected</b>A system without a connection is still on every onboarding and offboarding: a person does the work there and answers in ITOps, and the answer is on the record.</p>
      <p><b>Minimum data</b>Pay, bank details, SSN, home address and date of birth are never requested, and are dropped on receipt if a sender includes them.</p>
      <p><b>Everything is logged</b>Every change goes to the Audit Log with who, when and the old and new value. Every failed call goes to the Event Log.</p>
    </div>
  </section>

  <p class="foot">Microsoft 365 and vTiger are run by ITOps. JustCall and WHM are read. HubSpot, eFax, Jotform and the phone system are tracked by hand until their connections are built. Paylocity and the hosting provider are planned. A system added under Settings → Company systems appears here and on the Company systems page by itself: both pages show the same list. The plan is the project document ITOps/INTEGRATIONS-ROADMAP.md.</p>
</div>

<script src="vendor/three.min.js?v=r128"></script>
<script src="api/help_script/integration-map"></script>

ITOPS_VISUAL;
