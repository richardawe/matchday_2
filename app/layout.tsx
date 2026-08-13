import type { Metadata } from "next";
import "./globals.css";
export const metadata: Metadata = {title:"Matchday — Armies of 2026/27",description:"Twenty clubs. One throne. The coming season reimagined as history’s greatest fighting forces.",icons:{icon:"/favicon.svg"}};
export default function RootLayout({children}:{children:React.ReactNode}){return <html lang="en"><body>{children}</body></html>}
