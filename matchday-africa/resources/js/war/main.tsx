import React from 'react';
import {createRoot} from 'react-dom/client';
import WarApp from './WarApp';
import './war.css';
import './game.css';
import './wallpapers.css';

const root=document.getElementById('war-root');
if(root){
 const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content||'';
 const originalFetch=window.fetch.bind(window);
 window.fetch=(input,init={})=>{
  const headers=new Headers(init.headers);
  if((init.method||'GET').toUpperCase()!=='GET')headers.set('X-CSRF-TOKEN',token);
  headers.set('Accept','application/json');
  return originalFetch(input,{...init,headers,credentials:'same-origin'});
 };
 createRoot(root).render(<WarApp/>);
}
