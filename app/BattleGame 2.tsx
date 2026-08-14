"use client";
import {useState} from "react";

type Unit={club:string;faction:string;trait:string;img:string;color:string};
type Move="strike"|"guard"|"rally"|"special";
const moves:{id:Move;name:string;icon:string;desc:string}[]=[
 {id:"strike",name:"Charge",icon:"⚔",desc:"Deal damage · build fury"},
 {id:"guard",name:"Shield Wall",icon:"◈",desc:"Halve damage · build fury"},
 {id:"rally",name:"Rally",icon:"✦",desc:"Restore army morale"},
 {id:"special",name:"War Cry",icon:"♜",desc:"Heavy assault · 60 fury"}
];
const moveName=(m:Move)=>moves.find(x=>x.id===m)!.name;
const hit=(m:Move,f:number)=>m==="special"&&f>=60?28+Math.floor(Math.random()*9):m==="strike"?13+Math.floor(Math.random()*8):0;

export default function BattleGame({units}:{units:Unit[]}){
 const [screen,setScreen]=useState<"mode"|"select"|"battle">("mode");
 const [mode,setMode]=useState<"cpu"|"pvp">("cpu");
 const [p1,setP1]=useState(0),[p2,setP2]=useState(1),[picker,setPicker]=useState<1|2>(1);
 const [hp,setHp]=useState<[number,number]>([100,100]),[fury,setFury]=useState<[number,number]>([0,0]);
 const [pending,setPending]=useState<Move|null>(null),[turn,setTurn]=useState(1),[log,setLog]=useState("The horns sound. Choose your first order."),[impact,setImpact]=useState<0|1|2>(0);
 const a=units[p1],b=units[p2],winner=hp[0]<=0?2:hp[1]<=0?1:0;
 const reset=()=>{setHp([100,100]);setFury([0,0]);setPending(null);setTurn(1);setImpact(0);setPicker(1);setLog("The horns sound. Choose your first order.")};
 const start=(m:"cpu"|"pvp")=>{setMode(m);setPicker(1);setScreen("select")};
 const select=(i:number)=>{if(picker===1){setP1(i);setPicker(2)}else{setP2(i===p1?(i+1)%units.length:i);reset();setScreen("battle")}};
 const cpuMove=():Move=>hp[1]<38&&Math.random()<.36?"rally":fury[1]>=60&&Math.random()<.55?"special":Math.random()<.28?"guard":"strike";
 const resolve=(m1:Move,m2:Move)=>{
  const nh:[number,number]=[...hp],nf:[number,number]=[...fury];
  const run=(who:0|1,m:Move,other:Move)=>{if(m==="rally"){nh[who]=Math.min(100,nh[who]+12+Math.floor(Math.random()*7));nf[who]=Math.min(100,nf[who]+10);return}let dmg=hit(m,nf[who]);if(m==="special"&&nf[who]>=60)nf[who]-=60;else nf[who]=Math.min(100,nf[who]+(m==="guard"?14:22));if(other==="guard")dmg=Math.ceil(dmg*.42);nh[who?0:1]=Math.max(0,nh[who?0:1]-dmg)};
  run(0,m1,m2);run(1,m2,m1);setHp(nh);setFury(nf);setTurn(t=>t+1);setImpact(nh[0]<hp[0]?1:nh[1]<hp[1]?2:0);window.setTimeout(()=>setImpact(0),420);setLog(`${a.faction} order ${moveName(m1)}. ${b.faction} answer with ${moveName(m2)}.`)
 };
 const act=(m:Move)=>{if(winner)return;const actor=mode==="pvp"&&pending?1:0;if(m==="special"&&fury[actor]<60){setLog("The army needs 60 fury before its War Cry can be unleashed.");return}if(mode==="cpu"){resolve(m,cpuMove());return}if(!pending){setPending(m);setPicker(2);setLog("Player One’s order is sealed. Pass the device to Player Two.")}else{resolve(pending,m);setPending(null);setPicker(1)}};

 if(screen==="mode")return <div className="gameShell gameStart"><p className="eyebrow">THE WAR ROOM</p><h2>Command the army.</h2><p>Choose a champion. Read the enemy. Break their morale before they break yours.</p><div className="modeCards"><button onClick={()=>start("cpu")}><i>Ⅰ</i><b>Solo Campaign</b><span>Fight an adaptive computer army</span></button><button onClick={()=>start("pvp")}><i>Ⅱ</i><b>Two Commanders</b><span>Pass-and-play on one device</span></button></div></div>;
 if(screen==="select")return <div className="gameShell selectScreen"><button className="backButton" onClick={()=>setScreen(picker===1?"mode":"select")}>← {picker===1?"Modes":"Choose again"}</button><p className="eyebrow">{picker===1?"PLAYER ONE":"THE OPPOSING ARMY"}</p><h2>{picker===1?"Choose your champion.":mode==="cpu"?"Choose your enemy.":"Player Two, choose."}</h2><div className="roster">{units.map((u,i)=><button key={u.club} className={picker===2&&i===p1?"chosen":""} onClick={()=>select(i)} disabled={picker===2&&i===p1}><img src={`/warriors/${u.img}`} alt={u.faction}/><span style={{background:u.color}}/><div><small>{u.club}</small><b>{u.faction}</b><em>{u.trait}</em></div></button>)}</div></div>;
 return <div className="gameShell battleScreen"><div className="gameTop"><button className="backButton" onClick={()=>{reset();setScreen("mode")}}>← Leave battle</button><p>TURN {String(turn).padStart(2,"0")} · {mode==="cpu"?"SOLO CAMPAIGN":"TWO COMMANDERS"}</p></div><div className="battleStage">
  <div className={`combatant playerOne ${impact===1?"hit":""}`}><img src={`/warriors/${a.img}`} alt={a.faction}/><div><small>{a.club}</small><b>{a.faction}</b></div></div>
  <div className="battleMark"><span>WAR</span><b>VS</b></div>
  <div className={`combatant playerTwo ${impact===2?"hit":""}`}><img src={`/warriors/${b.img}`} alt={b.faction}/><div><small>{b.club}</small><b>{b.faction}</b></div></div>
  <div className="battleHud leftHud"><label>Morale <b>{hp[0]}</b></label><span><i style={{width:`${hp[0]}%`,background:a.color}}/></span><label>Fury <b>{fury[0]}</b></label><span className="fury"><i style={{width:`${fury[0]}%`}}/></span></div>
  <div className="battleHud rightHud"><label><b>{hp[1]}</b> Morale</label><span><i style={{width:`${hp[1]}%`,background:b.color}}/></span><label><b>{fury[1]}</b> Fury</label><span className="fury"><i style={{width:`${fury[1]}%`}}/></span></div>
 </div><div className="warLog"><small>{winner?"THE FIELD FALLS SILENT":mode==="pvp"?`PLAYER ${pending?"TWO":"ONE"} TO COMMAND`:"YOUR WAR COUNCIL"}</small><p>{winner?`${winner===1?a.faction:b.faction} stand victorious. Their banner claims the field.`:log}</p></div>
 {winner?<div className="victoryActions"><button onClick={reset}>Fight again</button><button onClick={()=>{reset();setScreen("select")}}>Change armies</button></div>:<div className="orders">{moves.map(m=><button key={m.id} onClick={()=>act(m.id)} className={m.id==="special"?"special":""}><i>{m.icon}</i><span><b>{m.name}</b><small>{m.desc}</small></span></button>)}</div>}
 </div>;
}
