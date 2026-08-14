import type { Metadata } from "next";
import "./globals.css";
import PostHog from "./PostHog";
export const metadata: Metadata = {metadataBase:new URL(process.env.PUBLIC_BASE_URL||"https://matchday.example.com"),title:"Matchday — Armies of 2026/27",description:"Twenty clubs. One throne. The coming season reimagined as history’s greatest fighting forces.",icons:{icon:"/favicon.svg"},openGraph:{title:"Matchday — Every Match Becomes Myth",description:"Challenge a friend and command your army.",type:"website",images:["/warriors/arsenal.png"]},twitter:{card:"summary_large_image",title:"Matchday — Every Match Becomes Myth",description:"Challenge a friend and command your army.",images:["/warriors/arsenal.png"]}};
export default function RootLayout({children}:{children:React.ReactNode}){return <html lang="en"><body><PostHog/>{children}</body></html>}
