import{j as e}from"./vendor-framer-CCfUDh03.js";import{a as d,e as Ve,H as Be}from"./vendor-inertia-DsW_bj1n.js";import{a as D}from"./vendor-utils-DFrufTdJ.js";import{U as Ke,V as ce,b as y,a as F,d as $,i as S,h as A,l as I,F as R,P as me,Q as he,R as ue,S as K,C as qe,j as He,k as Ze,m as E,n as Qe,o as We,q as L,g as M,t as Ge,M as Je,r as xe,v as pe,w as fe,x as ge,z as je,A as ye}from"./vendor-heroui-DkLGpNQ3.js";import{b as Ye}from"./app-DtYa0Bcq.js";import{F as Xe}from"./PlusIcon-Cd_ScElN.js";import{F as es}from"./EnvelopeIcon-B1BXYboq.js";import{F as ve}from"./EyeIcon-BNcKmvus.js";import{F as ss}from"./ExclamationTriangleIcon-hEfUXJ81.js";import{F as as}from"./MagnifyingGlassIcon-Br2T_H01.js";import{F as ts}from"./FunnelIcon-B0H3aUsC.js";import{F as rs}from"./PencilIcon-DFpWDDAW.js";import{F as ls}from"./DocumentArrowDownIcon-C7xoFxD4.js";function ns({title:s,titleId:t,...r},i){return d.createElement("svg",Object.assign({xmlns:"http://www.w3.org/2000/svg",fill:"none",viewBox:"0 0 24 24",strokeWidth:1.5,stroke:"currentColor","aria-hidden":"true","data-slot":"icon",ref:i,"aria-labelledby":t},r),s?d.createElement("title",{id:t},s):null,d.createElement("path",{strokeLinecap:"round",strokeLinejoin:"round",d:"M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"}))}const be=d.forwardRef(ns);let is={data:""},os=s=>{if(typeof window=="object"){let t=(s?s.querySelector("#_goober"):window._goober)||Object.assign(document.createElement("style"),{innerHTML:" ",id:"_goober"});return t.nonce=window.__nonce__,t.parentNode||(s||document.head).appendChild(t),t.firstChild}return s||is},ds=/(?:([\u0080-\uFFFF\w-%@]+) *:? *([^{;]+?);|([^;}{]*?) *{)|(}\s*)/g,cs=/\/\*[^]*?\*\/|  +/g,we=/\n+/g,C=(s,t)=>{let r="",i="",c="";for(let o in s){let l=s[o];o[0]=="@"?o[1]=="i"?r=o+" "+l+";":i+=o[1]=="f"?C(l,o):o+"{"+C(l,o[1]=="k"?"":t)+"}":typeof l=="object"?i+=C(l,t?t.replace(/([^,])+/g,u=>o.replace(/([^,]*:\S+\([^)]*\))|([^,])+/g,x=>/&/.test(x)?x.replace(/&/g,u):u?u+" "+x:x)):o):l!=null&&(o=/^--/.test(o)?o:o.replace(/[A-Z]/g,"-$&").toLowerCase(),c+=C.p?C.p(o,l):o+":"+l+";")}return r+(t&&c?t+"{"+c+"}":c)+i},w={},_e=s=>{if(typeof s=="object"){let t="";for(let r in s)t+=r+_e(s[r]);return t}return s},ms=(s,t,r,i,c)=>{let o=_e(s),l=w[o]||(w[o]=(x=>{let f=0,g=11;for(;f<x.length;)g=101*g+x.charCodeAt(f++)>>>0;return"go"+g})(o));if(!w[l]){let x=o!==s?s:(f=>{let g,N,v=[{}];for(;g=ds.exec(f.replace(cs,""));)g[4]?v.shift():g[3]?(N=g[3].replace(we," ").trim(),v.unshift(v[0][N]=v[0][N]||{})):v[0][g[1]]=g[2].replace(we," ").trim();return v[0]})(s);w[l]=C(c?{["@keyframes "+l]:x}:x,r?"":"."+l)}let u=r&&w.g?w.g:null;return r&&(w.g=w[l]),((x,f,g,N)=>{N?f.data=f.data.replace(N,x):f.data.indexOf(x)===-1&&(f.data=g?x+f.data:f.data+x)})(w[l],t,i,u),l},hs=(s,t,r)=>s.reduce((i,c,o)=>{let l=t[o];if(l&&l.call){let u=l(r),x=u&&u.props&&u.props.className||/^go/.test(u)&&u;l=x?"."+x:u&&typeof u=="object"?u.props?"":C(u,""):u===!1?"":u}return i+c+(l??"")},"");function q(s){let t=this||{},r=s.call?s(t.p):s;return ms(r.unshift?r.raw?hs(r,[].slice.call(arguments,1),t.p):r.reduce((i,c)=>Object.assign(i,c&&c.call?c(t.p):c),{}):r,os(t.target),t.g,t.o,t.k)}let Ne,X,ee;q.bind({g:1});let _=q.bind({k:1});function us(s,t,r,i){C.p=t,Ne=s,X=r,ee=i}function k(s,t){let r=this||{};return function(){let i=arguments;function c(o,l){let u=Object.assign({},o),x=u.className||c.className;r.p=Object.assign({theme:X&&X()},u),r.o=/ *go\d+/.test(x),u.className=q.apply(r,i)+(x?" "+x:"");let f=s;return s[0]&&(f=u.as||s,delete u.as),ee&&f[0]&&ee(u),Ne(f,u)}return c}}var xs=s=>typeof s=="function",se=(s,t)=>xs(s)?s(t):s,ps=(()=>{let s=0;return()=>(++s).toString()})(),fs=(()=>{let s;return()=>{if(s===void 0&&typeof window<"u"){let t=matchMedia("(prefers-reduced-motion: reduce)");s=!t||t.matches}return s}})(),gs=20,Se="default",Ce=(s,t)=>{let{toastLimit:r}=s.settings;switch(t.type){case 0:return{...s,toasts:[t.toast,...s.toasts].slice(0,r)};case 1:return{...s,toasts:s.toasts.map(l=>l.id===t.toast.id?{...l,...t.toast}:l)};case 2:let{toast:i}=t;return Ce(s,{type:s.toasts.find(l=>l.id===i.id)?1:0,toast:i});case 3:let{toastId:c}=t;return{...s,toasts:s.toasts.map(l=>l.id===c||c===void 0?{...l,dismissed:!0,visible:!1}:l)};case 4:return t.toastId===void 0?{...s,toasts:[]}:{...s,toasts:s.toasts.filter(l=>l.id!==t.toastId)};case 5:return{...s,pausedAt:t.time};case 6:let o=t.time-(s.pausedAt||0);return{...s,pausedAt:void 0,toasts:s.toasts.map(l=>({...l,pauseDuration:l.pauseDuration+o}))}}},js=[],ys={toasts:[],pausedAt:void 0,settings:{toastLimit:gs}},O={},ke=(s,t=Se)=>{O[t]=Ce(O[t]||ys,s),js.forEach(([r,i])=>{r===t&&i(O[t])})},Pe=s=>Object.keys(O).forEach(t=>ke(s,t)),vs=s=>Object.keys(O).find(t=>O[t].toasts.some(r=>r.id===s)),ae=(s=Se)=>t=>{ke(t,s)},bs=(s,t="blank",r)=>({createdAt:Date.now(),visible:!0,dismissed:!1,type:t,ariaProps:{role:"status","aria-live":"polite"},message:s,pauseDuration:0,...r,id:(r==null?void 0:r.id)||ps()}),T=s=>(t,r)=>{let i=bs(t,s,r);return ae(i.toasterId||vs(i.id))({type:2,toast:i}),i.id},h=(s,t)=>T("blank")(s,t);h.error=T("error");h.success=T("success");h.loading=T("loading");h.custom=T("custom");h.dismiss=(s,t)=>{let r={type:3,toastId:s};t?ae(t)(r):Pe(r)};h.dismissAll=s=>h.dismiss(void 0,s);h.remove=(s,t)=>{let r={type:4,toastId:s};t?ae(t)(r):Pe(r)};h.removeAll=s=>h.remove(void 0,s);h.promise=(s,t,r)=>{let i=h.loading(t.loading,{...r,...r==null?void 0:r.loading});return typeof s=="function"&&(s=s()),s.then(c=>{let o=t.success?se(t.success,c):void 0;return o?h.success(o,{id:i,...r,...r==null?void 0:r.success}):h.dismiss(i),c}).catch(c=>{let o=t.error?se(t.error,c):void 0;o?h.error(o,{id:i,...r,...r==null?void 0:r.error}):h.dismiss(i)}),s};var ws=_`
from {
  transform: scale(0) rotate(45deg);
	opacity: 0;
}
to {
 transform: scale(1) rotate(45deg);
  opacity: 1;
}`,_s=_`
from {
  transform: scale(0);
  opacity: 0;
}
to {
  transform: scale(1);
  opacity: 1;
}`,Ns=_`
from {
  transform: scale(0) rotate(90deg);
	opacity: 0;
}
to {
  transform: scale(1) rotate(90deg);
	opacity: 1;
}`,Ss=k("div")`
  width: 20px;
  opacity: 0;
  height: 20px;
  border-radius: 10px;
  background: ${s=>s.primary||"#ff4b4b"};
  position: relative;
  transform: rotate(45deg);

  animation: ${ws} 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)
    forwards;
  animation-delay: 100ms;

  &:after,
  &:before {
    content: '';
    animation: ${_s} 0.15s ease-out forwards;
    animation-delay: 150ms;
    position: absolute;
    border-radius: 3px;
    opacity: 0;
    background: ${s=>s.secondary||"#fff"};
    bottom: 9px;
    left: 4px;
    height: 2px;
    width: 12px;
  }

  &:before {
    animation: ${Ns} 0.15s ease-out forwards;
    animation-delay: 180ms;
    transform: rotate(90deg);
  }
`,Cs=_`
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
`,ks=k("div")`
  width: 12px;
  height: 12px;
  box-sizing: border-box;
  border: 2px solid;
  border-radius: 100%;
  border-color: ${s=>s.secondary||"#e0e0e0"};
  border-right-color: ${s=>s.primary||"#616161"};
  animation: ${Cs} 1s linear infinite;
`,Ps=_`
from {
  transform: scale(0) rotate(45deg);
	opacity: 0;
}
to {
  transform: scale(1) rotate(45deg);
	opacity: 1;
}`,Fs=_`
0% {
	height: 0;
	width: 0;
	opacity: 0;
}
40% {
  height: 0;
	width: 6px;
	opacity: 1;
}
100% {
  opacity: 1;
  height: 10px;
}`,$s=k("div")`
  width: 20px;
  opacity: 0;
  height: 20px;
  border-radius: 10px;
  background: ${s=>s.primary||"#61d345"};
  position: relative;
  transform: rotate(45deg);

  animation: ${Ps} 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)
    forwards;
  animation-delay: 100ms;
  &:after {
    content: '';
    box-sizing: border-box;
    animation: ${Fs} 0.2s ease-out forwards;
    opacity: 0;
    animation-delay: 200ms;
    position: absolute;
    border-right: 2px solid;
    border-bottom: 2px solid;
    border-color: ${s=>s.secondary||"#fff"};
    bottom: 6px;
    left: 6px;
    height: 10px;
    width: 6px;
  }
`,Rs=k("div")`
  position: absolute;
`,Es=k("div")`
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
  min-width: 20px;
  min-height: 20px;
`,Ls=_`
from {
  transform: scale(0.6);
  opacity: 0.4;
}
to {
  transform: scale(1);
  opacity: 1;
}`,Ds=k("div")`
  position: relative;
  transform: scale(0.6);
  opacity: 0.4;
  min-width: 20px;
  animation: ${Ls} 0.3s 0.12s cubic-bezier(0.175, 0.885, 0.32, 1.275)
    forwards;
`,Os=({toast:s})=>{let{icon:t,type:r,iconTheme:i}=s;return t!==void 0?typeof t=="string"?d.createElement(Ds,null,t):t:r==="blank"?null:d.createElement(Es,null,d.createElement(ks,{...i}),r!=="loading"&&d.createElement(Rs,null,r==="error"?d.createElement(Ss,{...i}):d.createElement($s,{...i})))},zs=s=>`
0% {transform: translate3d(0,${s*-200}%,0) scale(.6); opacity:.5;}
100% {transform: translate3d(0,0,0) scale(1); opacity:1;}
`,As=s=>`
0% {transform: translate3d(0,0,-1px) scale(1); opacity:1;}
100% {transform: translate3d(0,${s*-150}%,-1px) scale(.6); opacity:0;}
`,Is="0%{opacity:0;} 100%{opacity:1;}",Ms="0%{opacity:1;} 100%{opacity:0;}",Ts=k("div")`
  display: flex;
  align-items: center;
  background: #fff;
  color: #363636;
  line-height: 1.3;
  will-change: transform;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1), 0 3px 3px rgba(0, 0, 0, 0.05);
  max-width: 350px;
  pointer-events: auto;
  padding: 8px 10px;
  border-radius: 8px;
`,Us=k("div")`
  display: flex;
  justify-content: center;
  margin: 4px 10px;
  color: inherit;
  flex: 1 1 auto;
  white-space: pre-line;
`,Vs=(s,t)=>{let r=s.includes("top")?1:-1,[i,c]=fs()?[Is,Ms]:[zs(r),As(r)];return{animation:t?`${_(i)} 0.35s cubic-bezier(.21,1.02,.73,1) forwards`:`${_(c)} 0.4s forwards cubic-bezier(.06,.71,.55,1)`}};d.memo(({toast:s,position:t,style:r,children:i})=>{let c=s.height?Vs(s.position||t||"top-center",s.visible):{opacity:0},o=d.createElement(Os,{toast:s}),l=d.createElement(Us,{...s.ariaProps},se(s.message,s));return d.createElement(Ts,{className:s.className,style:{...c,...r,...s.style}},typeof i=="function"?i({icon:o,message:l}):d.createElement(d.Fragment,null,o,l))});us(d.createElement);q`
  z-index: 9999;
  > * {
    pointer-events: auto;
  }
`;function aa({auth:s}){const{url:t}=Ve(),[r,i]=d.useState([]),[c,o]=d.useState({}),[l,u]=d.useState(!0),[x,f]=d.useState(""),[g,N]=d.useState(""),[v,Fe]=d.useState(""),[H,$e]=d.useState(""),[Z,Re]=d.useState(1),[te,Ee]=d.useState(1),[z,re]=d.useState([]),[le,Le]=d.useState(!1),[De,U]=d.useState(!1),[Oe,V]=d.useState(!1),[n,ze]=d.useState(null),[b,B]=d.useState({unread_only:!1,urgent_only:!1,needing_reply:!1,overdue:!1}),[m,j]=d.useState({from:"",sender_name:"",sender_email:"",sender_address:"",sender_phone:"",recipient:"",subject:"",content:"",priority:"normal",category:"general",received_date:new Date().toISOString().split("T")[0],due_date:"",need_reply:!1,need_forward:!1,confidential:!1,attachments:[],tags:[]}),[Q,ne]=d.useState(""),Ae=[{value:"",label:"All Status"},{value:"unread",label:"Unread"},{value:"read",label:"Read"},{value:"processed",label:"Processed"},{value:"archived",label:"Archived"},{value:"urgent",label:"Urgent"}],W=[{value:"",label:"All Priority"},{value:"low",label:"Low"},{value:"normal",label:"Normal"},{value:"high",label:"High"},{value:"urgent",label:"Urgent"}],ie=[{value:"",label:"All Categories"},{value:"general",label:"General"},{value:"official",label:"Official"},{value:"personal",label:"Personal"},{value:"legal",label:"Legal"},{value:"financial",label:"Financial"}],P=d.useCallback(async()=>{u(!0);try{const a=new URLSearchParams({page:Z,search:x,status:g,priority:v,category:H,...b}),p=await D.get(`/letters?${a}`);i(p.data.data.data),o(p.data.stats),Ee(p.data.data.last_page)}catch(a){h.error("Failed to fetch letters"),console.error("Error fetching letters:",a)}finally{u(!1)}},[Z,x,g,v,H,b]);d.useEffect(()=>{P()},[P]);const G=async(a,p)=>{try{await D.put(`/letters/${a}`,{status:p}),h.success("Letter status updated"),P()}catch{h.error("Failed to update status")}},J=async(a,p=null)=>{if(z.length===0){h.error("Please select letters first");return}try{await D.post("/letters/bulk-update",{letter_ids:z,action:a,value:p}),h.success("Bulk update completed"),re([]),P()}catch{h.error("Bulk update failed")}},Ie=async()=>{try{const a=await D.post("/letters/sync-emails");h.success(`Synced ${a.data.processed} emails`),P()}catch{h.error("Email sync failed")}},Me=async()=>{try{const a=new FormData;Object.keys(m).forEach(p=>{p==="attachments"?m.attachments.forEach(Y=>{a.append("attachments[]",Y)}):p==="tags"?a.append("tags",JSON.stringify(m.tags)):a.append(p,m[p])}),await D.post("/letters",a,{headers:{"Content-Type":"multipart/form-data"}}),h.success("Letter created successfully"),U(!1),j({from:"",sender_name:"",sender_email:"",sender_address:"",sender_phone:"",recipient:"",subject:"",content:"",priority:"normal",category:"general",received_date:new Date().toISOString().split("T")[0],due_date:"",need_reply:!1,need_forward:!1,confidential:!1,attachments:[],tags:[]}),P()}catch{h.error("Failed to create letter")}},Te=async()=>{if(!n||!Q.trim()){h.error("Please enter reply content");return}try{await D.post(`/letters/${n.id}/reply`,{reply_content:Q}),h.success("Reply sent successfully"),ne(""),V(!1),P()}catch{h.error("Failed to send reply")}},Ue=(a,p)=>{window.open(`/letters/${a}/attachment/${p}`,"_blank")},oe=a=>({unread:"danger",read:"primary",processed:"success",archived:"secondary",urgent:"warning"})[a]||"default",de=a=>({low:"secondary",normal:"default",high:"warning",urgent:"danger"})[a]||"default";return e.jsxs(e.Fragment,{children:[e.jsx(Be,{title:"Incoming Letters"}),e.jsxs("div",{className:"container mx-auto px-4 py-6",children:[e.jsxs("div",{className:"mb-6",children:[e.jsxs(Ke,{className:"mb-4",children:[e.jsx(ce,{href:"/dashboard",children:"Dashboard"}),e.jsx(ce,{children:"Incoming Letters"})]}),e.jsxs("div",{className:"flex justify-between items-center",children:[e.jsxs("div",{children:[e.jsx("h1",{className:"text-3xl font-bold text-gray-900 dark:text-white",children:"Incoming Letters"}),e.jsx("p",{className:"text-gray-600 dark:text-gray-400 mt-1",children:"Manage and track all incoming correspondence"})]}),e.jsxs("div",{className:"flex gap-3",children:[e.jsx(y,{color:"primary",variant:"flat",startContent:e.jsx(Ye,{className:"w-4 h-4"}),onPress:Ie,children:"Sync Emails"}),e.jsx(y,{color:"primary",startContent:e.jsx(Xe,{className:"w-4 h-4"}),onPress:()=>U(!0),children:"New Letter"})]})]})]}),e.jsxs("div",{className:"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6",children:[e.jsx(F,{children:e.jsx($,{className:"p-4",children:e.jsxs("div",{className:"flex items-center justify-between",children:[e.jsxs("div",{children:[e.jsx("p",{className:"text-sm text-gray-600 dark:text-gray-400",children:"Total Letters"}),e.jsx("p",{className:"text-2xl font-bold",children:c.total||0})]}),e.jsx(es,{className:"w-8 h-8 text-blue-500"})]})})}),e.jsx(F,{children:e.jsx($,{className:"p-4",children:e.jsxs("div",{className:"flex items-center justify-between",children:[e.jsxs("div",{children:[e.jsx("p",{className:"text-sm text-gray-600 dark:text-gray-400",children:"Unread"}),e.jsx("p",{className:"text-2xl font-bold text-red-500",children:c.unread||0})]}),e.jsx(ve,{className:"w-8 h-8 text-red-500"})]})})}),e.jsx(F,{children:e.jsx($,{className:"p-4",children:e.jsxs("div",{className:"flex items-center justify-between",children:[e.jsxs("div",{children:[e.jsx("p",{className:"text-sm text-gray-600 dark:text-gray-400",children:"Urgent"}),e.jsx("p",{className:"text-2xl font-bold text-orange-500",children:c.urgent||0})]}),e.jsx(ss,{className:"w-8 h-8 text-orange-500"})]})})}),e.jsx(F,{children:e.jsx($,{className:"p-4",children:e.jsxs("div",{className:"flex items-center justify-between",children:[e.jsxs("div",{children:[e.jsx("p",{className:"text-sm text-gray-600 dark:text-gray-400",children:"Needing Reply"}),e.jsx("p",{className:"text-2xl font-bold text-blue-500",children:c.needing_reply||0})]}),e.jsx(be,{className:"w-8 h-8 text-blue-500"})]})})})]}),e.jsx(F,{className:"mb-6",children:e.jsxs($,{children:[e.jsxs("div",{className:"flex flex-col lg:flex-row gap-4",children:[e.jsx("div",{className:"flex-1",children:e.jsx(S,{placeholder:"Search letters...",value:x,onChange:a=>f(a.target.value),startContent:e.jsx(as,{className:"w-4 h-4"})})}),e.jsxs("div",{className:"flex gap-2",children:[e.jsx(A,{placeholder:"Status",selectedKeys:[g],onSelectionChange:a=>N([...a][0]||""),className:"w-32",children:Ae.map(a=>e.jsx(I,{value:a.value,children:a.label},a.value))}),e.jsx(A,{placeholder:"Priority",selectedKeys:[v],onSelectionChange:a=>Fe([...a][0]||""),className:"w-32",children:W.map(a=>e.jsx(I,{value:a.value,children:a.label},a.value))}),e.jsx(A,{placeholder:"Category",selectedKeys:[H],onSelectionChange:a=>$e([...a][0]||""),className:"w-36",children:ie.map(a=>e.jsx(I,{value:a.value,children:a.label},a.value))}),e.jsx(y,{variant:"flat",onPress:()=>Le(!le),startContent:e.jsx(ts,{className:"w-4 h-4"}),children:"Filters"})]})]}),le&&e.jsx("div",{className:"mt-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg",children:e.jsxs("div",{className:"grid grid-cols-2 md:grid-cols-4 gap-4",children:[e.jsx(R,{isSelected:b.unread_only,onValueChange:a=>B({...b,unread_only:a}),children:"Unread Only"}),e.jsx(R,{isSelected:b.urgent_only,onValueChange:a=>B({...b,urgent_only:a}),children:"Urgent Only"}),e.jsx(R,{isSelected:b.needing_reply,onValueChange:a=>B({...b,needing_reply:a}),children:"Needing Reply"}),e.jsx(R,{isSelected:b.overdue,onValueChange:a=>B({...b,overdue:a}),children:"Overdue"})]})})]})}),z.length>0&&e.jsx(F,{className:"mb-4",children:e.jsx($,{children:e.jsxs("div",{className:"flex items-center justify-between",children:[e.jsxs("span",{className:"text-sm text-gray-600 dark:text-gray-400",children:[z.length," letters selected"]}),e.jsxs("div",{className:"flex gap-2",children:[e.jsx(y,{size:"sm",variant:"flat",onPress:()=>J("mark_read"),children:"Mark Read"}),e.jsx(y,{size:"sm",variant:"flat",onPress:()=>J("archive"),children:"Archive"}),e.jsxs(me,{children:[e.jsx(he,{children:e.jsx(y,{size:"sm",variant:"flat",children:"Change Priority"})}),e.jsx(ue,{children:W.slice(1).map(a=>e.jsx(K,{onPress:()=>J("change_priority",a.value),children:a.label},a.value))})]})]})]})})}),e.jsx(F,{children:e.jsxs($,{children:[l?e.jsx("div",{className:"flex justify-center py-8",children:e.jsx(qe,{size:"lg"})}):e.jsxs(He,{"aria-label":"Letters table",selectionMode:"multiple",selectedKeys:z,onSelectionChange:re,children:[e.jsxs(Ze,{children:[e.jsx(E,{children:"Subject"}),e.jsx(E,{children:"From"}),e.jsx(E,{children:"Status"}),e.jsx(E,{children:"Priority"}),e.jsx(E,{children:"Category"}),e.jsx(E,{children:"Received"}),e.jsx(E,{children:"Actions"})]}),e.jsx(Qe,{children:r.map(a=>e.jsxs(We,{children:[e.jsx(L,{children:e.jsxs("div",{className:"flex flex-col",children:[e.jsx("span",{className:"font-medium",children:a.subject}),e.jsx("span",{className:"text-sm text-gray-500",children:a.reference_number})]})}),e.jsx(L,{children:e.jsxs("div",{className:"flex flex-col",children:[e.jsx("span",{children:a.sender_name||a.from}),a.sender_email&&e.jsx("span",{className:"text-sm text-gray-500",children:a.sender_email})]})}),e.jsx(L,{children:e.jsx(M,{color:oe(a.status),size:"sm",variant:"flat",children:a.status})}),e.jsx(L,{children:e.jsx(M,{color:de(a.priority),size:"sm",variant:"flat",children:a.priority})}),e.jsx(L,{children:e.jsx(M,{size:"sm",variant:"bordered",children:a.category})}),e.jsx(L,{children:new Date(a.received_date).toLocaleDateString()}),e.jsx(L,{children:e.jsxs("div",{className:"flex gap-2",children:[e.jsx(Ge,{content:"View Details",children:e.jsx(y,{size:"sm",variant:"light",isIconOnly:!0,onPress:()=>{ze(a),V(!0)},children:e.jsx(ve,{className:"w-4 h-4"})})}),e.jsxs(me,{children:[e.jsx(he,{children:e.jsx(y,{size:"sm",variant:"light",isIconOnly:!0,children:e.jsx(rs,{className:"w-4 h-4"})})}),e.jsxs(ue,{children:[e.jsx(K,{onPress:()=>G(a.id,"read"),children:"Mark as Read"}),e.jsx(K,{onPress:()=>G(a.id,"processed"),children:"Mark as Processed"}),e.jsx(K,{onPress:()=>G(a.id,"archived"),children:"Archive"})]})]})]})})]},a.id))})]}),te>1&&e.jsx("div",{className:"flex justify-center mt-6",children:e.jsx(Je,{total:te,page:Z,onChange:Re})})]})}),e.jsx(xe,{isOpen:De,onClose:()=>U(!1),size:"4xl",scrollBehavior:"inside",children:e.jsxs(pe,{children:[e.jsx(fe,{children:"Create New Letter"}),e.jsxs(ge,{children:[e.jsxs("div",{className:"grid grid-cols-1 md:grid-cols-2 gap-4",children:[e.jsx(S,{label:"From",value:m.from,onChange:a=>j({...m,from:a.target.value}),required:!0}),e.jsx(S,{label:"Sender Name",value:m.sender_name,onChange:a=>j({...m,sender_name:a.target.value})}),e.jsx(S,{label:"Sender Email",type:"email",value:m.sender_email,onChange:a=>j({...m,sender_email:a.target.value})}),e.jsx(S,{label:"Sender Phone",value:m.sender_phone,onChange:a=>j({...m,sender_phone:a.target.value})}),e.jsx(S,{label:"Recipient",value:m.recipient,onChange:a=>j({...m,recipient:a.target.value})}),e.jsx(A,{label:"Priority",selectedKeys:[m.priority],onSelectionChange:a=>j({...m,priority:[...a][0]}),children:W.slice(1).map(a=>e.jsx(I,{value:a.value,children:a.label},a.value))}),e.jsx(A,{label:"Category",selectedKeys:[m.category],onSelectionChange:a=>j({...m,category:[...a][0]}),children:ie.slice(1).map(a=>e.jsx(I,{value:a.value,children:a.label},a.value))}),e.jsx(S,{label:"Received Date",type:"date",value:m.received_date,onChange:a=>j({...m,received_date:a.target.value}),required:!0})]}),e.jsx(S,{label:"Subject",value:m.subject,onChange:a=>j({...m,subject:a.target.value}),required:!0,className:"mt-4"}),e.jsx(je,{label:"Content",value:m.content,onChange:a=>j({...m,content:a.target.value}),rows:6,className:"mt-4"}),e.jsxs("div",{className:"flex gap-4 mt-4",children:[e.jsx(R,{isSelected:m.need_reply,onValueChange:a=>j({...m,need_reply:a}),children:"Needs Reply"}),e.jsx(R,{isSelected:m.need_forward,onValueChange:a=>j({...m,need_forward:a}),children:"Needs Forward"}),e.jsx(R,{isSelected:m.confidential,onValueChange:a=>j({...m,confidential:a}),children:"Confidential"})]})]}),e.jsxs(ye,{children:[e.jsx(y,{variant:"flat",onPress:()=>U(!1),children:"Cancel"}),e.jsx(y,{color:"primary",onPress:Me,children:"Create Letter"})]})]})}),e.jsx(xe,{isOpen:Oe,onClose:()=>V(!1),size:"5xl",scrollBehavior:"inside",children:e.jsxs(pe,{children:[e.jsx(fe,{children:e.jsxs("div",{className:"flex items-center gap-3",children:[e.jsx("span",{children:n==null?void 0:n.subject}),e.jsx(M,{color:oe(n==null?void 0:n.status),size:"sm",children:n==null?void 0:n.status}),e.jsx(M,{color:de(n==null?void 0:n.priority),size:"sm",children:n==null?void 0:n.priority})]})}),e.jsx(ge,{children:n&&e.jsxs("div",{className:"space-y-6",children:[e.jsxs("div",{className:"grid grid-cols-1 md:grid-cols-2 gap-4",children:[e.jsxs("div",{children:[e.jsx("h4",{className:"font-semibold mb-2",children:"Sender Information"}),e.jsxs("p",{children:[e.jsx("strong",{children:"From:"})," ",n.from]}),n.sender_name&&e.jsxs("p",{children:[e.jsx("strong",{children:"Name:"})," ",n.sender_name]}),n.sender_email&&e.jsxs("p",{children:[e.jsx("strong",{children:"Email:"})," ",n.sender_email]}),n.sender_phone&&e.jsxs("p",{children:[e.jsx("strong",{children:"Phone:"})," ",n.sender_phone]}),n.sender_address&&e.jsxs("p",{children:[e.jsx("strong",{children:"Address:"})," ",n.sender_address]})]}),e.jsxs("div",{children:[e.jsx("h4",{className:"font-semibold mb-2",children:"Letter Details"}),e.jsxs("p",{children:[e.jsx("strong",{children:"Reference:"})," ",n.reference_number]}),e.jsxs("p",{children:[e.jsx("strong",{children:"Category:"})," ",n.category]}),e.jsxs("p",{children:[e.jsx("strong",{children:"Received:"})," ",new Date(n.received_date).toLocaleString()]}),n.due_date&&e.jsxs("p",{children:[e.jsx("strong",{children:"Due:"})," ",new Date(n.due_date).toLocaleString()]})]})]}),e.jsxs("div",{children:[e.jsx("h4",{className:"font-semibold mb-2",children:"Content"}),e.jsx("div",{className:"bg-gray-50 dark:bg-gray-800 p-4 rounded-lg whitespace-pre-wrap",children:n.content})]}),n.attachments&&n.attachments.length>0&&e.jsxs("div",{children:[e.jsx("h4",{className:"font-semibold mb-2",children:"Attachments"}),e.jsx("div",{className:"space-y-2",children:n.attachments.map((a,p)=>e.jsxs("div",{className:"flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded",children:[e.jsx("span",{children:a.filename}),e.jsx(y,{size:"sm",variant:"flat",startContent:e.jsx(ls,{className:"w-4 h-4"}),onPress:()=>Ue(n.id,p),children:"Download"})]},p))})]}),n.need_reply&&!n.replied_status&&e.jsxs("div",{children:[e.jsx("h4",{className:"font-semibold mb-2",children:"Send Reply"}),e.jsx(je,{placeholder:"Enter your reply...",value:Q,onChange:a=>ne(a.target.value),rows:4}),e.jsx(y,{color:"primary",className:"mt-2",startContent:e.jsx(be,{className:"w-4 h-4"}),onPress:Te,children:"Send Reply"})]}),n.replied_status&&n.reply_content&&e.jsxs("div",{children:[e.jsx("h4",{className:"font-semibold mb-2",children:"Reply Sent"}),e.jsxs("div",{className:"bg-green-50 dark:bg-green-900 p-4 rounded-lg",children:[e.jsxs("p",{className:"text-sm text-green-700 dark:text-green-300",children:["Replied on ",new Date(n.reply_date).toLocaleString()]}),e.jsx("div",{className:"mt-2 whitespace-pre-wrap",children:n.reply_content})]})]})]})}),e.jsx(ye,{children:e.jsx(y,{variant:"flat",onPress:()=>V(!1),children:"Close"})})]})})]})]})}export{aa as default};
