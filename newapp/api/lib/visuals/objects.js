/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* BT51: the 3D objects of the systems, shared by both Help Center visuals (the Integration map and Company systems:
   the whole process), so the two pages draw each system the same way. Plain pictures of what each system is for,
   drawn from simple solids; not the vendors' logos. A system without a picture of its own gets "generic". */
window.OPSH_OBJECTS=function(T){
  function rrect(w,d,r){ var s=new T.Shape(), x=-w/2, y=-d/2; r=Math.min(r,w/2,d/2);
    s.moveTo(x+r,y); s.lineTo(x+w-r,y); s.quadraticCurveTo(x+w,y,x+w,y+r); s.lineTo(x+w,y+d-r); s.quadraticCurveTo(x+w,y+d,x+w-r,y+d);
    s.lineTo(x+r,y+d); s.quadraticCurveTo(x,y+d,x,y+d-r); s.lineTo(x,y+r); s.quadraticCurveTo(x,y,x+r,y); return s; }
  /* lying, with rounded corners: w across, d deep, h high, standing on y=0 */
  function slab(w,d,h,r){ var g=new T.ExtrudeGeometry(rrect(w,d,r),{depth:h,bevelEnabled:false,curveSegments:6}); g.rotateX(-Math.PI/2); return g; }
  /* standing and facing the front: w across, h high, t thick, centred */
  function card(w,h,t,r){ var g=new T.ExtrudeGeometry(rrect(w,h,r),{depth:t,bevelEnabled:false,curveSegments:6}); g.translate(0,0,-t/2); return g; }
  var FACE=.5, BIG=1.3;   /* turned towards where the picture is first seen from */
  function Kit(group,mats){ this.g=group; this.mats=mats; }
  Kit.prototype.add=function(geo,col,x,y,z){ var m=new T.MeshStandardMaterial({color:col,roughness:.6,metalness:.03}); this.mats.push(m);
    var me=new T.Mesh(geo,m); me.position.set(x||0,y||0,z||0); me.castShadow=true; me.receiveShadow=true; this.g.add(me); return me; };
  Kit.prototype.box=function(w,h,d,col,x,y,z){ return this.add(new T.BoxGeometry(w,h,d),col,x,y,z); };
  Kit.prototype.cyl=function(rt,rb,h,col,x,y,z,seg){ return this.add(new T.CylinderGeometry(rt,rb,h,seg||28),col,x,y,z); };
  Kit.prototype.ball=function(r,col,x,y,z){ return this.add(new T.SphereGeometry(r,24,16),col,x,y,z); };
  Kit.prototype.pad=function(w,d,h,r,col,x,y,z){ return this.add(slab(w,d,h,r),col,x,y,z); };
  Kit.prototype.card=function(w,h,t,r,col,x,y,z){ return this.add(card(w,h,t,r),col,x,y,z); };
  Kit.prototype.sub=function(x,y,z){ var g=new T.Group(); g.position.set(x||0,y||0,z||0); this.g.add(g); return new Kit(g,this.mats); };
  var INK='#1D2B3A', PAPER='#FFFFFF', RULE='#C9D4DF', STEEL='#CBD5E1';
  /* BT51: how high each picture stands (for its name tag); a system without a picture of its own gets "generic" */
  var DRAW_H={m365:4.18,vtiger:3.27,justcall:3.53,hubspot:4.57,efax:3.14,jotform:3.53,pbx:1.97,paylocity:3.75,codetwo:3.1,whm:3.6,hosting:4.3,generic:3.4};
  var DRAW={
    /* Microsoft 365: accounts and mail in the cloud — a cloud, an envelope, a document */
    m365:function(k){
      var c=k.sub(.05,2.05,-.25); c.g.scale.set(1,.86,.9);
      c.ball(.6,'#4A9BE8',-.66,-.06,0); c.ball(.82,'#4A9BE8',0,.24,0); c.ball(.58,'#4A9BE8',.72,-.08,0); c.ball(.5,'#6FB3F2',.12,-.14,.22);
      c.cyl(.42,.42,1.36,'#4A9BE8',.03,-.3,0).rotation.z=Math.PI/2;
      var e=k.sub(-.52,.6,.62); e.g.rotation.set(-.14,.22,0);
      e.card(1.25,.84,.1,.08,'#F5C542',0,0,0);
      var tri=new T.Shape(); tri.moveTo(-.58,.38); tri.lineTo(.58,.38); tri.lineTo(0,-.1); tri.lineTo(-.58,.38);
      e.add(new T.ExtrudeGeometry(tri,{depth:.03,bevelEnabled:false}),'#D99A1E',0,0,.05);
      var d=k.sub(.66,.78,.3); d.g.rotation.set(-.12,-.3,0);
      d.card(.95,1.25,.07,.07,PAPER,0,0,0);
      d.box(.6,.08,.02,'#2F6FAE',-.05,.36,.045); d.box(.6,.08,.02,'#1F9D6B',-.05,.13,.045); d.box(.42,.08,.02,'#E8704A',-.14,-.1,.045); d.box(.6,.08,.02,RULE,-.05,-.33,.045);
    },
    /* vTiger: the CRM — a card index with a contact card in front */
    vtiger:function(k){
      k.pad(2.05,1.3,.36,.16,'#2E7D5B',0,0,0);
      var cols=['#F29F4B','#FFD08A',PAPER];
      for(var i=0;i<3;i++){ var h=1.3+(2-i)*.3, c=k.sub(0,.24+h/2,-.34+i*.3); c.g.rotation.x=-.1;
        c.card(1.62,h,.06,.1,cols[i],0,0,0); c.card(.5,.24,.06,.07,cols[i],-.46+i*.46,h/2+.06,0);
        if(i===2){ c.cyl(.19,.19,.03,'#F29F4B',-.44,.22,.045,24).rotation.x=Math.PI/2; c.card(.56,.3,.03,.14,'#F29F4B',-.44,-.2,.045);
          c.box(.62,.09,.02,'#2E7D5B',.32,.3,.04); c.box(.62,.09,.02,RULE,.32,.08,.04); c.box(.4,.09,.02,RULE,.21,-.14,.04); } }
    },
    /* JustCall: the cloud phone — a headset on its stand */
    justcall:function(k){
      k.cyl(.55,.62,.1,STEEL,0,.05,0); k.cyl(.07,.07,2.0,'#94A3B8',0,1.05,0,12);
      var h=k.sub(0,1.3,0);
      h.add(new T.TorusGeometry(.86,.1,12,40,Math.PI),INK,0,0,0);
      [-1,1].forEach(function(s){ h.cyl(.44,.44,.3,'#14B8A6',s*.86,-.1,0).rotation.z=Math.PI/2; h.cyl(.36,.36,.14,'#0F766E',s*.66,-.1,0).rotation.z=Math.PI/2; });
      var curve=new T.QuadraticBezierCurve3(new T.Vector3(-.9,-.3,.1),new T.Vector3(-1.02,-.9,.95),new T.Vector3(-.3,-.78,1.0));
      h.add(new T.TubeGeometry(curve,20,.05,8,false),INK,0,0,0); h.ball(.14,'#F59E0B',-.3,-.78,1.0);
    },
    /* HubSpot: marketing and sales — a funnel with leads dropping in */
    hubspot:function(k){
      k.cyl(.5,.56,.1,STEEL,0,.05,0); k.cyl(.16,.16,.62,'#B8405A',0,.39,0);
      k.cyl(.52,.2,.5,'#F2617A',0,.95,0); k.cyl(.86,.52,.5,'#FFB347',0,1.45,0); k.cyl(1.18,.86,.5,'#FF8A5B',0,1.95,0);
      k.cyl(1.06,1.06,.02,'#C4572B',0,2.2,0,40);
      k.ball(.2,'#3B82F6',-.45,2.5,.1); k.ball(.17,'#22C55E',.38,2.7,-.1); k.ball(.15,'#FACC15',.02,3.0,.15);
    },
    /* eFax: a fax machine, a page going in and a page coming out */
    efax:function(k){
      k.pad(2.0,1.5,.72,.18,'#5B6B8C',0,0,0);
      k.box(1.5,.07,.1,'#2B3550',0,.42,.76);
      var out=k.sub(0,.38,1.0); out.g.rotation.x=.14; out.box(1.25,.03,.72,PAPER,0,0,0); out.box(.8,.02,.07,RULE,-.1,.02,-.08); out.box(.8,.02,.07,RULE,-.1,.02,.12);
      var inn=k.sub(0,1.3,-.38); inn.g.rotation.x=-.28; inn.card(1.2,1.35,.04,.05,PAPER,0,0,0);
      inn.box(.8,.08,.02,'#2F6FAE',-.08,.38,.03); inn.box(.8,.08,.02,RULE,-.08,.18,.03); inn.box(.8,.08,.02,RULE,-.08,-.02,.03); inn.box(.5,.08,.02,RULE,-.23,-.22,.03);
      k.box(.7,.04,.36,'#A7F3D0',-.5,.74,.34); k.cyl(.11,.11,.08,'#22C55E',.34,.76,.36); k.cyl(.11,.11,.08,'#EF4444',.68,.76,.36); k.cyl(.11,.11,.08,'#FACC15',.51,.76,.1);
    },
    /* Jotform: forms — a clipboard with a form being ticked */
    jotform:function(k){
      k.pad(1.3,.6,.12,.1,'#94A3B8',0,0,.05);
      var b=k.sub(0,1.2,0); b.g.rotation.x=-.22;
      b.card(1.7,2.2,.1,.14,'#2B3A67',0,0,0); b.card(1.38,1.8,.03,.05,PAPER,0,-.1,.07); b.card(.66,.26,.14,.08,'#F59E0B',0,1.0,.06);
      var cols=['#22C55E','#3B82F6','#F97316','#A855F7'];
      for(var i=0;i<4;i++){ var y=.48-i*.4; b.box(.22,.22,.03,cols[i],-.44,y,.1); b.box(.66,.08,.02,RULE,.2,y+.06,.095); b.box(.44,.08,.02,'#DDE5EC',.09,y-.07,.095); }
    },
    /* the phone system in the office — a desk phone */
    pbx:function(k){
      var prof=new T.Shape(); prof.moveTo(-.8,0); prof.lineTo(.9,0); prof.lineTo(.9,.28); prof.lineTo(-.8,.85); prof.lineTo(-.8,0);
      var g=new T.ExtrudeGeometry(prof,{depth:2.1,bevelEnabled:false}); g.rotateY(-Math.PI/2); g.translate(1.05,0,0);
      k.add(g,'#334155',0,0,0);
      var t=k.sub(0,.565,.05); t.g.rotation.x=Math.atan2(.57,1.7);
      t.box(.34,.2,1.45,INK,-.68,.22,0); t.box(.42,.24,.4,INK,-.68,.12,-.56); t.box(.42,.24,.4,INK,-.68,.12,.56);
      t.box(.95,.05,.42,'#7DD3FC',.32,.03,-.5);
      for(var r=0;r<4;r++)for(var c=0;c<3;c++) t.box(.2,.07,.16,'#E2E8F0',-.2+c*.27,.04,-.1+r*.23);
      t.box(.22,.07,.16,'#22C55E',.74,.04,-.1); t.box(.22,.07,.16,'#EF4444',.74,.04,.13); t.box(.22,.07,.16,'#FACC15',.74,.04,.36);
    },
    /* Paylocity: HR and payroll — a staff ID card on a stand, with a check: this person works here */
    paylocity:function(k){
      k.pad(1.2,.6,.14,.1,'#94A3B8',0,0,0);
      var c=k.sub(0,1.25,0); c.g.rotation.x=-.08;
      c.card(1.5,2.0,.08,.14,'#F8F5EE',0,0,0); c.card(1.5,.42,.09,.14,'#E4573D',0,.8,0); c.card(.36,.1,.1,.05,'#F8F5EE',0,.86,0);
      c.ball(.26,'#3B4A63',0,.28,.07).scale.z=.3; c.add(new T.SphereGeometry(.4,24,12,0,Math.PI*2,0,Math.PI/2),'#3B4A63',0,-.24,.07).scale.z=.3;
      c.box(.9,.08,.02,'#2F6FAE',0,-.42,.06); c.box(.66,.07,.02,RULE,0,-.6,.06); c.box(.66,.07,.02,RULE,0,-.76,.06);
      c.cyl(.2,.2,.08,'#22C55E',.5,-.65,.09,24).rotation.x=Math.PI/2;
    },
    /* CodeTwo: the email signature — an envelope with a pen writing a signature line */
    codetwo:function(k){
      k.pad(1.4,.6,.12,.1,'#94A3B8',0,0,0);
      var e=k.sub(0,1.0,0); e.g.rotation.x=-.12;
      e.card(1.7,1.15,.08,.08,'#EAF2FB',0,0,0);
      var tri=new T.Shape(); tri.moveTo(-.8,.52); tri.lineTo(.8,.52); tri.lineTo(0,-.08); tri.lineTo(-.8,.52);
      e.add(new T.ExtrudeGeometry(tri,{depth:.03,bevelEnabled:false}),'#9EC2E8',0,0,.04);
      var sig=new T.CatmullRomCurve3([new T.Vector3(-.6,-.32,.07),new T.Vector3(-.35,-.18,.07),new T.Vector3(-.15,-.4,.07),new T.Vector3(.1,-.2,.07),new T.Vector3(.38,-.36,.07)]);
      e.add(new T.TubeGeometry(sig,24,.025,6,false),'#1D2B3A',0,0,0);
      var pen=k.sub(.62,1.5,.55); pen.g.rotation.set(0,0,-.75); pen.cyl(.07,.07,1.1,'#2F6FAE',0,0,0,14); pen.add(new T.ConeGeometry(.07,.22,14),'#1D2B3A',0,-.66,0).rotation.x=Math.PI;
    },
    /* WHM / cPanel: the web server — a screen with the server's health on it */
    whm:function(k){
      k.cyl(.45,.55,.1,STEEL,0,.05,0); k.box(.14,.9,.14,'#94A3B8',0,.5,0);
      var m=k.sub(0,1.75,0); m.g.rotation.x=-.06;
      m.card(2.0,1.35,.1,.08,'#334155',0,0,0); m.card(1.82,1.17,.02,.05,'#0F2740',0,0,.06);
      var bars=[['#22C55E',.6],['#22C55E',.42],['#FACC15',.3],['#22C55E',.5]];
      bars.forEach(function(b,i){ m.box(.18,b[1],.02,b[0],-.62+i*.28,-.4+b[1]/2,.075); });
      m.add(new T.TorusGeometry(.26,.06,10,30,Math.PI*1.4),'#38BDF8',.5,.05,.08); m.box(.5,.06,.02,'#64748B',.5,-.38,.075);
    },
    /* Hosting provider: a server rack with its lights */
    hosting:function(k){
      k.pad(1.4,1.1,.12,.1,'#475569',0,0,0);
      k.box(1.2,2.9,1.0,'#2B3550',0,1.55,0);
      for(var i=0;i<5;i++){ var y=.5+i*.5; k.box(1.02,.36,.04,'#3D4A68',0,y+.08,.51); k.box(.5,.05,.02,'#64748B',-.18,y+.08,.535);
        k.ball(.04,i===3?'#F59E0B':'#22C55E',.35,y+.08,.54); k.ball(.04,'#38BDF8',.44,y+.08,.54); }
    },
    /* a system the company added: an app window */
    generic:function(k){
      k.pad(1.3,.6,.12,.1,'#94A3B8',0,0,0);
      var w=k.sub(0,1.25,0); w.g.rotation.x=-.08;
      w.card(1.9,1.5,.08,.1,PAPER,0,0,0); w.card(1.9,.3,.09,.1,'#64748B',0,.6,0);
      ['#EF4444','#FACC15','#22C55E'].forEach(function(c,i){ w.ball(.05,c,-.8+i*.14,.6,.06); });
      w.box(.5,.5,.02,'#C7DBEE',-.5,.05,.05); w.box(.7,.08,.02,RULE,.3,.2,.05); w.box(.7,.08,.02,RULE,.3,0,.05); w.box(.5,.08,.02,RULE,.2,-.2,.05);
      w.box(1.4,.08,.02,'#DDE5EC',0,-.5,.05);
    }
  };
  /* one system: the round stand (its ring shows the kind) and the object on it. Returns the group and its materials. */
  function place(n,ringColor,standColor,ringToken){ var g=new T.Group(); g.position.set(n.x,0,n.z); var mats=[], k=new Kit(g,mats);
    k.cyl(2.2,2.2,.1,ringColor,0,.05,0,64).material.userData.token=ringToken;
    k.cyl(1.98,2.02,.2,standColor,0,.12,0,64).material.userData.token='--stand';
    var o=k.sub(0,.22,0); o.g.rotation.y=FACE; o.g.scale.setScalar(BIG); (DRAW[n.id]||DRAW.generic)(o);
    mats.forEach(function(m){ m.userData.base=m.color.clone(); });
    g.traverse(function(m){ if(m.isMesh) m.userData.id=n.id; });
    return {group:g,mats:mats,h:DRAW_H[DRAW[n.id]?n.id:'generic']}; }
  return {Kit:Kit,DRAW:DRAW,DRAW_H:DRAW_H,place:place,FACE:FACE,BIG:BIG,INK:INK,PAPER:PAPER,RULE:RULE,STEEL:STEEL,height:function(id){ return DRAW_H[DRAW[id]?id:'generic']; }};
};
