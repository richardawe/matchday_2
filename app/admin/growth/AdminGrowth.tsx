"use client";
import {useEffect,useState} from "react";

type Campaign={id:string;home:string;away:string;channel:string;kind:string;caption:string;status:string;kickoff:string};

export default function AdminGrowth(){
 const [email,setEmail]=useState(""),[authenticated,setAuthenticated]=useState(false),[items,setItems]=useState<Campaign[]>([]),[message,setMessage]=useState("");
 const load=()=>fetch("/api/growth/campaigns").then(r=>r.json()).then(setItems);
 useEffect(()=>{fetch("/api/growth/auth/session").then(r=>r.json()).then(x=>setAuthenticated(x.authenticated));load()},[]);
 const signIn=async(e:React.FormEvent)=>{e.preventDefault();const r=await fetch("/api/growth/auth/request",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({email})}),x=await r.json();setMessage(x.error||"Check your email for a secure sign-in link.");if(x.previewUrl)location.href=x.previewUrl};
 const action=async(id:string,a:string)=>{const r=await fetch(`/api/growth/campaigns/${id}`,{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({action:a})});const x=await r.json();setMessage(x.error||`${a}d`);load()};
 const generate=async()=>{const secret=prompt("Cron secret")||"",r=await fetch("/api/growth/generate",{method:"POST",headers:{authorization:`Bearer ${secret}`}});setMessage(JSON.stringify(await r.json()));load()};
 if(!authenticated)return <div className="adminGrowth adminLogin"><p className="eyebrow">PRIVATE CAMPAIGN DESK</p><h1>Enter the war council</h1><p>We will email an expiring sign-in link to an authorised editor.</p><form onSubmit={signIn}><input required type="email" value={email} onChange={e=>setEmail(e.target.value)} placeholder="editor@example.com"/><button>SEND SIGN-IN LINK</button></form>{message&&<p className="adminNotice">{message}</p>}</div>;
 return <div className="adminGrowth"><header><div><p className="eyebrow">PRIVATE CAMPAIGN DESK</p><h1>Growth Engine</h1></div><button onClick={generate}>GENERATE WEEK</button></header>{message&&<p className="adminNotice">{message}</p>}<section className="growthStats"><article><b>{items.length}</b><span>DRAFTS GENERATED</span></article><article><b>{items.filter(x=>x.status==='approved').length}</b><span>APPROVED</span></article><article><b>{new Set(items.map(x=>x.id.split('-')[1])).size}</b><span>HERO CLASHES</span></article></section><div className="campaignGrid">{items.map(x=><article key={x.id}><small>{x.channel} · {x.kind} · {x.status}</small><h2>{x.home} <i>VS</i> {x.away}</h2><p>{x.caption}</p><footer><button onClick={()=>action(x.id,"reject")}>Reject</button><button onClick={()=>action(x.id,"approve")}>Approve</button></footer></article>)}</div></div>;
}
