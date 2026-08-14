/** Cloudflare Worker entry point for the vinext-starter template. */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";
import { fallbackFixtures, mythResult, type GrowthFixture } from "../app/growth-data";

interface Env {
  ASSETS: Fetcher;
  DB: D1Database;
  FOOTBALL_DATA_TOKEN?: string;
  CRON_SECRET?: string;
  ADMIN_EMAILS?: string;
  RESEND_API_KEY?: string;
  BUFFER_API_KEY?: string;
  BUFFER_CHANNELS?: string;
  PUBLIC_BASE_URL?: string;
  IMAGES: {
    input(stream: ReadableStream): {
      transform(options: Record<string, unknown>): {
        output(options: { format: string; quality: number }): Promise<{ response(): Response }>;
      };
    };
  };
}

const roomSchema = `CREATE TABLE IF NOT EXISTS game_rooms (code TEXT PRIMARY KEY, host_id TEXT NOT NULL, guest_id TEXT, host_team INTEGER NOT NULL DEFAULT 0, guest_team INTEGER NOT NULL DEFAULT 1, state TEXT NOT NULL, updated_at INTEGER NOT NULL)`;
const json = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { "content-type": "application/json", "cache-control": "no-store" } });
const code = () => Math.random().toString(36).slice(2, 8).toUpperCase();
const token = () => Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2, "0")).join("");
const growthSchema=[
 "CREATE TABLE IF NOT EXISTS growth_fixtures (id TEXT PRIMARY KEY, slug TEXT UNIQUE NOT NULL, matchweek INTEGER NOT NULL, kickoff TEXT NOT NULL, home TEXT NOT NULL, away TEXT NOT NULL, home_score INTEGER, away_score INTEGER, status TEXT NOT NULL, hero INTEGER NOT NULL DEFAULT 0, myth TEXT NOT NULL, updated_at INTEGER NOT NULL)",
 "CREATE TABLE IF NOT EXISTS growth_campaigns (id TEXT PRIMARY KEY, matchweek INTEGER NOT NULL, fixture_id TEXT, kind TEXT NOT NULL, channel TEXT NOT NULL, caption TEXT NOT NULL, asset_url TEXT, scheduled_at TEXT, status TEXT NOT NULL DEFAULT 'draft', approved_by TEXT, approved_at INTEGER, external_id TEXT, created_at INTEGER NOT NULL)",
 "CREATE TABLE IF NOT EXISTS growth_referrals (id TEXT PRIMARY KEY, fixture_id TEXT NOT NULL, source TEXT NOT NULL, campaign TEXT, created_at INTEGER NOT NULL, landed_at INTEGER, challenge_at INTEGER, joined_at INTEGER, completed_at INTEGER)",
 "CREATE TABLE IF NOT EXISTS growth_magic_links (token TEXT PRIMARY KEY, email TEXT NOT NULL, expires_at INTEGER NOT NULL, used_at INTEGER)",
 "CREATE TABLE IF NOT EXISTS growth_subscribers (id TEXT PRIMARY KEY, email TEXT, push_id TEXT, email_consent INTEGER NOT NULL DEFAULT 0, push_consent INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, unsubscribed_at INTEGER)",
 "CREATE TABLE IF NOT EXISTS growth_jobs (id TEXT PRIMARY KEY, kind TEXT NOT NULL, status TEXT NOT NULL, detail TEXT, created_at INTEGER NOT NULL, finished_at INTEGER)"
];
const ensureGrowth=async(db:D1Database)=>{await db.batch(growthSchema.map(sql=>db.prepare(sql)))};
const cookieValue=(request:Request,name:string)=>request.headers.get("cookie")?.split(";").map(x=>x.trim()).find(x=>x.startsWith(`${name}=`))?.slice(name.length+1)||"";
const adminSession=async(request:Request,env:Env)=>{const session=cookieValue(request,"matchday_admin");if(!session)return null;return env.DB.prepare("SELECT email FROM growth_magic_links WHERE token=? AND used_at IS NOT NULL AND expires_at>?").bind(session,Date.now()).first<{email:string}>()};
const slugify=(s:string)=>s.toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/(^-|-$)/g,"");
const templates=(f:GrowthFixture)=>({pre:`${f.home} face ${f.away}. Two banners enter; one battlefield decides it. Challenge a friend and command your army.`,post:f.homeScore===null||f.awayScore===null?f.myth:mythResult(f.home,f.away,f.homeScore,f.awayScore)});
const syncFixtures=async(env:Env)=>{await ensureGrowth(env.DB);let list:GrowthFixture[]=fallbackFixtures;if(env.FOOTBALL_DATA_TOKEN){const from=new Date().toISOString().slice(0,10),to=new Date(Date.now()+14*86400000).toISOString().slice(0,10);const r=await fetch(`https://api.football-data.org/v4/competitions/PL/matches?dateFrom=${from}&dateTo=${to}`,{headers:{"X-Auth-Token":env.FOOTBALL_DATA_TOKEN}});if(r.ok){const data=await r.json<{matches:Array<any>}>();list=data.matches.map((m:any)=>({id:String(m.id),slug:`${slugify(m.homeTeam.name)}-v-${slugify(m.awayTeam.name)}`,matchweek:m.matchday||0,kickoff:m.utcDate,home:m.homeTeam.name,away:m.awayTeam.name,homeScore:m.score?.fullTime?.home??null,awayScore:m.score?.fullTime?.away??null,status:m.status,hero:false,myth:"Two banners approach the field. Only one will command the gate."}))}}const now=Date.now();await env.DB.batch(list.map(f=>env.DB.prepare("INSERT INTO growth_fixtures (id,slug,matchweek,kickoff,home,away,home_score,away_score,status,hero,myth,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET slug=excluded.slug,matchweek=excluded.matchweek,kickoff=excluded.kickoff,home=excluded.home,away=excluded.away,home_score=excluded.home_score,away_score=excluded.away_score,status=excluded.status,myth=excluded.myth,updated_at=excluded.updated_at").bind(f.id,f.slug,f.matchweek,f.kickoff,f.home,f.away,f.homeScore,f.awayScore,f.status,f.hero?1:0,f.myth,now)));return list};

