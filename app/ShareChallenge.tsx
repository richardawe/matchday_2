"use client";
import {useEffect,useState} from "react";

export default function ShareChallenge({fixtureId,title,playUrl}:{fixtureId:string;title:string;playUrl:string}){
 const [done,setDone]=useState(false);
 useEffect(()=>{
  const query=new URLSearchParams(location.search),ref=query.get("ref");
  if(!ref)return;
  sessionStorage.setItem("matchday_ref",ref);
  void fetch("/api/growth/referral",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({id:ref,fixtureId,source:query.get("source")||"shared",campaign:query.get("campaign"),event:"landed"})});
 },[fixtureId,playUrl]);
 const share=async()=>{
  const result=await fetch("/api/growth/referral",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({fixtureId,source:"web-share",event:"challenge"})}).then(r=>r.json());
  const target=`${location.origin}/challenge/${fixtureId}?ref=${result.id}&source=challenge`;
  if(navigator.share)await navigator.share({title,text:`${title}. Choose your army and face me.`,url:target});else await navigator.clipboard.writeText(target);
  setDone(true);
 };
 return <div className="shareChallenge"><a href={playUrl}>PLAY THIS CLASH</a><button onClick={share}>{done?"LINK COPIED":"CHALLENGE A FRIEND"}</button></div>;
}
