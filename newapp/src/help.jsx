/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
const {useState,useEffect,useCallback,useRef}=React;
const API=location.pathname.replace(/\/[^/]*$/,'')+'/api';
const Icon=({d,s=18,c="currentColor"})=><svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d={d}/></svg>;
const SEARCH="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z";
const BACK="M19 12H5M12 19l-7-7 7-7";

function fmtDate(s){ if(!s)return''; return (s+'').slice(0,10); }

function App(){
  const [arts,setArts]=useState([]);
  const [cats,setCats]=useState([]);
  const [cat,setCat]=useState('');
  const [q,setQ]=useState('');
  const [loading,setLoading]=useState(true);
  const [active,setActive]=useState(null);   // full article
  const [err,setErr]=useState('');
  const [brand,setBrand]=useState({});
  const [staff,setStaff]=useState(false);   /* BT140: signed in with Knowledge Base view → staff-only articles are listed too */
  useEffect(()=>{ fetch(API+'/branding').then(r=>r.json()).then(b=>setBrand(b||{})).catch(()=>{}); },[]);

  const seq=useRef(0);   /* BT91: only the answer to the latest search is shown (an older, slower answer is dropped) */
  const load=useCallback(async()=>{
    const my=++seq.current;
    setLoading(true); setErr('');
    try{
      const u=new URL(API+'/kb_public',location.origin);
      if(q.trim())u.searchParams.set('q',q.trim());
      if(cat)u.searchParams.set('category',cat);
      const r=await fetch(u.pathname+u.search);
      const j=await r.json();
      if(my!==seq.current) return;
      setArts(j.data||[]); if(j.categories)setCats(j.categories); setStaff(!!j.staff);
    }catch(e){ if(my!==seq.current) return; setErr('Could not load articles. Please try again later.'); }
    setLoading(false);
  },[q,cat]);

  useEffect(()=>{ const t=setTimeout(load,q?250:0); return ()=>clearTimeout(t); },[load,q]);

  const open=async(a)=>{
    setActive('loading');
    try{ const r=await fetch(API+'/kb_public/'+a.id); const j=await r.json(); setActive(j.data); window.scrollTo(0,0); }
    catch(e){ setActive(null); }
  };

  if(active && active!=='loading'){
    return <div>
      <div className="hero"><div className="wrap"><div className="tabs"><a className="tab" href="guide.html">Help Center</a><a className="tab active" href="help.html">Knowledge Base</a></div>{brand.logo&&<img src={brand.logo} alt="" className="kbLogo"/>}<h1>IT Knowledge Base</h1></div></div>
      <div className="main">
        <div className="card detail">
          <div className="back" onClick={()=>setActive(null)}><Icon d={BACK} s={16}/> All articles</div>
          <h2>{active.title}</h2>
          <div className="meta">{active.category_name&&<span className="badge">{active.category_name}</span>}{+active.is_public===0&&<span className="badge" data-kb-staff="1">Staff only</span>}
            <span>Updated {fmtDate(active.updated_at)}</span><span>· {active.views_count||0} views</span></div>
          <div className="body" dangerouslySetInnerHTML={{__html:(window.DOMPurify?window.DOMPurify.sanitize(active.content||'<p>No content.</p>'):(function(h){ var d=document.createElement('div'); d.textContent=String(h==null?'':h).replace(/<[^>]*>/g,' '); return d.innerHTML; })(active.content||''))}}/>
        </div>
        
      </div>
    </div>;
  }

  return <div>
    <div className="hero"><div className="wrap">
      <div className="tabs"><a className="tab" href="guide.html">Help Center</a><a className="tab active" href="help.html">Knowledge Base</a></div>
      {brand.logo&&<img src={brand.logo} alt="" className="kbLogo"/>}
      <h1>IT Knowledge Base</h1>
      <p>Search our knowledge base for answers, guides, and how-tos.</p>
      <div className="search">
        <Icon d={SEARCH} s={19} c="#888"/>
        <input value={q} onChange={e=>setQ(e.target.value)} placeholder="Search for help…" autoFocus/>
      </div>
    </div></div>
    <div className="main">
      {staff&&<div className="staffnote" data-kb-staff-note="1">You are signed in, so staff-only articles are listed too (marked “Staff only”). Visitors who are not signed in see only the public ones.</div>}
      {cats.length>0&&<div className="chips">
        <span className={"chip"+(cat===''?' on':'')} onClick={()=>setCat('')}>All</span>
        {cats.map(c=><span key={c.id} className={"chip"+(cat==c.id?' on':'')} onClick={()=>setCat(cat==c.id?'':c.id)}>{c.name}</span>)}
      </div>}
      {loading?<div className="spin"/>:err?<div className="empty">{err}</div>:
       !arts.length?<div className="empty">{q?'No articles match “'+q+'”.':'No published articles yet.'}</div>:
       <div>{arts.map(a=><div key={a.id} className="card art" onClick={()=>open(a)}>
         <h3>{a.title}</h3>
         <div className="ex">{a.excerpt}</div>
         <div className="meta">{a.category_name&&<span className="badge">{a.category_name}</span>}{+a.is_public===0&&<span className="badge" data-kb-staff={a.id}>Staff only</span>}<span>{a.views_count||0} views</span></div>
       </div>)}</div>}
      
    </div>
  </div>;
}
ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