interface ExecutionContext {
  waitUntil(promise: Promise<unknown>): void;
  passThroughOnException(): void;
}

// Image security config. SVG sources with .svg extension auto-skip the
// optimization endpoint on the client side (served directly, no proxy).
// To route SVGs through the optimizer (with security headers), set
// dangerouslyAllowSVG: true in next.config.js and uncomment below:
// const imageConfig: ImageConfig = { dangerouslyAllowSVG: true };

const worker = {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const url = new URL(request.url);

    if (url.pathname.startsWith("/api/growth")) {
      if(!env?.DB){if(url.pathname==="/api/growth/fixtures"&&request.method==="GET")return json(fallbackFixtures);return json({error:"Growth database binding is unavailable in this runtime."},503)}
      await ensureGrowth(env.DB);
      const secretOk=()=>!env.CRON_SECRET||request.headers.get("authorization")===`Bearer ${env.CRON_SECRET}`;
      if(url.pathname==="/api/growth/auth/request"&&request.method==="POST"){
        const body=await request.json<{email:string}>(),email=body.email.trim().toLowerCase(),allowed=(env.ADMIN_EMAILS||"").split(",").map(x=>x.trim().toLowerCase()).filter(Boolean);
        if(!allowed.includes(email))return json({error:"This address is not authorised for the campaign desk."},403);
        const magicToken=token(),expires=Date.now()+15*60*1000,base=env.PUBLIC_BASE_URL||url.origin,link=`${base}/api/growth/auth/verify?token=${magicToken}`;
        await env.DB.prepare("INSERT INTO growth_magic_links (token,email,expires_at) VALUES (?,?,?)").bind(magicToken,email,expires).run();
        if(env.RESEND_API_KEY){const sent=await fetch("https://api.resend.com/emails",{method:"POST",headers:{authorization:`Bearer ${env.RESEND_API_KEY}`,"content-type":"application/json"},body:JSON.stringify({from:"Matchday War Council <growth@matchdaywarriors.com>",to:[email],subject:"Enter the Matchday campaign desk",html:`<h1>Your campaign desk is ready</h1><p><a href="${link}">Open the private review desk</a></p><p>This link expires in 15 minutes.</p>`})});if(!sent.ok)return json({error:"The sign-in email could not be sent."},502)}
        return json({ok:true,previewUrl:env.RESEND_API_KEY?undefined:link});
      }
      if(url.pathname==="/api/growth/auth/verify"&&request.method==="GET"){
        const magicToken=url.searchParams.get("token")||"",row=await env.DB.prepare("SELECT email FROM growth_magic_links WHERE token=? AND used_at IS NULL AND expires_at>?").bind(magicToken,Date.now()).first<{email:string}>();
        if(!row)return new Response("This sign-in link is invalid or has expired.",{status:401});
        await env.DB.prepare("UPDATE growth_magic_links SET used_at=? WHERE token=?").bind(Date.now(),magicToken).run();
        return new Response(null,{status:302,headers:{location:"/admin/growth","set-cookie":`matchday_admin=${magicToken}; Path=/; HttpOnly; SameSite=Strict; Max-Age=43200${url.protocol==="https:"?"; Secure":""}`}});
      }
      if(url.pathname==="/api/growth/auth/session"&&request.method==="GET"){const session=await adminSession(request,env);return json({authenticated:Boolean(session),email:session?.email||null})}
      if(url.pathname==="/api/growth/auth/logout"&&request.method==="POST")return new Response(null,{status:204,headers:{"set-cookie":"matchday_admin=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0"}});
      if(url.pathname==="/api/growth/fixtures"&&request.method==="GET"){let rows=await env.DB.prepare("SELECT * FROM growth_fixtures ORDER BY kickoff").all();if(!rows.results.length){await syncFixtures(env);rows=await env.DB.prepare("SELECT * FROM growth_fixtures ORDER BY kickoff").all()}return json(rows.results)}
      if(url.pathname==="/api/growth/sync"&&request.method==="POST"){if(!secretOk())return json({error:"Unauthorised"},401);const fixtures=await syncFixtures(env);return json({ok:true,count:fixtures.length})}
      if(url.pathname==="/api/growth/generate"&&request.method==="POST"){if(!secretOk())return json({error:"Unauthorised"},401);const rows=await env.DB.prepare("SELECT * FROM growth_fixtures ORDER BY kickoff").all<Record<string,any>>();const fixtures=rows.results.map(r=>({id:String(r.id),slug:String(r.slug),matchweek:Number(r.matchweek),kickoff:String(r.kickoff),home:String(r.home),away:String(r.away),homeScore:r.home_score===null?null:Number(r.home_score),awayScore:r.away_score===null?null:Number(r.away_score),status:String(r.status),hero:Boolean(r.hero),myth:String(r.myth)}));const byWeek=new Map<number,GrowthFixture[]>();for(const f of fixtures)byWeek.set(f.matchweek,[...(byWeek.get(f.matchweek)||[]),f]);const week=[...byWeek.keys()].sort((a,b)=>a-b)[0]||1,selected=(byWeek.get(week)||[]).sort((a,b)=>Number(b.hero)-Number(a.hero)||Date.parse(a.kickoff)-Date.parse(b.kickoff)).slice(0,3);await env.DB.prepare("UPDATE growth_fixtures SET hero=0 WHERE matchweek=?").bind(week).run();await env.DB.batch(selected.map(f=>env.DB.prepare("UPDATE growth_fixtures SET hero=1 WHERE id=?").bind(f.id)));const channels=["instagram","tiktok","x"],now=Date.now(),statements=[];for(const f of selected)for(const channel of channels){const t=templates(f);for(const [kind,caption] of [["pre",t.pre],["post",t.post]]){const id=`mw${week}-${f.id}-${channel}-${kind}`;statements.push(env.DB.prepare("INSERT INTO growth_campaigns (id,matchweek,fixture_id,kind,channel,caption,status,created_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET caption=excluded.caption").bind(id,week,f.id,kind,channel,caption,"draft",now))}}if(statements.length)await env.DB.batch(statements);return json({ok:true,matchweek:week,hero:selected.map(f=>f.id),drafts:statements.length})}
      if(url.pathname==="/api/growth/campaigns"&&request.method==="GET"){const rows=await env.DB.prepare("SELECT c.*,f.home,f.away,f.slug,f.kickoff FROM growth_campaigns c LEFT JOIN growth_fixtures f ON f.id=c.fixture_id ORDER BY c.created_at DESC").all();return json(rows.results)}
      if(url.pathname.startsWith("/api/growth/campaigns/")&&request.method==="POST"){const session=await adminSession(request,env);if(!session)return json({error:"Sign in with your admin link first."},401);const id=url.pathname.split("/").pop()!,body=await request.json<{action:string}>();if(body.action==="approve")await env.DB.prepare("UPDATE growth_campaigns SET status='approved',approved_by=?,approved_at=? WHERE id=?").bind(session.email,Date.now(),id).run();if(body.action==="reject")await env.DB.prepare("UPDATE growth_campaigns SET status='rejected',approved_by=?,approved_at=? WHERE id=?").bind(session.email,Date.now(),id).run();return json({ok:true})}
      if(url.pathname==="/api/growth/publish"&&request.method==="POST"){if(!secretOk())return json({error:"Unauthorised"},401);if(!env.BUFFER_API_KEY)return json({ok:false,reason:"BUFFER_API_KEY is not configured"},503);const channels=JSON.parse(env.BUFFER_CHANNELS||"{}") as Record<string,string>,rows=await env.DB.prepare("SELECT * FROM growth_campaigns WHERE status='approved'").all<Record<string,any>>();let published=0;for(const row of rows.results){const channelId=channels[String(row.channel)];if(!channelId)continue;const query=`mutation CreatePost($input: CreatePostInput!){createPost(input:$input){... on PostActionSuccess{post{id}} ... on MutationError{message}}}`;const result=await fetch("https://api.buffer.com",{method:"POST",headers:{authorization:`Bearer ${env.BUFFER_API_KEY}`,"content-type":"application/json"},body:JSON.stringify({query,variables:{input:{channelId,text:row.caption,schedulingType:"automatic",mode:"addToQueue",saveToDraft:true,needsApproval:true,assets:[]}}})});const data=await result.json<any>();if(result.ok&&!data.errors){await env.DB.prepare("UPDATE growth_campaigns SET status='scheduled',external_id=? WHERE id=?").bind(data.data?.createPost?.post?.id||"buffer-draft",row.id).run();published++}}return json({ok:true,published})}
      if(url.pathname==="/api/growth/referral"&&request.method==="POST"){const b=await request.json<{id?:string;fixtureId:string;source:string;campaign?:string;event:string}>(),id=b.id||code()+Date.now().toString(36);await env.DB.prepare("INSERT INTO growth_referrals (id,fixture_id,source,campaign,created_at) VALUES (?,?,?,?,?) ON CONFLICT(id) DO NOTHING").bind(id,b.fixtureId,b.source,b.campaign||null,Date.now()).run();const field:{[key:string]:string}={landed:"landed_at",challenge:"challenge_at",joined:"joined_at",completed:"completed_at"};if(field[b.event])await env.DB.prepare(`UPDATE growth_referrals SET ${field[b.event]}=? WHERE id=?`).bind(Date.now(),id).run();return json({id})}
      if(url.pathname==="/api/growth/subscribe"&&request.method==="POST"){const b=await request.json<{email?:string;pushId?:string}>(),id=code()+Date.now().toString(36);await env.DB.prepare("INSERT INTO growth_subscribers (id,email,push_id,email_consent,push_consent,created_at) VALUES (?,?,?,?,?,?)").bind(id,b.email||null,b.pushId||null,b.email?1:0,b.pushId?1:0,Date.now()).run();return json({ok:true,id})}
      if(url.pathname==="/api/growth/digest"&&request.method==="POST"){
        if(!secretOk())return json({error:"Unauthorised"},401);if(!env.RESEND_API_KEY)return json({ok:false,reason:"RESEND_API_KEY is not configured"},503);
        const fixtures=await env.DB.prepare("SELECT * FROM growth_fixtures WHERE hero=1 ORDER BY kickoff LIMIT 3").all<Record<string,any>>(),subscribers=await env.DB.prepare("SELECT email FROM growth_subscribers WHERE email_consent=1 AND unsubscribed_at IS NULL AND email IS NOT NULL").all<{email:string}>();
        const cards=fixtures.results.map(f=>`<li><strong>${f.home} vs ${f.away}</strong><br>${new Date(String(f.kickoff)).toUTCString()}<br><a href="${env.PUBLIC_BASE_URL||url.origin}/match/${f.slug}">Enter the clash</a></li>`).join("");let sent=0;
        for(const subscriber of subscribers.results){const response=await fetch("https://api.resend.com/emails",{method:"POST",headers:{authorization:`Bearer ${env.RESEND_API_KEY}`,"content-type":"application/json"},body:JSON.stringify({from:"Matchday War Council <news@matchdaywarriors.com>",to:[subscriber.email],subject:"This week's three hero clashes",html:`<h1>The war council has chosen</h1><ul>${cards}</ul><p>Choose your warrior. Challenge a friend. Command the field.</p>`})});if(response.ok)sent++}
        return json({ok:true,sent});
      }
      return json({error:"Growth route not found"},404);
    }

    if (url.pathname.startsWith("/api/rooms")) {
      await env.DB.prepare(roomSchema).run();
      if (request.method === "POST" && url.pathname === "/api/rooms") {
        const body = await request.json<{ playerId: string; team: number }>();
        const roomCode = code();
        const state = JSON.stringify({ status: "waiting", ball: 50, possession: 0, score: [0, 0], turn: 0, remaining: 60, endsAt: 0, event: "Waiting for an opponent" });
        await env.DB.prepare("INSERT INTO game_rooms (code, host_id, host_team, state, updated_at) VALUES (?, ?, ?, ?, ?)").bind(roomCode, body.playerId, body.team, state, Date.now()).run();
        return json({ code: roomCode, side: 0 });
      }
      const parts = url.pathname.split("/").filter(Boolean);
      const roomCode = parts[2]?.toUpperCase();
      const row = roomCode ? await env.DB.prepare("SELECT * FROM game_rooms WHERE code = ?").bind(roomCode).first<Record<string, unknown>>() : null;
      if (!row) return json({ error: "Room not found" }, 404);
      if (request.method === "POST" && parts[3] === "join") {
        const body = await request.json<{ playerId: string; team: number }>();
        if (row.guest_id && row.guest_id !== body.playerId) return json({ error: "Room is full" }, 409);
        const state = JSON.parse(String(row.state)); state.status = "ready"; state.event = "Both commanders are ready";
        await env.DB.prepare("UPDATE game_rooms SET guest_id = ?, guest_team = ?, state = ?, updated_at = ? WHERE code = ?").bind(body.playerId, body.team, JSON.stringify(state), Date.now(), roomCode).run();
        return json({ code: roomCode, side: 1 });
      }
      if (request.method === "POST" && parts[3] === "action") {
        const body = await request.json<{ playerId: string; move: "attack"|"defend"|"counter"|"shoot" }>();
        const side = row.host_id === body.playerId ? 0 : row.guest_id === body.playerId ? 1 : -1;
        const state = JSON.parse(String(row.state));
        state.remaining = state.endsAt ? Math.max(0, Math.ceil((state.endsAt - Date.now()) / 1000)) : 60;
        if (!state.remaining) state.status = "finished";
        if (side < 0 || state.status !== "playing" || state.turn !== side) return json({ error: "Not your turn" }, 409);
        const dir = side === 0 ? 1 : -1, club = side === 0 ? "Home" : "Away";
        if (body.move === "attack" && state.possession === side) { state.ball = Math.max(8, Math.min(92, state.ball + 14 * dir)); state.event = `${club} charge forward`; }
        else if ((body.move === "defend" || body.move === "counter") && state.possession !== side) { const won = Math.random() < (body.move === "counter" ? .58 : .72); if (won) { state.possession = side; state.ball = Math.max(8, Math.min(92, state.ball + (body.move === "counter" ? 10 : 2) * dir)); state.event = `${club} reclaim the banner`; } else state.event = `${club} fail to win it back`; }
        else if (body.move === "shoot" && state.possession === side && (side === 0 ? state.ball >= 76 : state.ball <= 24)) { if (Math.random() < .5) { state.score[side]++; state.event = `${club} score! The raid lands!`; state.ball = 50; state.possession = side === 0 ? 1 : 0; } else { state.event = "The fortress holds!"; state.possession = side === 0 ? 1 : 0; } }
        else return json({ error: state.possession === side ? "You have the ball — attack or shoot" : "Win the ball with tackle or counter" }, 409);
        state.turn = side === 0 ? 1 : 0;
        await env.DB.prepare("UPDATE game_rooms SET state = ?, updated_at = ? WHERE code = ?").bind(JSON.stringify(state), Date.now(), roomCode).run();
        return json(state);
      }
      if (request.method === "POST" && parts[3] === "start") {
        if (row.host_id !== (await request.json<{playerId:string}>()).playerId) return json({ error: "Host only" }, 403);
        const state = JSON.parse(String(row.state)); state.status = "playing"; state.endsAt = Date.now() + 60000; state.remaining = 60; state.event = "Kick off! Home carry the banner.";
        await env.DB.prepare("UPDATE game_rooms SET state = ?, updated_at = ? WHERE code = ?").bind(JSON.stringify(state), Date.now(), roomCode).run();
        return json(state);
      }
      const state = JSON.parse(String(row.state));
      if (state.status === "playing" && state.endsAt) { state.remaining = Math.max(0, Math.ceil((state.endsAt - Date.now()) / 1000)); if (!state.remaining) state.status = "finished"; }
      return json({ code: row.code, hostTeam: row.host_team, guestTeam: row.guest_team, connected: Boolean(row.guest_id), state });
    }

    if (url.pathname === "/_vinext/image") {
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      return handleImageOptimization(request, {
        fetchAsset: (path) => env.ASSETS.fetch(new Request(new URL(path, request.url))),
        transformImage: async (body, { width, format, quality }) => {
          const result = await env.IMAGES.input(body).transform(width > 0 ? { width } : {}).output({ format, quality });
          return result.response();
        },
      }, allowedWidths);
    }

    return handler.fetch(request, env, ctx);
  },
};

export default worker;
