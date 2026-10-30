/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* ITOps Help Center visual "integration-map" (BT90: served by api/help_script, so the page policy allows no inline scripts). */
(function(){
'use strict';
/* ───────── data: one list drives the 3D view, the panel and the tables ───────── */
var MODS={
  lifecycle:{name:'Onboard · Offboard',x:-2.8,z:-1.35},
  accounts:{name:'Accounts',x:0,z:-1.35},
  links:{name:'People links',x:2.8,z:-1.35},
  phones:{name:'Phones',x:-2.8,z:1.35},
  tickets:{name:'Tickets',x:0,z:1.35},
  guardian:{name:'Server Guardian',x:2.8,z:1.35}
};
/* BT51: the systems are the shared list (the same as on "Company systems: the whole process"). */
var SYS=(window.ITOPS_SYSTEMS||[]).slice();
var STATUS={acts:['ITOps acts by itself','--live'],reads:['connected · ITOps reads it','--in'],hand:['tracked by hand · connection planned','--plan'],
  planned:['planned · not in use yet','--soon'],indirect:['no connection · follows Microsoft 365','--manual']};
/* round the hub: Microsoft 365 at the back, CodeTwo beside it, Paylocity last (next to Microsoft 365 on the other side) */
var first=SYS.filter(function(n){ return n.id==='m365'; }), cod=SYS.filter(function(n){ return n.id==='codetwo'; }), pay=SYS.filter(function(n){ return n.id==='paylocity'; });
var NODES=first.concat(cod,SYS.filter(function(n){ return ['m365','codetwo','paylocity'].indexOf(n.id)<0; }),pay).filter(function(n){ return STATUS[n.status]; });
var RX=Math.max(11,NODES.length*1.05), RZ=RX*.84;
NODES.forEach(function(n,i){ var a=-Math.PI/2+i*2*Math.PI/NODES.length; n.x=RX*Math.cos(a); n.z=RZ*Math.sin(a); });
/* a → b is the direction the information or the change travels. A system without lines of its own gets the lines of its kind. */
var FLOWS=[], have={};
NODES.forEach(function(n){ have[n.id]=1; });
NODES.forEach(function(n){
  var fl=(n.flows&&n.flows.length)?n.flows:(
    n.role==='people'?[['lifecycle',n.id,'manual','A step in every onboarding and offboarding: a person does the work there and answers in ITOps'],[n.id,'links','manual','Accounts recorded by hand: a pasted list, or per person']]:
    n.role==='infra'?[[n.id,'guardian',n.status==='reads'?'in':'both','Server and hosting information']]:
    n.role==='follows'?[['m365',n.id,'ext','Reads the Microsoft 365 account by itself']]:[[n.id,'lifecycle','in','Tells ITOps who joins and who leaves']]);
  fl.forEach(function(f){ var a=f[0], b=f[1]; if((!MODS[a]&&!have[a])||(!MODS[b]&&!have[b]))return;
    var st=f[4]||((n.status==='planned'||n.status==='hand')?'planned':'live');
    FLOWS.push({id:a+'>'+b,a:a,b:b,dir:f[2],st:st,t:f[3]}); });
});
var handNames=NODES.filter(function(n){ return n.status==='hand'; }).map(function(n){ return n.name; });
var listOf=function(a){ return a.length<2?a.join(''):a.slice(0,-1).join(', ')+' and '+a[a.length-1]; };
/* steps: [lines it lights ('@hand' = the step of every system tracked by hand), text ({hand} = their names)] */
var SCEN={
 hire:{name:'A new hire',steps:[
  [['paylocity>lifecycle'],'HR enters the hire in Paylocity. Once Paylocity is connected, it sends the event to ITOps.'],
  [null,'ITOps creates a draft onboarding, filled with name, title, department, manager and start date. Someone reviews it and presses Start.'],
  [['lifecycle>m365'],'ITOps creates the Microsoft 365 account with its license and groups.'],
  [['lifecycle>vtiger'],'ITOps creates the CRM user in vTiger.'],
  [['lifecycle>justcall'],'A person adds the user in JustCall; ITOps checks that JustCall lists them.'],
  [['@hand'],'{hand}: each has its own step. A person sets the account up there and answers in ITOps.'],
  [['m365>codetwo'],'CodeTwo picks up the new account’s title and phone for the signature.'],
  [['paylocity>links'],'The nightly roster comparison confirms the person now has an account.']]},
 leave:{name:'A departure',steps:[
  [['paylocity>lifecycle'],'HR enters the termination in Paylocity. ITOps creates a draft offboarding dated to the last day.'],
  [['lifecycle>m365'],'When someone starts it: sign-in is blocked, sessions are revoked, licenses are released.'],
  [['lifecycle>vtiger'],'The CRM user is deactivated and its records are reassigned.'],
  [['lifecycle>justcall'],'A person removes the user in JustCall; the step is done only when JustCall no longer lists them.'],
  [['@hand'],'{hand}: a person removes or hands over the account there and answers in ITOps.'],
  [['justcall>links','vtiger>links'],'Verify offboarding asks each connected system again, and reports the answers given for the others.']]},
 call:{name:'A missed call',steps:[
  [['justcall>tickets'],'Later: JustCall tells ITOps about a missed call or a voicemail on the help line.'],
  [null,'ITOps checks the signature, then fetches the call from JustCall by its id before trusting it.'],
  [null,'A ticket is created with the caller, the number called, the time and the voicemail text. Repeat calls from the same caller join the same ticket.'],
  [null,'Normal ticket routing applies: owner, backup and alerts.']]},
 sign:{name:'A signature change',steps:[
  [null,'Someone changes a title, a phone number or a custom field on Accounts in ITOps.'],
  [['m365>accounts'],'ITOps writes it to the Microsoft 365 account and records the old and new value in the Audit Log.'],
  [['m365>codetwo'],'CodeTwo reads the account again within about 20 minutes.'],
  [null,'The next email carries the new signature. Nobody opened the Microsoft or CodeTwo portal.']]},
 host:{name:'Hosting',steps:[
  [['whm>guardian'],'Server Guardian checks the web server and its cPanel accounts through WHM. This runs today.'],
  [['hosting>guardian'],'Later: the hosting provider adds the servers, the vendor tickets and the invoices.'],
  [null,'A hosting provider ticket can be linked to an ITOps ticket, so the vendor conversation sits on the incident.'],
  [null,'Invoices give a reminder before the due date and a yearly hosting cost for the vendor record.']]}
};
var stepRefs=function(r){ if(!r)return []; var o=[]; r.forEach(function(x){ if(x==='@hand') NODES.forEach(function(n){ if(n.status==='hand')o.push('lifecycle>'+n.id); }); else o.push(x); }); return o; };
var stepText=function(t){ return t.replace('{hand}',handNames.length?listOf(handNames):'The systems tracked by hand'); };
var byId={}; NODES.forEach(function(n){ byId[n.id]=n; });
var esc=function(s){ return String(s).replace(/[&<>"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
var nameOf=function(id){ return byId[id]?byId[id].name:(MODS[id]?MODS[id].name:id); };
var dirColor={in:'--in',out:'--out',both:'--accent',manual:'--manual',ext:'--manual'};
var dirWord={in:'into ITOps',out:'out of ITOps',both:'both ways',manual:'by hand',ext:'outside ITOps'};

/* ───────── tables and scenario lists (always on the page) ───────── */
(function(){
  var h='<thead><tr><th>Service</th><th>Status</th><th>How ITOps connects</th><th>ITOps reads</th><th>ITOps changes</th><th>Limits</th></tr></thead><tbody>';
  NODES.forEach(function(n){ var s=STATUS[n.status];
    h+='<tr data-sys="'+esc(n.id)+'"><td>'+esc(n.name)+'</td><td><span class="status"><i class="dot" style="background:var('+s[1]+')"></i>'+esc(s[0])+'</span>'+(n.in_use===false?'<div style="font-size:12px;color:var(--ink3);margin-top:4px">not switched on here</div>':'')+'</td><td>'+esc(n.connect)+'</td><td>'+esc(n.reads)+'</td><td>'+esc(n.writes)+'</td><td>'+esc(n.limits)+'</td></tr>'; });
  document.getElementById('matrix').innerHTML=h+'</tbody>';
  var s='';
  Object.keys(SCEN).forEach(function(k){ s+='<div><h3>'+esc(SCEN[k].name)+'</h3><ol>'+SCEN[k].steps.map(function(x){ return '<li>'+esc(stepText(x[1]))+'</li>'; }).join('')+'</ol></div>'; });
  document.getElementById('scen').innerHTML=s;
})();

/* ───────── panel ───────── */
var state={sel:null,sc:''};
var panel=document.getElementById('panel');
function flowLine(f){ return '<li><i class="sw'+(f.st==='planned'||f.dir==='manual'?' dash':'')+'"'+(f.st==='planned'||f.dir==='manual'?'':' style="background:var('+dirColor[f.dir]+')"')+'></i><span><b>'+esc(nameOf(f.a))+' → '+esc(nameOf(f.b))+'</b> · '+esc(dirWord[f.dir])+'<br>'+esc(f.t)+'</span></li>'; }
function renderPanel(){
  var h='';
  if(state.sc){ var sc=SCEN[state.sc];
    h='<div class="eyebrow">Follow</div><h3>'+esc(sc.name)+'</h3><ol>'+sc.steps.map(function(x){ return '<li>'+esc(stepText(x[1]))+'</li>'; }).join('')+'</ol>';
  } else if(state.sel&&byId[state.sel]){ var n=byId[state.sel], s=STATUS[n.status];
    var fl=FLOWS.filter(function(f){ return f.a===n.id||f.b===n.id; });
    h='<div><span class="status"><i class="dot" style="background:var('+s[1]+')"></i>'+esc(s[0])+'</span></div><h3>'+esc(n.name)+'</h3><p style="color:var(--ink2);font-size:14px">'+esc(n.what)+'</p>'
     +(n.in_use===false?'<p style="font-size:13px;color:var(--ink3)">Not switched on under Settings → Company systems.</p>':'')
     +'<div class="how">'+esc(n.connect)+'</div><dl>'
     +'<div><dt>ITOps reads</dt><dd>'+esc(n.reads)+'</dd></div><div><dt>ITOps changes</dt><dd>'+esc(n.writes)+'</dd></div><div><dt>Limits</dt><dd>'+esc(n.limits)+'</dd></div>'
     +(n.mind?'<div><dt>Keep in mind</dt><dd>'+esc(n.mind)+'</dd></div>':'')+'</dl>'
     +'<div><dt style="font-family:var(--f-mono);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:6px">Its lines on the map</dt><ul class="flowlist">'+fl.map(flowLine).join('')+'</ul></div>';
  } else {
    h='<div class="eyebrow">The hub</div><h3>ITOps</h3><p style="color:var(--ink2);font-size:14px">Six parts of ITOps take part. The dark base under them is the Audit Log: every change that leaves ITOps is recorded there with the old and the new value.</p>'
     +'<dl><div><dt>Onboard · Offboard</dt><dd>Starts and ends accounts in every system, one checklist per person.</dd></div>'
     +'<div><dt>Accounts</dt><dd>One Microsoft 365 account: contact information, licenses, groups, sign-in.</dd></div>'
     +'<div><dt>People links</dt><dd>Says which account in every system belongs to which person: the CRM user, the JustCall user, the extension, and each account recorded by hand.</dd></div>'
     +'<div><dt>Phones</dt><dd>Extensions and JustCall seats, compared with the staff list.</dd></div>'
     +'<div><dt>Tickets</dt><dd>Where a missed call or a vendor conversation lands.</dd></div>'
     +'<div><dt>Server Guardian</dt><dd>Server health, and later the hosting inventory.</dd></div></dl>'
     +'<p style="font-size:13px;color:var(--ink3)">Click a block on the map, or follow one thing with the buttons above.</p>';
  }
  panel.innerHTML=h;
}
function setScenario(k){ state.sc=k; state.sel=null;
  Array.prototype.forEach.call(document.querySelectorAll('.chip'),function(c){ c.setAttribute('aria-pressed',String(c.getAttribute('data-sc')===k)); });
  renderPanel(); if(window.__map3d)window.__map3d.sync(); }
function setSel(id){ state.sel=(state.sel===id?null:id); state.sc='';
  Array.prototype.forEach.call(document.querySelectorAll('.chip'),function(c){ c.setAttribute('aria-pressed',String(c.getAttribute('data-sc')==='')); });
  renderPanel(); if(window.__map3d)window.__map3d.sync(); }
Array.prototype.forEach.call(document.querySelectorAll('.chip'),function(c){ c.addEventListener('click',function(){ setScenario(c.getAttribute('data-sc')); }); });
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
  var themed=[];   /* [material, token] — recoloured when the theme changes */
  function mat(token,opts){ var m=new T.MeshStandardMaterial(Object.assign({color:css(token),roughness:.82,metalness:.02},opts||{})); themed.push([m,token]); return m; }
  function lineMat(token,opts){ var m=new T.LineBasicMaterial(Object.assign({color:css(token)},opts||{})); themed.push([m,token]); return m; }
  /* a box with rounded corners standing on the ground: w along x, d along z, h up */
  function slab(w,d,h,r){ var s=new T.Shape(), x=-w/2, y=-d/2; r=Math.min(r,w/2,d/2);
    s.moveTo(x+r,y); s.lineTo(x+w-r,y); s.quadraticCurveTo(x+w,y,x+w,y+r); s.lineTo(x+w,y+d-r); s.quadraticCurveTo(x+w,y+d,x+w-r,y+d);
    s.lineTo(x+r,y+d); s.quadraticCurveTo(x,y+d,x,y+d-r); s.lineTo(x,y+r); s.quadraticCurveTo(x,y,x+r,y);
    var g=new T.ExtrudeGeometry(s,{depth:h,bevelEnabled:false,curveSegments:6}); g.rotateX(-Math.PI/2); return g; }
  function block(w,d,h,r,material,x,y,z,edgeTok){ var g=slab(w,d,h,r); var m=new T.Mesh(g,material); m.position.set(x,y,z); m.castShadow=true; m.receiveShadow=true; scene.add(m);
    if(edgeTok){ var e=new T.LineSegments(new T.EdgesGeometry(g,30),lineMat(edgeTok,{transparent:true,opacity:.55})); e.position.copy(m.position); scene.add(e); m.userData.edge=e; }
    return m; }

  scene.add(new T.AmbientLight(0xffffff,.6));
  var sun=new T.DirectionalLight(0xffffff,.5); sun.position.set(-9,18,7); sun.castShadow=true;
  sun.shadow.mapSize.set(2048,2048); var sc=sun.shadow.camera; sc.left=-20; sc.right=20; sc.top=20; sc.bottom=-20; sc.near=1; sc.far=60; sun.shadow.bias=-.0006; scene.add(sun);

  /* ground */
  block(RX*2+6,RZ*2+6,.12,3,mat('--ground'),0,-.14,0,null).castShadow=false;
  /* the office: the phone system stands in it */
  var office=byId.pbx; if(office) block(5.6,5.6,.1,1.1,mat('--zone'),office.x,-.02,office.z,'--wall').castShadow=false;
  /* ITOps: Audit Log base, working surface, six parts */
  block(9.6,6.9,.5,.7,mat('--hubBase'),0,0,0,null);
  var hubTop=block(9,6.3,.32,.6,mat('--hub'),0,.5,0,'--accent');
  var modMesh={};
  Object.keys(MODS).forEach(function(k){ var m=MODS[k]; modMesh[k]=block(2.45,2.25,.42,.3,mat('--tile'),m.x,.82,m.z,'--accent'); });

  /* the systems: the same objects as on "Company systems: the whole process" (visuals/objects.php), on a round stand
     whose ring shows the kind */
  var OB=window.ITOPS_OBJECTS(T), nodeMesh={}, pickables=[], FADE=new T.Color(css('--surface'));
  NODES.forEach(function(n){ var p=OB.place(n,css(STATUS[n.status][1]),css('--stand'),STATUS[n.status][1]); n.h=p.h; scene.add(p.group);
    p.group.traverse(function(m){ if(m.isMesh) pickables.push(m); }); nodeMesh[n.id]={mats:p.mats}; });

  /* dashed look for planned lines: an alpha stripe along the tube */
  var stripe=(function(){ var c=document.createElement('canvas'); c.width=64; c.height=4; var x=c.getContext('2d'); x.fillStyle='#000'; x.fillRect(0,0,64,4); x.fillStyle='#fff'; x.fillRect(0,0,38,4);
    var t=new T.CanvasTexture(c); t.wrapS=T.RepeatWrapping; t.repeat.set(16,1); return t; })();

  function anchor(id,other){ if(MODS[id]) return new T.Vector3(MODS[id].x,1.26,MODS[id].z);
    var n=byId[id]; var v=new T.Vector3(n.x,.4,n.z); if(other){ var d=new T.Vector3(other.x-n.x,0,other.z-n.z).normalize(); v.x+=d.x*2.25; v.z+=d.z*2.25; } return v; }
  var flows=[];
  FLOWS.forEach(function(f,i){
    var pa=anchor(f.a,MODS[f.b]||byId[f.b]), pb=anchor(f.b,MODS[f.a]||byId[f.a]);
    var mid=pa.clone().add(pb).multiplyScalar(.5); var dist=pa.distanceTo(pb); mid.y=Math.max(pa.y,pb.y)+1.2+dist*.2+(i%3)*.35;
    var curve=new T.QuadraticBezierCurve3(pa,mid,pb);
    var dashed=(f.st==='planned'||f.dir==='manual');
    var m=mat(dirColor[f.dir],{transparent:true,opacity:1,roughness:.5,alphaMap:dashed?stripe:null,alphaTest:dashed?.5:0});
    var tube=new T.Mesh(new T.TubeGeometry(curve,56,dashed?.055:.075,8,false),m); scene.add(tube);
    var heads=[];
    function head(t,flip){ var p=curve.getPoint(t), tg=curve.getTangent(t).normalize(); if(flip)tg.negate();
      var c=new T.Mesh(new T.ConeGeometry(.2,.5,14),m); c.position.copy(p); c.quaternion.setFromUnitVectors(new T.Vector3(0,1,0),tg); scene.add(c); heads.push(c); }
    if(f.dir!=='manual') head(.97,false); if(f.dir==='both') head(.03,true);
    var pulses=[];
    function pulse(rev,off){ var s=new T.Mesh(new T.SphereGeometry(.15,14,10),mat(f.dir==='both'?(rev?'--out':'--in'):dirColor[f.dir],{roughness:.3,emissive:0x000000})); scene.add(s); pulses.push({mesh:s,rev:rev,off:off}); }
    if(f.dir!=='manual'){ pulse(false,(i*.37)%1); if(f.dir==='both') pulse(true,(i*.37+.5)%1); }
    flows.push({f:f,curve:curve,tube:tube,mat:m,heads:heads,pulses:pulses,mid:curve.getPoint(i%2?.38:.6)});
  });

  /* labels: plain HTML placed over the canvas */
  var labels=[];
  function addLabel(el,pos){ tags.appendChild(el); labels.push({el:el,pos:pos}); return el; }
  var hubTag=document.createElement('button'); hubTag.className='tag hubtag'; hubTag.id='tag-itops'; hubTag.type='button'; hubTag.textContent='ITOps';
  hubTag.addEventListener('click',function(){ state.sel=null; setScenario(''); });
  addLabel(hubTag,new T.Vector3(0,2.5,-3.6));
  Object.keys(MODS).forEach(function(k){ var d=document.createElement('div'); d.className='mod'; d.textContent=MODS[k].name; addLabel(d,new T.Vector3(MODS[k].x,1.3,MODS[k].z)); });
  var tagEl={};
  NODES.forEach(function(n){ var b=document.createElement('button'); b.type='button'; b.className='tag'; b.id='tag-'+n.id;
    b.innerHTML='<i class="dot" style="background:var('+STATUS[n.status][1]+')"></i>'+esc(n.name);
    b.addEventListener('click',function(){ setSel(n.id); }); tagEl[n.id]=b; addLabel(b,new T.Vector3(n.x,n.h+.5,n.z)); });
  if(office){ var d1=document.createElement('div'); d1.className='note3d'; d1.textContent='in the office'; addLabel(d1,new T.Vector3(office.x,.2,office.z+3.1)); }
  var d2=document.createElement('div'); d2.className='note3d'; d2.textContent='base: Audit Log'; addLabel(d2,new T.Vector3(0,.25,3.9));
  var stepEls=[];

  /* camera */
  var view={theta:.62,phi:.66,t0:.62};
  var target=new T.Vector3(0,1.1,0);
  function placeCam(){ var r=60; cam.position.set(target.x+r*Math.sin(view.theta)*Math.cos(view.phi),target.y+r*Math.sin(view.phi),target.z+r*Math.cos(view.theta)*Math.cos(view.phi)); cam.lookAt(target); }

  /* BT51: the camera frames everything on the stage, however many systems the list has: the centre of what is drawn,
     and the size that fits it at the first angle (turning keeps that size). */
  var FIT=[]; function fitBox(x,z,dx,dz,y0,y1){ [-1,1].forEach(function(sx){ [-1,1].forEach(function(sz){ FIT.push(new T.Vector3(x+sx*dx,y0,z+sz*dz),new T.Vector3(x+sx*dx,y1,z+sz*dz)); }); }); }
  NODES.forEach(function(n){ fitBox(n.x,n.z,2.2,2.2,0,n.h+.9); }); fitBox(0,0,4.8,3.5,0,1.4);
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

  /* what is lit and what is dimmed */
  var dirty=true;
  function sync(){
    var act=null, steps=[];
    if(state.sc){ act={}; SCEN[state.sc].steps.forEach(function(s,i){ var r=stepRefs(s[0]).filter(function(id){ return flows.some(function(o){ return o.f.id===id; }); }); r.forEach(function(id){ act[id]=1; }); if(r.length) steps.push([r[0],i+1]); }); }
    else if(state.sel){ act={}; FLOWS.forEach(function(f){ if(f.a===state.sel||f.b===state.sel)act[f.id]=1; }); }
    var lit={};
    flows.forEach(function(o){ var on=!act||act[o.f.id]; o.mat.opacity=on?1:.1; on=!!on; o.pulses.forEach(function(p){ p.mesh.visible=on&&!reduce; }); o.on=on;
      if(on&&act){ lit[o.f.a]=1; lit[o.f.b]=1; } });
    NODES.forEach(function(n){ var on=!act||lit[n.id]||state.sel===n.id; tagEl[n.id].classList.toggle('dim',!on); tagEl[n.id].classList.toggle('on',state.sel===n.id);
      nodeMesh[n.id].mats.forEach(function(m){ m.color.copy(m.userData.base); if(!on) m.color.lerp(FADE,.8); }); });
    stepEls.forEach(function(e){ e.el.remove(); var ix=labels.indexOf(e); if(ix>=0)labels.splice(ix,1); }); stepEls=[];
    steps.forEach(function(s){ var o=flows.filter(function(x){ return x.f.id===s[0]; })[0]; if(!o)return; var el=document.createElement('div'); el.className='stepn'; el.textContent=s[1]; tags.appendChild(el); var L={el:el,pos:o.mid}; labels.push(L); stepEls.push(L); });
    dirty=true;
  }
  function recolor(){ themed.forEach(function(x){ x[0].color.set(css(x[1])); }); FADE.set(css('--surface'));
    NODES.forEach(function(n){ nodeMesh[n.id].mats.forEach(function(m){ if(m.userData.token)m.userData.base=new T.Color(css(m.userData.token)); }); });
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
    ray.setFromCamera(v2,cam); var hit=ray.intersectObjects(pickables,false)[0]; if(hit&&hit.object.userData.id)setSel(hit.object.userData.id); }
  canvas.addEventListener('pointerup',up); canvas.addEventListener('pointercancel',function(){ drag=null; });
  canvas.addEventListener('keydown',function(e){ if(e.key==='ArrowLeft'){ view.theta-=.12; dirty=true; e.preventDefault(); } if(e.key==='ArrowRight'){ view.theta+=.12; dirty=true; e.preventDefault(); } });

  var v3=new T.Vector3();
  function frame(ts){
    requestAnimationFrame(frame);
    if(!reduce){ var t=ts/1000; flows.forEach(function(o){ if(!o.on)return; o.pulses.forEach(function(p){ var u=((t*.16)+p.off)%1; if(p.rev)u=1-u; p.mesh.position.copy(o.curve.getPoint(u)); }); }); dirty=true; }
    if(!dirty)return; dirty=false;
    placeCam(); renderer.render(scene,cam);
    var w=stage.clientWidth,h=stage.clientHeight;
    labels.forEach(function(L){ v3.copy(L.pos).project(cam); if(!L.hw)L.hw=(L.el.offsetWidth||0)/2; var x=(v3.x*.5+.5)*w; x=Math.max(L.hw+6,Math.min(w-L.hw-6,x));   /* keep every label inside the stage */
      L.el.style.transform='translate(-50%,'+(L.el.classList.contains('tag')?'-100%':'-50%')+') translate('+x.toFixed(1)+'px,'+((-v3.y*.5+.5)*h).toFixed(1)+'px)'; });
  }
  window.__map3d={sync:sync};
  if(window.ResizeObserver)new ResizeObserver(resize).observe(stage); else window.addEventListener('resize',resize);
  resize(); sync(); requestAnimationFrame(frame);
}
try{ start3d(); }catch(e){ document.getElementById('gl').hidden=true; document.getElementById('tags').hidden=true; document.getElementById('fallback').hidden=false; }
})();
