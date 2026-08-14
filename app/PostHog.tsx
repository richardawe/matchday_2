"use client";
import {useEffect} from "react";
export default function PostHog(){useEffect(()=>{const key=process.env.NEXT_PUBLIC_POSTHOG_KEY,host=process.env.NEXT_PUBLIC_POSTHOG_HOST||"https://us.i.posthog.com";if(!key)return;const s=document.createElement("script");s.async=true;s.src=`${host}/static/array.js`;s.onload=()=>{const ph=(window as any).posthog;ph?.init?.(key,{api_host:host,capture_pageview:true,capture_pageleave:true,autocapture:true})};document.head.appendChild(s);return()=>s.remove()},[]);return null}
