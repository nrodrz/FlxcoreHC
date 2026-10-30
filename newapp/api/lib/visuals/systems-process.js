/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* ITOps Help Center visual "systems-process" (BT90: served by api/help_script, so the page policy allows no inline scripts). */
(function(){
'use strict';
/* ───────── data: one list drives the 3D view, the panel and the tables ───────── */
var KIND={acts:['ITOps acts by itself','--live'],reads:['connected · read only','--in'],hand:['tracked by hand · connection planned','--plan']};
var STEPS=[
 {n:1,id:'s1',name:'Set up',short:'Set up',x:-8.75,
  what:'Each system is switched on once, on its own page: how it is used, the link to its admin page, whether it takes part in onboarding and offboarding, and what to do there in your own words.',
  acts:'Already connected. Microsoft 365: Settings → Company systems → Microsoft 365 (Set up…). vTiger: Settings → Company systems → vTiger (Set up…).',
  reads:'A Super Admin pastes the JustCall key and secret, presses “Save the key and test”, then chooses “Connected”. JustCall is read at once.',
  hand:'Choose “Tracked by hand”, check the link, and write the instructions people will see on the step.',
  other:'Paylocity and the hosting provider: nothing until they are connected. CodeTwo: nothing, it reads Microsoft 365. WHM: its token is entered once for Server Guardian.',
  where:'Administration → Settings → Company systems → “Switch on…”'},
 {n:2,id:'s2',name:'The accounts become known',short:'Accounts',x:-5.25,
  what:'ITOps has to know who has an account where, so offboarding can name exactly what to remove.',
  acts:'Read live every time: the Microsoft 365 account and its state, and the vTiger user.',
  reads:'ITOps reads every JustCall user with role and numbers: on “Read now”, when a step is opened or answered, at verification, and every four hours.',
  hand:'A person records the accounts once: from a pasted list under Settings → Company systems (a user export usually fits), or on the person, under Accounts. From then on onboarding and offboarding keep the records up to date.',
  other:'Paylocity (planned) will say who works here: its roster, read every night. CodeTwo and the servers hold no account of their own for a person.',
  where:'Microsoft 365 → Accounts, the block “Systems” on a person · the JustCall tab · “Needs attention” on Company systems'},
 {n:3,id:'s3',name:'Onboarding',short:'Onboarding',x:-1.75,
  what:'On Onboard, tick the systems the new person needs; a template can tick them. The record stays open until every system is answered.',
  acts:'ITOps creates the Microsoft 365 account with its license and groups, and the vTiger user.',
  reads:'A person adds the user in JustCall with the work email, then clicks “Check JustCall for the user”. The step is done when JustCall lists them.',
  hand:'A box shows your instructions and the link. A person sets the account up there, types its name, and clicks “The account is set up”. It is recorded for the person.',
  other:'Paylocity (planned): a hire creates a draft onboarding that a person reviews. CodeTwo picks up the new account for the signature by itself.',
  where:'Microsoft 365 → Accounts → Onboard, and History'},
 {n:4,id:'s4',name:'Offboarding',short:'Offboarding',x:1.75,
  what:'On Offboard, each system is listed with the accounts known for the person. Run it; the offboarding stays “in progress” until every system is answered.',
  acts:'ITOps blocks sign-in, revokes sessions, removes licenses and groups in Microsoft 365; reassigns the records and deactivates the user in vTiger.',
  reads:'A person reassigns the numbers and removes the user in JustCall, then clicks “I removed the user: check JustCall again”. The step is done only when JustCall no longer lists them.',
  hand:'A person does the work there and answers: “Removed there”, “Handed over to a colleague…” or “Checked: no account there”. The recorded account is closed or moved.',
  other:'Paylocity (planned): a termination creates a draft offboarding dated to the last day. CodeTwo stops with the Microsoft 365 account. The servers take no part.',
  where:'Microsoft 365 → Accounts → Offboard, and History'},
 {n:5,id:'s5',name:'Verification',short:'Verification',x:5.25,
  what:'“Verify offboarding”, and the monthly re-check of past leavers, has one line per system. Any line that fails keeps the offboarding from being clean.',
  acts:'Asked live: is sign-in blocked, are the licenses gone, is the vTiger user inactive, does it still own records.',
  reads:'“Account removed (read from JustCall)”: JustCall is asked at that moment. It fails when the user is still listed or JustCall cannot be read.',
  hand:'“Account removed (recorded by hand)”: ITOps reports what was answered. It fails while an account is still recorded as open or the step is unanswered.',
  other:'Not asked: Paylocity, CodeTwo and the servers hold no account of a person to remove.',
  where:'The offboarding record · Verify offboarding'},
 {n:6,id:'s6',name:'The record',short:'The record',x:8.75,
  what:'Every step, answer, reading and correction is kept on the person’s record and in the Audit Log. It is the evidence that a leaver lost every access, system by system.',
  acts:'Each change ITOps made: what, when, and the result.',
  reads:'Each reading that changed something, with a line per user that appeared, disappeared or changed, and whether a person or the scheduled job read.',
  hand:'Each answer with who gave it and when, and every correction afterwards.',
  other:'A draft that came from Paylocity will say so on the record.',
  where:'History · Logs & Integrations → Audit Log, type “access”'}
];
/* BT51: the systems are the shared list (the same as on the Integration map). */
var KIND={acts:['ITOps acts by itself','--live'],reads:['connected · ITOps reads it','--in'],hand:['tracked by hand · connection planned','--plan'],
  planned:['planned · not in use yet','--soon'],indirect:['no connection · follows Microsoft 365','--manual']};
var NODES=(window.ITOPS_SYSTEMS||[]).filter(function(n){ return KIND[n.status]; }).map(function(n){ var o={}; for(var k in n)o[k]=n[k]; o.kind=n.status; return o; });
/* who does the work in the steps: the column a system belongs to */
var grp=function(n){ return n.role!=='people'?'other':(n.status==='acts'?'acts':(n.status==='reads'?'reads':'hand')); };
var namesIn=function(g){ return NODES.filter(function(n){ return grp(n)===g; }).map(function(n){ return n.name; }); };
var listOf=function(a){ return !a.length?'—':(a.length<2?a[0]:a.slice(0,-1).join(', ')+' and '+a[a.length-1]); };
var GRP={acts:['--live',listOf(namesIn('acts'))],reads:['--in',listOf(namesIn('reads'))],hand:['--plan',listOf(namesIn('hand'))],other:['--soon',listOf(namesIn('other'))]};
/* on an arc behind the platform, from the front left over the back to the front right; wider when there are more */
var RX=Math.max(15.5,(NODES.length-1)*1.42), RZ=RX*.78, ZC=-1.2;
NODES.forEach(function(n,i){ var t=NODES.length<2?.5:i/(NODES.length-1), a=(205-t*230)*Math.PI/180; n.x=RX*Math.cos(a); n.z=ZC-RZ*Math.sin(a); });
var PERSON={id:'it',name:'HR or IT user',x:0,z:8.2,h:3.9};
/* a → b is the direction the information or the change travels. step '' = the whole picture */
/* a → b is the direction the information or the change travels. step '' = the whole picture. Made from each
   system's column, so a system added to the list takes its part in every step by itself. */
var SAY={
 m365:{c:'Creates the account, license and groups',d:'Blocks sign-in, revokes sessions, removes licenses and groups',e:'Asked live: sign-in blocked, licenses removed',b:'People and their state, read live'},
 vtiger:{c:'Creates the CRM user',d:'Reassigns the records, deactivates the user',e:'Asked live: user inactive, no records owned',b:'CRM users, read live'}};
var FLOWS=[], fid=0;
function fl(step,a,b,dir,t){ FLOWS.push({id:'f'+(++fid),step:step,a:a,b:b,dir:dir,t:t}); }
NODES.forEach(function(n){ var g=grp(n), sy=SAY[n.id]||{}, id=n.id;
  if(g==='acts'){ fl('',id,'hub','both','ITOps reads it and acts in it by itself'); fl('2',id,'s2','in',sy.b||'Accounts read live'); fl('3','s3',id,'out',sy.c||'Creates the account'); fl('4','s4',id,'out',sy.d||'Closes the account'); fl('5',id,'s5','in',sy.e||'Asked live: the account is closed'); }
  else if(g==='reads'){ fl('',id,'hub','in','ITOps reads it; a person makes the changes there'); fl('1',id,'s1','in','The key is pasted once; the test reads one user');
    fl('2',id,'s2','in','Users, roles and numbers: on demand, at each step, every four hours'); fl('3',id,'s3','in','A person adds the user there; ITOps checks that it lists them');
    fl('4',id,'s4','in','A person removes the user there; done when it no longer lists them'); fl('5',id,'s5','in','Asked at that moment: no user for this person'); }
  else if(g==='hand'){ fl('','hub',id,'manual','A person does the work there and answers in ITOps'); fl('2',id,'s2','manual','Recorded by a person: a pasted list, or per person');
    fl('3','s3',id,'manual','Set up by hand; answered “The account is set up”'); fl('4','s4',id,'manual','Removed by hand; answered removed, handed over, or no account'); }
  else if(n.role==='source'){ fl('',id,'hub','plan','Planned: tells ITOps who joins and who leaves'); fl('2',id,'s2','plan','Planned: the roster, read every night');
    fl('3',id,'s3','plan','Planned: a hire creates a draft onboarding'); fl('4',id,'s4','plan','Planned: a termination creates a draft offboarding'); }
  else if(n.role==='follows'){ var m=NODES.some(function(x){ return x.id==='m365'; })?'m365':'hub';
    fl('',m,id,'ext','Reads the Microsoft 365 account by itself'); fl('3',m,id,'ext','Picks up the new account for the signature'); fl('4',m,id,'ext','Stops with the Microsoft 365 account'); }
  else { fl('',id,'hub',n.status==='reads'?'in':'plan',n.status==='reads'?'Server Guardian reads it':'Planned: servers, tickets and invoices'); }
});
fl('','it','hub','manual','The HR or IT user sets the systems up and answers the steps that are done by hand');
fl('1','it','s1','manual','Chooses the use, the link and the instructions for each system');
fl('3','it','s3','manual','Runs the onboarding and answers each box');
fl('4','it','s4','manual','Runs the offboarding and answers each box');
var PAGES=[
 ['Microsoft 365 → Accounts, a person','The block “Systems” on the person’s panel: Microsoft 365, vTiger and every system in use, with the person’s account in each, open and closed, and who recorded or read it. For a system tracked by hand: record, correct, close and open again, in place. For a connected system: “Read from JustCall: change it there.”'],
 ['Microsoft 365 → Accounts, the list','One account per row. The column “Other systems” says how many accounts a person has in the other systems; in red, with “still open”, when sign-in is blocked and one is still open.'],
 ['Settings → Company systems → Systems','Microsoft 365 and vTiger with how many accounts each has. Every other system with its use (Not used, Tracked by hand, Connected), the steps it adds and its open accounts. “Switch on…” opens the set-up page, with “How to connect” and “How the process works”. “Record accounts from a list…” takes a pasted export.'],
 ['Settings → Company systems, “Needs attention”','Only the people to look at, instead of a second list of everybody. Red: blocked in Microsoft 365 while the vTiger user is active or an account elsewhere is open. Amber: an account under an email nobody has. Also an active vTiger user without a Microsoft 365 account. A row opens the person.'],
 ['Settings → Company systems → JustCall (a tab per connected system)','Every user JustCall lists: role, group, numbers, extension, last sign-in, and the person it is matched to. “Read now”, “Assign…”, and a warning when a list is held back or a reading failed.'],
 ['Onboard, Offboard and History','One box per system with your instructions, the link, the accounts by name, and the answer buttons. The same boxes stay in History until answered.'],
 ['Audit Log, type “access”','Every system switched on or changed, every key saved (never the key itself), every account recorded, read, assigned or closed, every step answered.']
];
var CONNECT=NODES.map(function(n){ return [n.name,KIND[n.kind][0]+(n.in_use===false?' · not switched on here':''),n.who||'—',n.connect||'—',n.mind||'—']; });
var byId={}; NODES.forEach(function(n){ byId[n.id]=n; });
var stepById={}; STEPS.forEach(function(s){ stepById[s.id]=s; });
var esc=function(s){ return String(s).replace(/[&<>"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
var nameOf=function(id){ return byId[id]?byId[id].name:(stepById[id]?stepById[id].n+' '+stepById[id].short:(id==='it'?PERSON.name:'ITOps')); };
var dirColor={in:'--in',out:'--out',both:'--accent',manual:'--manual',plan:'--soon',ext:'--manual'};
var dirWord={in:'ITOps reads',out:'ITOps changes',both:'reads and changes',manual:'by hand',plan:'planned',ext:'by itself, outside ITOps'};
var DASH={manual:1,plan:1};

/* ───────── tables (always on the page) ───────── */
(function(){
  var h='<thead><tr><th>Step</th><th><i class="dot" style="background:var(--live)"></i> '+esc(GRP.acts[1])+'</th><th><i class="dot" style="background:var(--in)"></i> '+esc(GRP.reads[1])+', connected</th><th><i class="dot" style="background:var(--plan)"></i> Tracked by hand: '+esc(GRP.hand[1])+'</th><th><i class="dot" style="background:var(--soon)"></i> No personal accounts: '+esc(GRP.other[1])+'</th><th>Where you see it</th></tr></thead><tbody>';
  STEPS.forEach(function(s){ h+='<tr><td><span class="num">'+s.n+'</span>'+esc(s.name)+'</td><td>'+esc(s.acts)+'</td><td>'+esc(s.reads)+'</td><td>'+esc(s.hand)+'</td><td>'+esc(s.other)+'</td><td>'+esc(s.where)+'</td></tr>'; });
  document.getElementById('steps').innerHTML=h+'</tbody>';
  h='<thead><tr><th>Page</th><th>What it shows</th></tr></thead><tbody>';
  PAGES.forEach(function(p){ h+='<tr><td>'+esc(p[0])+'</td><td>'+esc(p[1])+'</td></tr>'; });
  document.getElementById('pages').innerHTML=h+'</tbody>';
  h='<thead><tr><th>System</th><th>Today</th><th>Who can get the key</th><th>Where the key is made</th><th>Keep in mind</th></tr></thead><tbody>';
  CONNECT.forEach(function(c){ h+='<tr>'+c.map(function(x){ return '<td>'+esc(x)+'</td>'; }).join('')+'</tr>'; });
  document.getElementById('connect').innerHTML=h+'</tbody>';
})();

/* ───────── panel ───────── */
var state={sel:null,step:''};
var panel=document.getElementById('panel');
function flowLine(f){ var dash=!!DASH[f.dir]; return '<li><i class="sw'+(dash?' dash':'')+'"'+(dash?'':' style="background:var('+dirColor[f.dir]+')"')+'></i><span><b>'+esc(nameOf(f.a))+' → '+esc(nameOf(f.b))+'</b> · '+esc(dirWord[f.dir])+'<br>'+esc(f.t)+'</span></li>'; }
function kindRow(k,text){ return '<div><dt><i class="ring" style="border-color:var('+GRP[k][0]+')"></i>'+esc(GRP[k][1])+'</dt><dd>'+esc(text)+'</dd></div>'; }
function renderPanel(){
  var h='';
  if(state.step){ var s=STEPS[+state.step-1];
    h='<div class="eyebrow">Step '+s.n+' of 6</div><h3>'+esc(s.name)+'</h3><p>'+esc(s.what)+'</p><dl>'+kindRow('acts',s.acts)+kindRow('reads',s.reads)+kindRow('hand',s.hand)+kindRow('other',s.other)
     +'<div><dt>Where you see it</dt><dd>'+esc(s.where)+'</dd></div></dl>'
     +'<div class="navrow"><button type="button" id="nav-prev"'+(s.n===1?' disabled':'')+'>← Step '+(s.n-1||'')+'</button><button type="button" id="nav-next"'+(s.n===6?' disabled':'')+'>Step '+(s.n<6?s.n+1:'')+' →</button></div>';
  } else if(state.sel&&byId[state.sel]){ var n=byId[state.sel], k=KIND[n.kind];
    var fl=FLOWS.filter(function(f){ return f.step&&(f.a===n.id||f.b===n.id); });
    h='<div><span class="status"><i class="dot" style="background:var('+k[1]+')"></i>'+esc(k[0])+'</span></div><h3>'+esc(n.name)+'</h3><p>'+esc(n.what)+'</p>'
     +(n.in_use===false?'<p style="font-size:13px;color:var(--ink3)">Not switched on under Settings → Company systems.</p>':'')+'<dl>'
     +'<div><dt>'+(n.kind==='acts'?'How it is connected':'How it connects')+'</dt><dd>'+esc(n.connect)+'</dd></div><div><dt>Keep in mind</dt><dd>'+esc(n.mind)+'</dd></div></dl>'
     +'<div><div class="eyebrow" style="color:var(--ink3);margin-bottom:6px">Its part in each step</div><ul class="flowlist">'+fl.map(flowLine).join('')
     +(grp(n)==='hand'?'<li><i class="sw dash"></i><span><b>5 Verification</b> · not asked<br>ITOps reports what was answered: “Account removed (recorded by hand)”.</span></li>':'')
     +(n.role==='infra'?'<li><i class="sw dash"></i><span><b>Not in the steps</b><br>It holds no personal accounts, so onboarding and offboarding do not touch it.</span></li>':'')+'</ul></div>';
  } else {
    h='<div class="eyebrow">The whole picture</div><h3>Four kinds of system</h3><p>The platform in the middle is ITOps. The six tiles on it are the steps every system goes through, left to right; the dark base under them is the Audit Log. Around it stands each system, and the ring under it shows its kind. In front, the HR or IT user who sets the systems up and answers what is done by hand.</p><dl>'
     +'<div><dt><i class="ring" style="border-color:var(--live)"></i>ITOps acts by itself</dt><dd>'+esc(GRP.acts[1])+'. ITOps creates, blocks and removes, and reads the result live.</dd></div>'
     +'<div><dt><i class="ring" style="border-color:var(--in)"></i>Connected, read only</dt><dd>'+esc(GRP.reads[1])+'. A person makes the change there; ITOps reads it to know it is done. Nobody’s word is taken for it.</dd></div>'
     +'<div><dt><i class="ring" style="border-color:var(--plan)"></i>Tracked by hand</dt><dd>'+esc(GRP.hand[1])+', and any system you add. A person does the work there and answers in ITOps. A connection is planned for each.</dd></div>'
     +'<div><dt><i class="ring" style="border-color:var(--soon)"></i>No personal accounts</dt><dd>'+esc(GRP.other[1])+'. They tell ITOps who joins and leaves, follow Microsoft 365, or run the servers; nobody has an account there to open or close.</dd></div></dl>'
     +'<p style="font-size:13px;color:var(--ink3)">Choose a step above, or click a system on the picture.</p>'
     +'<div class="navrow"><button type="button" id="nav-next">Start at step 1 →</button></div>';
  }
  panel.innerHTML=h;
  var p=document.getElementById('nav-prev'), nx=document.getElementById('nav-next');
  if(p)p.addEventListener('click',function(){ setStep(String(Math.max(1,(+state.step||1)-1))); });
  if(nx)nx.addEventListener('click',function(){ setStep(String(Math.min(6,(+state.step||0)+1))); });
}
function pressChips(){ Array.prototype.forEach.call(document.querySelectorAll('.chip'),function(c){ c.setAttribute('aria-pressed',String(c.getAttribute('data-step')===state.step&&!state.sel)); }); }
function setStep(k){ state.step=k; state.sel=null; pressChips(); renderPanel(); if(window.__map3d)window.__map3d.sync(); }
function setSel(id){ state.sel=(state.sel===id?null:id); state.step=''; pressChips(); renderPanel(); if(window.__map3d)window.__map3d.sync(); }
Array.prototype.forEach.call(document.querySelectorAll('.chip'),function(c){ c.addEventListener('click',function(){ setStep(c.getAttribute('data-step')); }); });
renderPanel();

/* ───────── 3D ───────── */
function start3d(){
  if(!window.THREE) throw new Error('no three');
  var T=THREE, stage=document.getElementById('stage'), canvas=document.getElementById('gl'), tags=document.getElementById('tags');
  var renderer=new T.WebGLRenderer({canvas:canvas,antialias:true,alpha:true});
  renderer.setPixelRatio(Math.min(window.devicePixelRatio||1,2));
  renderer.shadowMap.enabled=true; renderer.shadowMap.type=T.PCFSoftShadowMap;
  var scene=new T.Scene();
  var cam=new T.OrthographicCamera(-1,1,1,-1,-100,200);
  var css=function(v){ return getComputedStyle(document.documentElement).getPropertyValue(v).trim()||'#888888'; };
  var themed=[];   /* [material, token getter] — recoloured when the theme changes */
  function mat(token,opts){ var m=new T.MeshStandardMaterial(Object.assign({color:css(token),roughness:.82,metalness:.02},opts||{})); m.userData.token=token; themed.push(m); return m; }
  function lineMat(token,opts){ var m=new T.LineBasicMaterial(Object.assign({color:css(token)},opts||{})); m.userData.token=token; themed.push(m); return m; }
  function rrect(w,d,r){ var s=new T.Shape(), x=-w/2, y=-d/2; r=Math.min(r,w/2,d/2);
    s.moveTo(x+r,y); s.lineTo(x+w-r,y); s.quadraticCurveTo(x+w,y,x+w,y+r); s.lineTo(x+w,y+d-r); s.quadraticCurveTo(x+w,y+d,x+w-r,y+d);
    s.lineTo(x+r,y+d); s.quadraticCurveTo(x,y+d,x,y+d-r); s.lineTo(x,y+r); s.quadraticCurveTo(x,y,x+r,y); return s; }
  /* lying, with rounded corners: w across, d deep, h high, standing on y=0 */
  function slab(w,d,h,r){ var g=new T.ExtrudeGeometry(rrect(w,d,r),{depth:h,bevelEnabled:false,curveSegments:6}); g.rotateX(-Math.PI/2); return g; }
  /* standing and facing the front: w across, h high, t thick, centred */
  function card(w,h,t,r){ var g=new T.ExtrudeGeometry(rrect(w,h,r),{depth:t,bevelEnabled:false,curveSegments:6}); g.translate(0,0,-t/2); return g; }
  function block(w,d,h,r,material,x,y,z,edgeTok){ var g=slab(w,d,h,r); var m=new T.Mesh(g,material); m.position.set(x,y,z); m.castShadow=true; m.receiveShadow=true; scene.add(m);
    if(edgeTok){ var e=new T.LineSegments(new T.EdgesGeometry(g,30),lineMat(edgeTok,{transparent:true,opacity:.55})); e.position.copy(m.position); scene.add(e); m.userData.edge=e; }
    return m; }

  scene.add(new T.AmbientLight(0xffffff,.7));
  var sun=new T.DirectionalLight(0xffffff,.5); sun.position.set(-9,18,7); sun.castShadow=true;
  sun.shadow.mapSize.set(2048,2048); var sc=sun.shadow.camera; sc.left=-22; sc.right=22; sc.top=22; sc.bottom=-22; sc.near=1; sc.far=60; sun.shadow.bias=-.0006; scene.add(sun);
  var fill=new T.DirectionalLight(0xffffff,.16); fill.position.set(10,6,14); scene.add(fill);

  /* ground */
  var GZ0=ZC-RZ-3.4, GZ1=PERSON.z+2.6;
  block(RX*2+6,GZ1-GZ0,.12,3.4,mat('--ground'),0,-.14,(GZ0+GZ1)/2,null).castShadow=false;
  /* ITOps: the Audit Log base, the working surface, six step tiles in a row */
  var base=block(22.4,5.2,.5,.7,mat('--hubBase'),0,0,0,null);
  block(21.8,4.6,.32,.6,mat('--hub'),0,.5,0,'--accent');
  var pickables=[], tileMesh={};
  STEPS.forEach(function(s){ var m=block(3.05,3.4,.42,.3,mat('--tile'),s.x,.82,0,'--accent'); m.userData.step=String(s.n); pickables.push(m); tileMesh[s.id]=m; });
  /* the direction of the process: small arrows between the tiles */
  var arrowMat=mat('--accent');
  for(var i=0;i<5;i++){ var c=new T.Mesh(new T.ConeGeometry(.17,.42,12),arrowMat); c.position.set(STEPS[i].x+1.75,1.0,1.95); c.rotation.z=-Math.PI/2; scene.add(c); }

  /* ── the systems: each one a small object of its own, standing on a round stand; the ring of the stand shows its kind.
     The objects are plain pictures of what the system is for (a cloud with mail and a document, a card index, a
     headset…), drawn here from simple solids. They are not the vendors' logos. ── */
  /* BT51: the objects are shared with the Integration map (visuals/objects.php) */
  var OB=window.ITOPS_OBJECTS(T), Kit=OB.Kit, FACE=OB.FACE, BIG=OB.BIG, INK=OB.INK, PAPER=OB.PAPER, RULE=OB.RULE, STEEL=OB.STEEL;
  NODES.forEach(function(n){ n.h=OB.height(n.id); });
  var nodeMesh={}, FADE=new T.Color(css('--surface'));
  NODES.forEach(function(n){ var p=OB.place(n,css(KIND[n.kind][1]),css('--stand'),KIND[n.kind][1]); scene.add(p.group);
    p.group.traverse(function(m){ if(m.isMesh) pickables.push(m); }); nodeMesh[n.id]={mats:p.mats}; });

  /* ── the HR or IT user: two people, one with a clipboard and one with a laptop, because either of them may do it ── */
  function figure(k,o){
    k.box(.3,.1,.44,INK,-.2,.05,.07); k.box(.3,.1,.44,INK,.2,.05,.07);
    k.cyl(.17,.15,.92,o.legs,-.2,.55,0,14); k.cyl(.17,.15,.92,o.legs,.2,.55,0,14);
    k.cyl(.44,.38,1.0,o.shirt,0,1.48,0); k.ball(.44,o.shirt,0,1.98,0).scale.y=.45;
    [-1,1].forEach(function(s){ k.cyl(.12,.12,.52,o.shirt,s*.55,1.7,0,12); k.cyl(.11,.1,.5,o.shirt,s*.5,1.42,.22,12).rotation.x=Math.PI/2; k.ball(.12,o.skin,s*.46,1.42,.5); });
    k.cyl(.13,.13,.2,o.skin,0,2.14,0,12); k.ball(.36,o.skin,0,2.52,0);
    k.add(new T.SphereGeometry(.39,24,14,0,Math.PI*2,0,Math.PI*.56),o.hair,0,2.54,-.03).rotation.x=-.38;
    if(o.long) k.cyl(.36,.3,.56,o.hair,0,2.3,-.16,20);
    k.box(.17,.22,.03,PAPER,.2,1.78,.42); k.box(.17,.07,.035,o.badge,.2,1.85,.42);
  }
  var crew=new T.Group(); crew.position.set(PERSON.x,0,PERSON.z); scene.add(crew); var crewKit=new Kit(crew,[]);
  crewKit.pad(5.6,3.0,.1,1.1,css('--mat'),0,0,.1).material.userData.token='--mat';
  var hr=crewKit.sub(-1.25,.1,0); hr.g.rotation.y=FACE+.22; hr.g.scale.setScalar(.94*BIG);
  figure(hr,{shirt:'#E8704A',legs:'#3B4A63',skin:'#E3B48C',hair:'#5A3825',long:true,badge:'#2F6FAE'});
  var cb=hr.sub(0,1.56,.58); cb.g.rotation.x=-.75; cb.card(.62,.8,.05,.06,'#2B3A67',0,0,0); cb.card(.5,.64,.02,.03,PAPER,0,-.03,.035);
  cb.box(.1,.1,.02,'#22C55E',-.14,.14,.05); cb.box(.22,.05,.02,RULE,.07,.14,.05); cb.box(.1,.1,.02,'#22C55E',-.14,-.04,.05); cb.box(.22,.05,.02,RULE,.07,-.04,.05); cb.box(.1,.1,.02,'#F59E0B',-.14,-.22,.05); cb.box(.22,.05,.02,RULE,.07,-.22,.05);
  var it=crewKit.sub(1.25,.1,0); it.g.rotation.y=FACE-.22; it.g.scale.setScalar(BIG);
  figure(it,{shirt:'#2F6FAE',legs:'#2B3550',skin:'#A9744A',hair:'#1F1A17',long:false,badge:'#1F9D6B'});
  it.box(.84,.05,.52,STEEL,0,1.4,.66); var lid=it.sub(0,1.42,.92); lid.g.rotation.x=.3; lid.box(.84,.56,.04,'#94A3B8',0,.28,0); lid.box(.74,.46,.02,'#BFE3FF',0,.28,-.025); lid.cyl(.06,.06,.02,PAPER,0,.28,.025,16).rotation.x=Math.PI/2;

  var stripe=(function(){ var c=document.createElement('canvas'); c.width=64; c.height=4; var x=c.getContext('2d'); x.fillStyle='#000'; x.fillRect(0,0,64,4); x.fillStyle='#fff'; x.fillRect(0,0,38,4);
    var t=new T.CanvasTexture(c); t.wrapS=T.RepeatWrapping; t.repeat.set(16,1); return t; })();

  function where(id){ if(stepById[id]) return {x:stepById[id].x,z:0,y:1.26,tile:true}; if(id==='hub') return {x:0,z:0,y:1.0,hub:true}; if(id==='it') return {x:PERSON.x,z:PERSON.z,y:2.2,person:true}; var n=byId[id]; return {x:n.x,z:n.z,y:.36,node:true}; }
  function anchor(id,otherId){ var p=where(id), o=where(otherId); var v=new T.Vector3(p.x,p.y,p.z);
    if(p.hub){ /* land on the edge of the platform that faces the other end */ v.x=Math.max(-9.5,Math.min(9.5,o.x*.6)); v.z=o.z<0?-1.9:1.9; v.y=.95; }
    else if(p.tile){ v.z=o.z<0?-1.1:1.1; }
    else if(p.node){ var d=new T.Vector3(o.x-p.x,0,o.z-p.z).normalize(); v.x+=d.x*2.25; v.z+=d.z*2.25; }
    else if(p.person){ v.z-=1.5; }
    return v; }
  var flows=[];
  FLOWS.forEach(function(f,i){
    var pa=anchor(f.a,f.b), pb=anchor(f.b,f.a);
    var mid=pa.clone().add(pb).multiplyScalar(.5); var dist=pa.distanceTo(pb); mid.y=Math.max(pa.y,pb.y)+1.0+dist*.2+(i%3)*.3;
    var curve=new T.QuadraticBezierCurve3(pa,mid,pb);
    var dashed=!!DASH[f.dir];
    var m=mat(dirColor[f.dir],{transparent:true,opacity:1,roughness:.5,alphaMap:dashed?stripe:null,alphaTest:dashed?.5:0});
    var grp=new T.Group(); scene.add(grp);
    grp.add(new T.Mesh(new T.TubeGeometry(curve,56,dashed?.055:.075,8,false),m));
    function head(t,flip){ var p=curve.getPoint(t), tg=curve.getTangent(t).normalize(); if(flip)tg.negate();
      var c=new T.Mesh(new T.ConeGeometry(.2,.5,14),m); c.position.copy(p); c.quaternion.setFromUnitVectors(new T.Vector3(0,1,0),tg); grp.add(c); }
    if(f.dir!=='manual') head(.97,false); if(f.dir==='both') head(.03,true);
    var pulses=[];
    function pulse(rev,off){ var s=new T.Mesh(new T.SphereGeometry(.15,14,10),mat(f.dir==='both'?(rev?'--out':'--in'):dirColor[f.dir],{roughness:.3})); grp.add(s); pulses.push({mesh:s,rev:rev,off:off}); }
    if(f.dir!=='manual'){ pulse(false,(i*.37)%1); if(f.dir==='both') pulse(true,(i*.37+.5)%1); }
    flows.push({f:f,curve:curve,grp:grp,pulses:pulses,on:false});
  });

  /* labels: plain HTML placed over the canvas */
  var labels=[];
  function addLabel(el,pos){ tags.appendChild(el); labels.push({el:el,pos:pos}); return el; }
  var hubTag=document.createElement('button'); hubTag.className='tag hubtag'; hubTag.id='tag-itops'; hubTag.type='button'; hubTag.textContent='ITOps';
  hubTag.addEventListener('click',function(){ setStep(''); });
  addLabel(hubTag,new T.Vector3(-10.4,1.5,-2.2));
  var modEl={};
  STEPS.forEach(function(s){ var d=document.createElement('div'); d.className='mod'; d.innerHTML='<b>'+s.n+'</b><span>'+esc(s.short)+'</span>'; modEl[s.id]=d; addLabel(d,new T.Vector3(s.x,1.3,0)); });
  var tagEl={};
  NODES.forEach(function(n){ var b=document.createElement('button'); b.type='button'; b.className='tag'; b.id='tag-'+n.id;
    b.innerHTML='<i class="dot" style="background:var('+KIND[n.kind][1]+')"></i>'+esc(n.name);
    b.addEventListener('click',function(){ setSel(n.id); }); tagEl[n.id]=b; addLabel(b,new T.Vector3(n.x,n.h+.5,n.z)); });
  var d1=document.createElement('div'); d1.className='note3d who'; d1.textContent='HR or IT user'; d1.id='note-person'; addLabel(d1,new T.Vector3(PERSON.x,4.5,PERSON.z));
  var d2=document.createElement('div'); d2.className='note3d'; d2.id='note-audit'; d2.textContent='base: Audit Log'; addLabel(d2,new T.Vector3(7.2,.25,3.2));

  /* camera */
  var view={theta:.5,phi:.7};
  var target=new T.Vector3(0,1.4,(GZ0+GZ1)/2-.6);
  function placeCam(){ var r=60; cam.position.set(target.x+r*Math.sin(view.theta)*Math.cos(view.phi),target.y+r*Math.sin(view.phi),target.z+r*Math.cos(view.theta)*Math.cos(view.phi)); cam.lookAt(target); }

  /* BT51: the camera frames everything on the stage, however many systems the list has: the centre of what is drawn,
     and the size that fits it at the first angle (turning keeps that size). */
  var FIT=[]; function fitBox(x,z,dx,dz,y0,y1){ [-1,1].forEach(function(sx){ [-1,1].forEach(function(sz){ FIT.push(new T.Vector3(x+sx*dx,y0,z+sz*dz),new T.Vector3(x+sx*dx,y1,z+sz*dz)); }); }); }
  NODES.forEach(function(n){ fitBox(n.x,n.z,2.2,2.2,0,n.h+1.0); }); fitBox(0,0,11.2,2.6,0,1.4); fitBox(PERSON.x,PERSON.z,2.8,1.5,0,4.9);
  (function(){ var lo=new T.Vector3(1e9,1e9,1e9), hi=new T.Vector3(-1e9,-1e9,-1e9); FIT.forEach(function(p){ lo.min(p); hi.max(p); }); target.set((lo.x+hi.x)/2,(lo.y+hi.y)/2,(lo.z+hi.z)/2); })();
  var VIEW0={theta:view.theta,phi:view.phi};
  function fitHalf(a){ var keep={theta:view.theta,phi:view.phi}; view.theta=VIEW0.theta; view.phi=VIEW0.phi; placeCam(); cam.updateMatrixWorld(true);
    var inv=cam.matrixWorldInverse, t=target.clone().applyMatrix4(inv), x0=1e9, x1=-1e9, y0=1e9, y1=-1e9;
    FIT.forEach(function(p){ var q=p.clone().applyMatrix4(inv); x0=Math.min(x0,q.x-t.x); x1=Math.max(x1,q.x-t.x); y0=Math.min(y0,q.y-t.y); y1=Math.max(y1,q.y-t.y); });
    if(!fitHalf.moved){ fitHalf.moved=true;   /* once: move the point looked at to the middle of what is drawn, as seen from the first angle */
      var e=cam.matrixWorld.elements; target.add(new T.Vector3(e[0],e[1],e[2]).multiplyScalar((x0+x1)/2)).add(new T.Vector3(e[4],e[5],e[6]).multiplyScalar((y0+y1)/2)); }
    view.theta=keep.theta; view.phi=keep.phi; return Math.max((y1-y0)/2,(x1-x0)/2/a)*1.05+.4; }
  function resize(){ var w=stage.clientWidth,h=stage.clientHeight; if(!w||!h)return; renderer.setSize(w,h,false); var a=w/h; var half=fitHalf(a);
    cam.left=-half*a; cam.right=half*a; cam.top=half; cam.bottom=-half; cam.updateProjectionMatrix(); labels.forEach(function(L){ L.hw=0; }); dirty=true; }

  /* what is shown: the whole picture, one step, or one system across the steps */
  var dirty=true;
  function sync(){
    var lit={};
    flows.forEach(function(o){ var f=o.f; var on=state.sel?(f.step&&(f.a===state.sel||f.b===state.sel)):(f.step===state.step);
      o.on=!!on; o.grp.visible=o.on; o.pulses.forEach(function(p){ p.mesh.visible=o.on&&!reduce; }); if(o.on){ lit[f.a]=1; lit[f.b]=1; } });
    var focus=!!(state.sel||state.step);
    NODES.forEach(function(n){ var on=!focus||lit[n.id]||state.sel===n.id; tagEl[n.id].classList.toggle('dim',!on); tagEl[n.id].classList.toggle('on',state.sel===n.id);
      nodeMesh[n.id].mats.forEach(function(m){ m.color.copy(m.userData.base); if(!on) m.color.lerp(FADE,.8); }); });
    STEPS.forEach(function(s){ var on=state.step===String(s.n)||(state.sel&&lit[s.id]); var m=tileMesh[s.id].material; m.userData.token=on?'--tileOn':'--tile'; m.color.set(css(m.userData.token)); });
    base.material.userData.token=state.step==='6'?'--tileOn':'--hubBase'; base.material.color.set(css(base.material.userData.token));
    dirty=true;
  }
  /* BT51: light or dark — the Help Center sets data-theme; every token colour is read again */
  function recolor(){ themed.forEach(function(m){ if(m.userData.token)m.color.set(css(m.userData.token)); }); FADE.set(css('--surface'));
    NODES.forEach(function(n){ nodeMesh[n.id].mats.forEach(function(m){ if(m.userData.token)m.userData.base=new T.Color(css(m.userData.token)); }); });
    crew.traverse(function(o){ if(o.isMesh&&o.material.userData.token)o.material.color.set(css(o.material.userData.token)); });
    sync(); }
  var mq=window.matchMedia('(prefers-color-scheme: dark)'); if(mq.addEventListener)mq.addEventListener('change',recolor);
  new MutationObserver(recolor).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
  var rm=window.matchMedia('(prefers-reduced-motion: reduce)'); var reduce=rm.matches; if(rm.addEventListener)rm.addEventListener('change',function(e){ reduce=e.matches; sync(); });

  /* drag to turn, click to pick */
  var drag=null, ray=new T.Raycaster(), v2=new T.Vector2();
  canvas.addEventListener('pointerdown',function(e){ drag={x:e.clientX,y:e.clientY,th:view.theta,ph:view.phi,moved:false}; try{ canvas.setPointerCapture(e.pointerId); }catch(_){} });
  canvas.addEventListener('pointermove',function(e){ if(!drag)return; var dx=e.clientX-drag.x, dy=e.clientY-drag.y; if(Math.abs(dx)+Math.abs(dy)>4)drag.moved=true;
    if(drag.moved){ view.theta=drag.th-dx*.006; view.phi=Math.max(.3,Math.min(1.25,drag.ph+dy*.005)); dirty=true; } });
  function up(e){ if(!drag)return; var d=drag; drag=null; if(d.moved)return; var r=canvas.getBoundingClientRect(); v2.set(((e.clientX-r.left)/r.width)*2-1,-((e.clientY-r.top)/r.height)*2+1);
    ray.setFromCamera(v2,cam); var hit=ray.intersectObjects(pickables,false)[0]; if(!hit)return; if(hit.object.userData.id)setSel(hit.object.userData.id); else if(hit.object.userData.step)setStep(hit.object.userData.step); }
  canvas.addEventListener('pointerup',up); canvas.addEventListener('pointercancel',function(){ drag=null; });
  canvas.addEventListener('keydown',function(e){ if(e.key==='ArrowLeft'){ view.theta-=.12; dirty=true; e.preventDefault(); } if(e.key==='ArrowRight'){ view.theta+=.12; dirty=true; e.preventDefault(); } });

  var v3=new T.Vector3();
  function frame(ts){
    requestAnimationFrame(frame);
    if(!reduce){ var t=ts/1000; flows.forEach(function(o){ if(!o.on)return; o.pulses.forEach(function(p){ var u=((t*.16)+p.off)%1; if(p.rev)u=1-u; p.mesh.position.copy(o.curve.getPoint(u)); }); }); dirty=true; }
    if(!dirty)return; dirty=false;
    placeCam(); renderer.render(scene,cam);
    var w=stage.clientWidth,h=stage.clientHeight;
    labels.forEach(function(L){ v3.copy(L.pos).project(cam); if(!L.hw)L.hw=(L.el.offsetWidth||0)/2; var x=(v3.x*.5+.5)*w; x=Math.max(L.hw+6,Math.min(w-L.hw-6,x));
      L.el.style.transform='translate(-50%,'+(L.el.classList.contains('tag')?'-100%':'-50%')+') translate('+x.toFixed(1)+'px,'+((-v3.y*.5+.5)*h).toFixed(1)+'px)'; });
  }
  window.__map3d={sync:sync};
  if(window.ResizeObserver)new ResizeObserver(resize).observe(stage); else window.addEventListener('resize',resize);
  resize(); sync(); requestAnimationFrame(frame);
}
try{ start3d(); }catch(e){ document.getElementById('gl').hidden=true; document.getElementById('tags').hidden=true; document.getElementById('fallback').hidden=false; }
})();
