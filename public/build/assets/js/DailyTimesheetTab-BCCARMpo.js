import{j as e,a as y,p as a,b as k,e as Q,u as Re,c as ce,h as Z,d as D,_ as Fe,$ as Le,a0 as Se,am as Ie,a1 as le,q as Ee,s as Ke}from"./vendor-radix-B3LaRtec.js";import{R as H,a as c}from"./vendor-inertia-BheeDqvO.js";import"./logRange-CBe7ByuT.js";import"./useObjectionsListState-255NJi1g.js";import{P as er}from"./VerifyEmail-wZNKb0on.js";import{m as re,c as te,d as Te,M as $e,g as oe,G as me,_ as de,L as rr,u as ue,ae as ge,av as we,R as ye,aw as tr,ax as be,ac as or,b as nr,ab as ve,$ as je,p as ir}from"./react-icons.esm-Da4MBIfi.js";import{L as A}from"./leaflet-GjjsV4zE.js";import{d as ke,M as ar,T as sr,n as fe,f as lr}from"./TileLayer-Dcq8pXyL.js";import"./DepartmentForm-BYcCrBWl.js";import"./ErrorBoundary-CBUcoRdt.js";import"./MonthlyCalendarTab-DE8kR6O2.js";import"./index.esm-MmCp14hd.js";import"./firebase-config-B7IbdUHj.js";import"./vendor-utils-Bd_1ICpc.js";const pe={voyager:{id:"voyager",name:"Voyager (Crisp Light)",icon:"Compass",url:"https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png",subdomains:"abcd",maxZoom:20,attribution:'&copy; <a href="https://carto.com/">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'},darkMatter:{id:"darkMatter",name:"Dark Matter (Midnight)",icon:"Moon",url:"https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png",subdomains:"abcd",maxZoom:20,attribution:'&copy; <a href="https://carto.com/">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'},positron:{id:"positron",name:"Positron (Minimal Light)",icon:"Sun",url:"https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png",subdomains:"abcd",maxZoom:20,attribution:'&copy; <a href="https://carto.com/">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'},satellite:{id:"satellite",name:"Satellite (Aerial HD)",icon:"Globe",url:"https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",subdomains:"",maxZoom:19,attribution:"Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community"},osm:{id:"osm",name:"OpenStreetMap Standard",icon:"Map",url:"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",subdomains:"abc",maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}},cr=[23.8103,90.4125],dr=12,pr=7,hr=19,xe=15,B={active:"#10b981",completed:"#3b82f6",punchin:"#10b981",punchout:"#ef4444"},ur=`
/* Living Radar Pulse Keyframes */
@keyframes radarPing {
    0% {
        transform: scale(0.7);
        opacity: 0.9;
    }
    50% {
        opacity: 0.5;
    }
    100% {
        transform: scale(2.2);
        opacity: 0;
    }
}

@keyframes beaconGlow {
    0%, 100% {
        box-shadow: 0 0 10px rgba(16, 185, 129, 0.6), 0 0 20px rgba(16, 185, 129, 0.3);
    }
    50% {
        box-shadow: 0 0 18px rgba(16, 185, 129, 0.9), 0 0 32px rgba(16, 185, 129, 0.5);
    }
}

@keyframes dashFlow {
    to {
        stroke-dashoffset: -24;
    }
}

/* Custom Marker Classes */
.living-marker-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.living-marker-wrapper:hover {
    transform: scale(1.15) translateY(-3px);
    z-index: 9999 !important;
}

.living-marker-radar-ring {
    position: absolute;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(16, 185, 129, 0.25);
    border: 1.5px solid rgba(16, 185, 129, 0.85);
    animation: radarPing 2.2s cubic-bezier(0, 0.2, 0.8, 1) infinite;
    pointer-events: none;
}

.living-marker-core {
    position: relative;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: 2.5px solid var(--color-surface, #ffffff);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: linear-gradient(135deg, var(--blue-9, #2563eb), var(--blue-11, #1e40af));
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    z-index: 2;
}

.living-marker-core.is-active {
    border-color: #10b981;
    background: linear-gradient(135deg, #10b981, #047857);
    animation: beaconGlow 2.5s ease-in-out infinite;
}

.living-marker-core.is-completed {
    border-color: var(--gray-8, #94a3b8);
    background: linear-gradient(135deg, var(--gray-9, #64748b), var(--gray-11, #334155));
}

.living-marker-core.is-punchin {
    border-color: #10b981;
    background: linear-gradient(135deg, #10b981, #059669);
}

.living-marker-core.is-punchout {
    border-color: #ef4444;
    background: linear-gradient(135deg, #ef4444, #b91c1c);
}

.living-marker-badge {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid var(--color-surface, #ffffff);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 8px;
    color: #ffffff;
    z-index: 3;
}

/* Radix Theme-Aware Leaflet Popup */
.leaflet-popup-content-wrapper {
    background: transparent !important;
    box-shadow: none !important;
    padding: 0 !important;
    border-radius: var(--radius-4, 12px) !important;
}

.leaflet-popup-content {
    margin: 0 !important;
    line-height: normal !important;
}

.leaflet-popup-tip {
    background: var(--color-panel-solid, var(--color-surface, #ffffff)) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}

/* Centroid Labels for Polygons */
.geofence-centroid-badge {
    background: var(--color-panel-solid, var(--color-surface, #ffffff));
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid var(--gray-a6);
    border-radius: 20px;
    padding: 3px 10px;
    color: var(--gray-12, #1e293b);
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
    display: flex;
    align-items: center;
    gap: 5px;
}

/* Trajectory flowing dashes */
.patrol-trajectory-path {
    stroke-dasharray: 8 6;
    animation: dashFlow 1.2s linear infinite;
}
`,Pe=H.memo(({stats:r,lastUpdateText:l,isPolling:d,secondsLeft:f})=>{const h=(r==null?void 0:r.total)||0,_=(r==null?void 0:r.checkedIn)??(r==null?void 0:r.active)??0,u=(r==null?void 0:r.completed)||0,s=h>0?Math.round(_/h*100):0;return e.jsx(y,{p:"3",style:{background:"linear-gradient(135deg, var(--gray-a2), var(--gray-a3))",borderBottom:"1px solid var(--gray-a4)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",wrap:"wrap",children:[e.jsxs(a,{align:"center",gap:"3",wrap:"wrap",children:[e.jsxs(a,{align:"center",gap:"2",px:"3",py:"2",style:{borderRadius:"var(--radius-3)",background:"var(--color-panel-solid, #ffffff)",border:"1px solid var(--gray-a4)",boxShadow:"0 1px 3px rgba(0,0,0,0.05)"},children:[e.jsx(a,{align:"center",justify:"center",style:{width:28,height:28,borderRadius:"50%",background:"var(--blue-a3)",color:"var(--blue-9)"},children:e.jsx(re,{style:{width:16,height:16}})}),e.jsxs(y,{children:[e.jsxs(a,{align:"baseline",gap:"1",children:[e.jsx(k,{size:"4",weight:"bold",style:{color:"var(--gray-12)"},children:h}),e.jsx(k,{size:"1",color:"gray",children:"Officers"})]}),e.jsx(k,{size:"1",color:"gray",style:{fontSize:10,display:"block",marginTop:-2},children:"Total Tracked"})]})]}),e.jsxs(a,{align:"center",gap:"2",px:"3",py:"2",style:{borderRadius:"var(--radius-3)",background:"var(--color-panel-solid, #ffffff)",border:"1px solid var(--green-a5)",boxShadow:"0 1px 3px rgba(0,0,0,0.05)"},children:[e.jsxs(a,{align:"center",justify:"center",style:{width:28,height:28,borderRadius:"50%",background:"var(--green-a3)",color:"var(--green-9)",position:"relative"},children:[e.jsx(te,{style:{width:16,height:16}}),_>0&&e.jsx("span",{style:{position:"absolute",top:1,right:1,width:8,height:8,borderRadius:"50%",background:B.active,border:"1.5px solid white"}})]}),e.jsxs(y,{children:[e.jsxs(a,{align:"baseline",gap:"1",children:[e.jsx(k,{size:"4",weight:"bold",style:{color:"var(--green-11)"},children:_}),e.jsxs(Q,{size:"1",color:"green",variant:"soft",radius:"full",children:[s,"%"]})]}),e.jsx(k,{size:"1",color:"gray",style:{fontSize:10,display:"block",marginTop:-2},children:"Active On-Duty"})]})]}),e.jsxs(a,{align:"center",gap:"2",px:"3",py:"2",style:{borderRadius:"var(--radius-3)",background:"var(--color-panel-solid, #ffffff)",border:"1px solid var(--gray-a4)",boxShadow:"0 1px 3px rgba(0,0,0,0.05)"},children:[e.jsx(a,{align:"center",justify:"center",style:{width:28,height:28,borderRadius:"50%",background:"var(--blue-a3)",color:"var(--blue-9)"},children:e.jsx(Te,{style:{width:16,height:16}})}),e.jsxs(y,{children:[e.jsxs(a,{align:"baseline",gap:"1",children:[e.jsx(k,{size:"4",weight:"bold",style:{color:"var(--blue-11)"},children:u}),e.jsx(k,{size:"1",color:"gray",children:"Completed"})]}),e.jsx(k,{size:"1",color:"gray",style:{fontSize:10,display:"block",marginTop:-2},children:"Finished Shifts"})]})]})]}),e.jsxs(a,{align:"center",gap:"2",children:[e.jsxs(a,{align:"center",gap:"2",px:"2",py:"1",style:{background:"var(--gray-a3)",borderRadius:"var(--radius-2)",border:"1px solid var(--gray-a4)"},children:[e.jsx(a,{align:"center",justify:"center",style:{width:8,height:8,borderRadius:"50%",background:d?B.active:"var(--gray-8)",boxShadow:d?"0 0 8px #10b981":"none"}}),e.jsx(k,{size:"1",color:"gray",children:d?`Live Sync (${f}s)`:"Polling Paused"})]}),l&&e.jsxs(k,{size:"1",color:"gray",style:{fontSize:11},children:["Updated: ",l]})]})]})})});Pe.displayName="MapStatsRibbon";const Me=H.memo(({searchQuery:r,onSearchChange:l,statusFilter:d,onStatusFilterChange:f,stats:h,currentTileId:_,onTileChange:u,layerVisibility:s,onToggleLayer:x,onFitBounds:v,onRefresh:t,isRefreshing:b,isDrawerOpen:i,onToggleDrawer:n,isFullscreen:g,onToggleFullscreen:o})=>{var j;return e.jsx(y,{style:{position:"absolute",top:14,left:14,right:14,zIndex:1e3,pointerEvents:"none"},children:e.jsxs(a,{gap:"2",align:"center",justify:"between",wrap:"wrap",style:{pointerEvents:"auto"},children:[e.jsxs(a,{align:"center",gap:"2",wrap:"wrap",p:"2",style:{background:"var(--color-surface)",backdropFilter:"blur(16px)",WebkitBackdropFilter:"blur(16px)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a5)",boxShadow:"var(--shadow-4, 0 8px 30px rgba(0, 0, 0, 0.12))"},children:[e.jsx(y,{style:{width:190},children:e.jsxs(Re,{size:"1",variant:"surface",placeholder:"Search officer / ID...",value:r,onChange:m=>l(m.target.value),children:[e.jsx(ce,{children:e.jsx($e,{style:{color:"var(--gray-9)"}})}),r&&e.jsx(ce,{children:e.jsx(Z,{size:"1",variant:"ghost",color:"gray",style:{cursor:"pointer"},onClick:()=>l(""),children:e.jsx(oe,{})})})]})}),e.jsxs(a,{align:"center",gap:"1",children:[e.jsxs(D,{size:"1",variant:d==="all"?"solid":"soft",color:"gray",onClick:()=>f("all"),style:{cursor:"pointer",fontWeight:600},children:["All (",h.total,")"]}),e.jsxs(D,{size:"1",variant:d==="active"?"solid":"soft",color:"green",onClick:()=>f("active"),style:{cursor:"pointer",fontWeight:600},children:["🟢 Active (",h.active,")"]}),e.jsxs(D,{size:"1",variant:d==="completed"?"solid":"soft",color:"blue",onClick:()=>f("completed"),style:{cursor:"pointer",fontWeight:600},children:["✅ Done (",h.completed,")"]})]})]}),e.jsxs(a,{align:"center",gap:"2",p:"2",style:{background:"var(--color-surface)",backdropFilter:"blur(16px)",WebkitBackdropFilter:"blur(16px)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a5)",boxShadow:"var(--shadow-4, 0 8px 30px rgba(0, 0, 0, 0.12))"},children:[e.jsxs(Fe,{children:[e.jsx(Le,{children:e.jsxs(D,{size:"1",variant:"soft",color:"gray",style:{cursor:"pointer",fontWeight:600},children:[e.jsx(me,{}),((j=pe[_])==null?void 0:j.name)||"Basemap"]})}),e.jsxs(Se,{variant:"solid",size:"1",children:[e.jsx(Ie,{children:"Select Map Tile"}),Object.values(pe).map(m=>e.jsxs(le,{onClick:()=>u(m.id),style:{cursor:"pointer",display:"flex",alignItems:"center",justifyContent:"between"},children:[e.jsx("span",{children:m.name}),_===m.id&&e.jsx(de,{style:{marginLeft:8}})]},m.id))]})]}),e.jsxs(Fe,{children:[e.jsx(Le,{children:e.jsxs(D,{size:"1",variant:"soft",color:"gray",style:{cursor:"pointer",fontWeight:600},children:[e.jsx(rr,{}),"Layers"]})}),e.jsxs(Se,{variant:"solid",size:"1",children:[e.jsx(Ie,{children:"Toggle Overlays"}),e.jsx(le,{onClick:()=>x("geofences"),style:{cursor:"pointer"},children:e.jsxs(a,{align:"center",gap:"2",children:[s.geofences?e.jsx(ue,{style:{color:"var(--purple-9)"}}):e.jsx(ge,{}),e.jsx("span",{children:"Geofence Zones"})]})}),e.jsx(le,{onClick:()=>x("waypoints"),style:{cursor:"pointer"},children:e.jsxs(a,{align:"center",gap:"2",children:[s.waypoints?e.jsx(ue,{style:{color:"var(--cyan-9)"}}):e.jsx(ge,{}),e.jsx("span",{children:"Route Waypoints"})]})}),e.jsx(le,{onClick:()=>x("trajectories"),style:{cursor:"pointer"},children:e.jsxs(a,{align:"center",gap:"2",children:[s.trajectories?e.jsx(ue,{style:{color:"var(--blue-9)"}}):e.jsx(ge,{}),e.jsx("span",{children:"Patrol Trajectories"})]})})]})]}),e.jsxs(D,{size:"1",variant:"soft",color:"gray",onClick:v,style:{cursor:"pointer"},title:"Fit all markers in view",children:[e.jsx(we,{}),"Fit All"]}),e.jsx(Z,{size:"1",variant:"soft",color:"blue",onClick:t,disabled:b,style:{cursor:"pointer"},title:"Refresh live coordinates",children:e.jsx(ye,{className:b?"animate-spin":""})}),e.jsxs(D,{size:"1",variant:i?"solid":"soft",color:i?"blue":"gray",onClick:n,style:{cursor:"pointer",fontWeight:600},children:[e.jsx(re,{}),"Roster (",h.total,")"]}),e.jsx(Z,{size:"1",variant:"soft",color:"gray",onClick:o,style:{cursor:"pointer"},title:g?"Exit Fullscreen":"Enter Fullscreen",children:g?e.jsx(tr,{}):e.jsx(be,{})})]})]})})});Me.displayName="MapHudControls";L.Control.Fullscreen=L.Control.extend({options:{position:"topleft",title:{false:"View Fullscreen",true:"Exit Fullscreen"}},onAdd:function(r){var l=L.DomUtil.create("div","leaflet-control-fullscreen leaflet-bar leaflet-control");return this.link=L.DomUtil.create("a","leaflet-control-fullscreen-button leaflet-bar-part",l),this.link.href="#",this._map=r,this._map.on("fullscreenchange",this._toggleTitle,this),this._toggleTitle(),L.DomEvent.on(this.link,"click",this._click,this),l},_click:function(r){L.DomEvent.stopPropagation(r),L.DomEvent.preventDefault(r),this._map.toggleFullscreen(this.options)},_toggleTitle:function(){this.link.title=this.options.title[this._map.isFullscreen()]}});L.Map.include({isFullscreen:function(){return this._isFullscreen||!1},toggleFullscreen:function(r){var l=this.getContainer();this.isFullscreen()?r&&r.pseudoFullscreen?this._disablePseudoFullscreen(l):document.exitFullscreen?document.exitFullscreen():document.mozCancelFullScreen?document.mozCancelFullScreen():document.webkitCancelFullScreen?document.webkitCancelFullScreen():document.msExitFullscreen?document.msExitFullscreen():this._disablePseudoFullscreen(l):r&&r.pseudoFullscreen?this._enablePseudoFullscreen(l):l.requestFullscreen?l.requestFullscreen():l.mozRequestFullScreen?l.mozRequestFullScreen():l.webkitRequestFullscreen?l.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT):l.msRequestFullscreen?l.msRequestFullscreen():this._enablePseudoFullscreen(l)},_enablePseudoFullscreen:function(r){L.DomUtil.addClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!0),this.fire("fullscreenchange")},_disablePseudoFullscreen:function(r){L.DomUtil.removeClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!1),this.fire("fullscreenchange")},_setFullscreen:function(r){this._isFullscreen=r;var l=this.getContainer();r?L.DomUtil.addClass(l,"leaflet-fullscreen-on"):L.DomUtil.removeClass(l,"leaflet-fullscreen-on"),this.invalidateSize()},_onFullscreenChange:function(r){var l=document.fullscreenElement||document.mozFullScreenElement||document.webkitFullscreenElement||document.msFullscreenElement;l===this.getContainer()&&!this._isFullscreen?(this._setFullscreen(!0),this.fire("fullscreenchange")):l!==this.getContainer()&&this._isFullscreen&&(this._setFullscreen(!1),this.fire("fullscreenchange"))}});L.Map.mergeOptions({fullscreenControl:!1});L.Map.addInitHook(function(){this.options.fullscreenControl&&(this.fullscreenControl=new L.Control.Fullscreen(this.options.fullscreenControl),this.addControl(this.fullscreenControl));var r;if("onfullscreenchange"in document?r="fullscreenchange":"onmozfullscreenchange"in document?r="mozfullscreenchange":"onwebkitfullscreenchange"in document?r="webkitfullscreenchange":"onmsfullscreenchange"in document&&(r="MSFullscreenChange"),r){var l=L.bind(this._onFullscreenChange,this);this.whenReady(function(){L.DomEvent.on(document,r,l)}),this.on("unload",function(){L.DomEvent.off(document,r,l)})}});L.control.fullscreen=function(r){return new L.Control.Fullscreen(r)};const Ae=H.memo(({fitBoundsTrigger:r,users:l,flyToCoords:d,attendanceTypeConfigs:f})=>{const h=ke();return c.useEffect(()=>{if(!h||r===0)return;const _=A.latLngBounds([]);(l||[]).forEach(u=>{const s=u.punchin_location||u.location,x=u.punchout_location;s&&s.lat&&s.lng&&_.extend([parseFloat(s.lat),parseFloat(s.lng)]),x&&x.lat&&x.lng&&_.extend([parseFloat(x.lat),parseFloat(x.lng)])}),(f||[]).forEach(u=>{var s,x;(s=u.config)!=null&&s.polygon&&u.config.polygon.forEach(v=>{v.lat&&v.lng&&_.extend([parseFloat(v.lat),parseFloat(v.lng)])}),(x=u.config)!=null&&x.waypoints&&u.config.waypoints.forEach(v=>{v.lat&&v.lng&&_.extend([parseFloat(v.lat),parseFloat(v.lng)])})}),_.isValid()&&h.fitBounds(_,{padding:[60,60],maxZoom:15,animate:!0,duration:.8})},[h,r,l,f]),c.useEffect(()=>{!h||!d||h.flyTo(d,16,{animate:!0,duration:1.2})},[h,d]),null});Ae.displayName="MapController";const Oe=H.memo(({currentTileId:r="voyager",users:l=[],attendanceTypeConfigs:d=[],fitBoundsTrigger:f=0,flyToCoords:h=null,children:_})=>{const u=pe[r]||pe.voyager;return c.useEffect(()=>{const s="team-map-injected-styles";if(!document.getElementById(s)){const x=document.createElement("style");x.id=s,x.innerHTML=ur,document.head.appendChild(x)}},[]),e.jsx("div",{style:{position:"relative",width:"100%",height:"100%"},children:e.jsxs(ar,{center:cr,zoom:dr,minZoom:pr,maxZoom:hr,style:{width:"100%",height:"100%",background:"#0f172a"},scrollWheelZoom:!0,doubleClickZoom:!0,dragging:!0,touchZoom:!0,zoomControl:!1,attributionControl:!1,children:[e.jsx(sr,{url:u.url,subdomains:u.subdomains,maxZoom:u.maxZoom,attribution:u.attribution},u.id),e.jsx(Ae,{fitBoundsTrigger:f,users:l,flyToCoords:h,attendanceTypeConfigs:d}),_]})})});Oe.displayName="MapContainerView";const Ne=H.memo(({attendanceTypeConfigs:r=[],users:l=[],layerVisibility:d={geofences:!0,waypoints:!0,trajectories:!0}})=>{const f=ke(),h=c.useRef([]);return c.useEffect(()=>{if(!f)return;let _=!1;if(h.current.forEach(s=>{try{f.removeLayer(s)}catch{}}),h.current=[],!r||r.length===0)return;const u=["#0284c7","#10b981","#f59e0b","#8b5cf6","#ec4899","#06b6d4","#14b8a6","#f97316"];return r.forEach((s,x)=>{var j,m,S,P;const{base_slug:v,slug:t,config:b,name:i}=s,n=u[x%u.length];if(!b)return;if((v==="geo_polygon"||(t==null?void 0:t.includes("polygon"))||(t==null?void 0:t.includes("geofence"))||!!((j=b.polygon)!=null&&j.length||(m=b.polygons)!=null&&m.length))&&d.geofences!==!1){const I=b.polygon||[],R=b.polygons||[],F=(C,z)=>{const p=(C||[]).map(fe).filter(Boolean);if(p.length<3)return;const E=p.map(M=>[M.lat,M.lng]),T=A.polygon(E,{color:n,fillColor:n,fillOpacity:.16,weight:2.5,opacity:.85,dashArray:"6, 6"}).addTo(f),O=T.getBounds(),U=O.getCenter(),W=l.filter(M=>{const G=fe(M.punchin_location||M.punchout_location||M.location);return G?O.contains(A.latLng(G.lat,G.lng)):!1}).length,q=`
                        <div class="geofence-centroid-badge" style="border-color: ${n}88;">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${n};"></span>
                            <span>${z||i}</span>
                            ${W>0?`<span style="background:${n}; color:white; border-radius:10px; padding:0 6px; font-size:10px;">${W} Officers</span>`:""}
                        </div>
                    `,N=A.marker(U,{icon:A.divIcon({html:q,className:"geofence-label-marker",iconSize:[120,26],iconAnchor:[60,13]}),interactive:!1}).addTo(f);T.bindPopup(`
                        <div style="font-family: inherit; padding: 6px; min-width: 140px; color: var(--gray-12, #1e293b);">
                            <div style="font-weight: 700; color: ${n}; font-size: 13px; margin-bottom: 2px;">
                                🛡️ ${z||i}
                            </div>
                            <div style="font-size: 11px; color: var(--gray-10, #64748b);">Geofence Zone Perimeter</div>
                            <div style="font-size: 11px; margin-top: 4px; font-weight: 600;">
                                Verified Officers: <span style="color:${n};">${W}</span>
                            </div>
                        </div>
                    `),h.current.push(T),h.current.push(N)};I.length>=3&&F(I,i),R.forEach((C,z)=>{const p=C.points||C.coordinates||C;Array.isArray(p)&&p.length>=3&&F(p,C.name||`${i} Zone ${z+1}`)})}if((v==="route_waypoint"||(t==null?void 0:t.includes("route"))||(t==null?void 0:t.includes("waypoint"))||(t==null?void 0:t.includes("patrol"))||!!((S=b.waypoints)!=null&&S.length||(P=b.routes)!=null&&P.length))&&d.waypoints!==!1){const I=b.waypoints||[],R=b.routes||[],F=(z,p,E)=>{const T=(z||[]).map(fe).filter(Boolean);if(T.length===0)return;const O=T.map(N=>[N.lat,N.lng]);let U=null,W=null,q=null;O.length>=2&&(U=A.polyline(O,{color:n,weight:12,opacity:.2,lineCap:"round",lineJoin:"round"}).addTo(f),W=A.polyline(O,{color:n,weight:4.5,opacity:.85,lineCap:"round",lineJoin:"round"}).addTo(f),q=A.polyline(O,{color:"#ffffff",weight:2,opacity:.9,dashArray:"8, 8",className:"patrol-trajectory-path",lineCap:"round",lineJoin:"round"}).addTo(f),h.current.push(U),h.current.push(W),h.current.push(q),lr(T).then(N=>{if(_||!N||!N.latLngs)return;const M=N.latLngs;U&&f.hasLayer(U)&&U.setLatLngs(M),W&&f.hasLayer(W)&&W.setLatLngs(M),q&&f.hasLayer(q)&&q.setLatLngs(M)}).catch(N=>{console.warn("Road snapping fallback active:",N)})),T.forEach((N,M)=>{const G=M===0,ne=M===T.length-1&&T.length>1,ee=G?"#10b981":ne?"#ef4444":n;if(E&&E>0){const he=A.circle([N.lat,N.lng],{radius:E,color:ee,fillColor:ee,fillOpacity:.08,weight:1.5,dashArray:"4, 4"}).addTo(f);h.current.push(he)}const ie=`
                            <div style="
                                width: 28px;
                                height: 28px;
                                border-radius: 50%;
                                background: ${ee};
                                border: 2.5px solid var(--color-surface, #ffffff);
                                box-shadow: 0 4px 10px rgba(0,0,0,0.4);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: white;
                                font-weight: 800;
                                font-size: 11px;
                            ">
                                ${T.length===1?"📍":G?"S":ne?"E":M+1}
                            </div>
                        `,X=A.marker([N.lat,N.lng],{icon:A.divIcon({html:ie,className:"waypoint-marker",iconSize:[28,28],iconAnchor:[14,14]})}).addTo(f);X.bindPopup(`
                            <div style="font-family: inherit; padding: 4px; color: var(--gray-12, #1e293b);">
                                <strong style="color: ${n};">${p||i}</strong><br>
                                <span style="font-size: 11px; color: var(--gray-10, #64748b);">
                                    ${T.length===1?"🎯 Patrol Checkpoint":G?"🚀 Expressway Route Start":ne?"🏁 Expressway Route End":`Waypoint #${M+1}`}
                                </span>
                                ${E?`<div style="font-size: 10px; color: var(--gray-9); margin-top: 2px;">Highway Attendance Tolerance: ${E}m</div>`:""}
                            </div>
                        `),h.current.push(X)})},C=b.tolerance||150;I.length>0&&F(I,i,C),R.forEach((z,p)=>{const E=z.waypoints||z.points||z.coords;Array.isArray(E)&&E.length>0&&F(E,z.name||`${i} Route ${p+1}`,z.tolerance||C)})}}),()=>{_=!0,h.current.forEach(s=>{try{f.removeLayer(s)}catch{}}),h.current=[]}},[f,r,l,d]),null});Ne.displayName="MapGeofenceLayers";const Ue=H.memo(({users:r=[],selectedUserId:l,onSelectOfficer:d,onOpenTelemetry:f,onOpenPhoto:h,layerVisibility:_={trajectories:!0}})=>{const u=ke(),s=c.useRef([]),x=c.useRef([]),v=c.useCallback(i=>{if(!i)return null;if(typeof i=="object"&&i.lat&&i.lng){const n=parseFloat(i.lat),g=parseFloat(i.lng);if(!isNaN(n)&&!isNaN(g))return{lat:n,lng:g}}if(typeof i=="string")try{const n=JSON.parse(i);if(n.lat&&n.lng){const g=parseFloat(n.lat),o=parseFloat(n.lng);if(!isNaN(g)&&!isNaN(o))return{lat:g,lng:o}}}catch{const g=i.split(",");if(g.length>=2){const o=parseFloat(g[0].trim()),j=parseFloat(g[1].trim());if(!isNaN(o)&&!isNaN(j))return{lat:o,lng:j}}}return null},[]),t=c.useCallback((i,n="active",g=!1)=>{var z,p;const o=i.status==="active"||n==="punchin",j=n==="punchout",m=i.profile_image_url,S=((p=(z=i.name)==null?void 0:z.charAt(0))==null?void 0:p.toUpperCase())||"?",P=o?'<div class="living-marker-radar-ring"></div>':"",I=`living-marker-core ${o?"is-active":j?"is-punchout":"is-completed"}`,R=o?B.active:j?B.punchout:B.completed,F=o?"▶":j?"◼":"✓",C=`
            <div class="living-marker-wrapper" style="${g?"transform: scale(1.25); z-index: 9999;":""}">
                ${P}
                <div class="${I}" style="${g?"border-color: #38bdf8; box-shadow: 0 0 16px #38bdf8;":""}">
                    ${m?`<img src="${m}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerText='${S}';" />`:S}
                </div>
                <div class="living-marker-badge" style="background: ${R};">
                    ${F}
                </div>
            </div>
        `;return A.divIcon({html:C,className:"custom-living-marker",iconSize:[44,44],iconAnchor:[22,22],popupAnchor:[0,-22]})},[]),b=c.useCallback((i,n,g="current")=>{var F,C;const o=i.status==="active",j=(n==null?void 0:n.punchin_time)||i.punchin_time||"--",m=(n==null?void 0:n.punchout_time)||i.punchout_time,S=(n==null?void 0:n.punchin_photo_url)||i.punchin_photo_url,P=(n==null?void 0:n.punchout_photo_url)||i.punchout_photo_url,I=g==="punchout"&&P||S,R=I?`
            <div style="margin: 8px 0; border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.15); max-height: 90px; cursor: pointer; position: relative;"
                 onclick="window.__openMapPhoto && window.__openMapPhoto('${I}', '${i.name.replace(/'/g,"\\'")}', '${j}', '${g}')">
                <img src="${I}" style="width: 100%; height: 85px; object-fit: cover;" alt="Selfie" />
                <div style="position: absolute; bottom: 2px; right: 4px; background: rgba(0,0,0,0.65); padding: 1px 6px; border-radius: 4px; font-size: 9px; color: #fff;">
                    🔍 Zoom
                </div>
            </div>
        `:"";return`
            <div style="
                min-width: 210px;
                max-width: 250px;
                background: var(--color-panel-solid, var(--color-surface, #ffffff));
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border: 1px solid var(--gray-a6, #cbd5e1);
                border-radius: 12px;
                padding: 12px;
                color: var(--gray-12, #1e293b);
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.18);
                font-family: inherit;
            ">
                <!-- Header -->
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="
                        width: 32px;
                        height: 32px;
                        border-radius: 50%;
                        overflow: hidden;
                        border: 2px solid ${o?B.active:"var(--gray-8, #94a3b8)"};
                        background: var(--gray-a4, #e2e8f0);
                        color: var(--gray-12, #1e293b);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: bold;
                        font-size: 12px;
                        flex-shrink: 0;
                    ">
                        ${i.profile_image_url?`<img src="${i.profile_image_url}" style="width:100%; height:100%; object-fit:cover;" />`:((C=(F=i.name)==null?void 0:F.charAt(0))==null?void 0:C.toUpperCase())||"?"}
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 700; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--gray-12, #0f172a);">
                            ${i.name}
                        </div>
                        <div style="font-size: 10px; color: var(--gray-10, #64748b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            ${i.designation||"Officer"}
                        </div>
                    </div>
                    <div style="
                        font-size: 9px;
                        font-weight: 600;
                        padding: 2px 6px;
                        border-radius: 10px;
                        background: ${o?"var(--green-a3, rgba(16, 185, 129, 0.15))":"var(--blue-a3, rgba(59, 130, 246, 0.15))"};
                        color: ${o?"var(--green-11, #059669)":"var(--blue-11, #2563eb)"};
                        border: 1px solid ${o?"var(--green-a5, rgba(16, 185, 129, 0.4))":"var(--blue-a5, rgba(59, 130, 246, 0.4))"};
                    ">
                        ${o?"🟢 ACTIVE":"✅ DONE"}
                    </div>
                </div>

                <!-- Timestamps -->
                <div style="background: var(--gray-a3, rgba(0, 0, 0, 0.04)); border-radius: 6px; padding: 6px; margin-bottom: 6px; font-size: 11px; border: 1px solid var(--gray-a4);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px;">
                        <span style="color: var(--gray-10, #64748b);">Check In:</span>
                        <span style="font-weight: 600; color: var(--green-11, #059669);">${j}</span>
                    </div>
                    ${m?`
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="color: var(--gray-10, #64748b);">Check Out:</span>
                        <span style="font-weight: 600; color: var(--red-11, #dc2626);">${m}</span>
                    </div>`:""}
                </div>

                ${R}

                <!-- Inspect Button -->
                <button
                    onclick="window.__inspectOfficer && window.__inspectOfficer(${i.user_id})"
                    style="
                        width: 100%;
                        background: linear-gradient(135deg, #0284c7, #2563eb);
                        border: none;
                        border-radius: 6px;
                        color: #ffffff;
                        padding: 6px 10px;
                        font-size: 11px;
                        font-weight: 600;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 4px;
                        margin-top: 6px;
                        box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
                    "
                >
                    🔍 Inspect Telemetry
                </button>
            </div>
        `},[]);return c.useEffect(()=>(window.__inspectOfficer=i=>{const n=r.find(g=>g.user_id===i);n&&f&&f(n)},window.__openMapPhoto=(i,n,g,o)=>{h&&h({url:i,officerName:n,timestamp:g,type:o})},()=>{delete window.__inspectOfficer,delete window.__openMapPhoto}),[r,f,h]),c.useEffect(()=>{if(!u||(s.current.forEach(o=>{try{u.removeLayer(o)}catch{}}),s.current=[],x.current.forEach(o=>{try{u.removeLayer(o)}catch{}}),x.current=[],!r||r.length===0))return;const i=[],n=15e-5,g=o=>{let j=o.lat,m=o.lng;const S=i.filter(P=>Math.abs(P.lat-j)<n&&Math.abs(P.lng-m)<n).length;if(S>0){const P=S*1.25,I=18e-5*Math.sqrt(S);j+=Math.cos(P)*I,m+=Math.sin(P)*I}return i.push({lat:j,lng:m}),{lat:j,lng:m}};return r.forEach(o=>{const j=o.cycles&&o.cycles.length>0?o.cycles:null,m=l===o.user_id;if(j)j.forEach((S,P)=>{const I=v(S.punchin_location),R=v(S.punchout_location);if(I&&R&&S.is_complete){const F=g(I),C=g(R),z=A.marker([F.lat,F.lng],{icon:t(o,"punchin",m),zIndexOffset:m?1e3:100}).addTo(u);z.bindPopup(b(o,S,"punchin")),z.on("click",()=>d&&d(o)),s.current.push(z);const p=A.marker([C.lat,C.lng],{icon:t(o,"punchout",m),zIndexOffset:m?1e3:90}).addTo(u);if(p.bindPopup(b(o,S,"punchout")),p.on("click",()=>d&&d(o)),s.current.push(p),_.trajectories){const E=A.polyline([[F.lat,F.lng],[C.lat,C.lng]],{color:"#06b6d4",weight:3.5,opacity:.8,className:"patrol-trajectory-path"}).addTo(u);x.current.push(E)}}else{const F=I||R;if(F){const C=g(F),z=A.marker([C.lat,C.lng],{icon:t(o,o.status,m),zIndexOffset:m?1e3:150}).addTo(u);z.bindPopup(b(o,S,"punchin")),z.on("click",()=>d&&d(o)),s.current.push(z)}}});else{const S=v(o.punchin_location||o.location),P=v(o.punchout_location),I=S||P;if(I){const R=g(I),F=A.marker([R.lat,R.lng],{icon:t(o,o.status,m),zIndexOffset:m?1e3:100}).addTo(u);if(F.bindPopup(b(o,o,o.status)),F.on("click",()=>d&&d(o)),s.current.push(F),S&&P&&o.punchout_time&&_.trajectories){const C=g(P),z=A.polyline([[R.lat,R.lng],[C.lat,C.lng]],{color:"#06b6d4",weight:3.5,opacity:.8,className:"patrol-trajectory-path"}).addTo(u);x.current.push(z)}}}}),()=>{s.current.forEach(o=>{try{u.removeLayer(o)}catch{}}),s.current=[],x.current.forEach(o=>{try{u.removeLayer(o)}catch{}}),x.current=[]}},[u,r,l,_,t,b,v,d]),null});Ue.displayName="MapLivingMarkers";const We=H.memo(({isOpen:r,onClose:l,users:d=[],selectedUserId:f,onSelectOfficer:h,onOpenTelemetry:_,onOpenPhoto:u})=>{const[s,x]=c.useState(""),v=c.useMemo(()=>{if(!s)return d;const t=s.toLowerCase();return d.filter(b=>{var i,n,g,o;return((i=b.name)==null?void 0:i.toLowerCase().includes(t))||((n=b.employee_id)==null?void 0:n.toLowerCase().includes(t))||((g=b.designation)==null?void 0:g.toLowerCase().includes(t))||((o=b.department)==null?void 0:o.toLowerCase().includes(t))})},[d,s]);return r?e.jsxs(y,{style:{position:"absolute",top:74,right:14,bottom:14,width:320,maxWidth:"calc(100vw - 28px)",background:"var(--color-panel-solid, var(--color-surface, #ffffff))",backdropFilter:"blur(20px)",WebkitBackdropFilter:"blur(20px)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a5)",boxShadow:"var(--shadow-5, 0 20px 40px rgba(0, 0, 0, 0.25))",zIndex:1e3,display:"flex",flexDirection:"column",overflow:"hidden",animation:"slideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1)"},children:[e.jsxs(y,{p:"3",style:{borderBottom:"1px solid var(--gray-a4)",background:"var(--gray-a2)"},children:[e.jsxs(a,{justify:"between",align:"center",mb:"2",children:[e.jsxs(a,{align:"center",gap:"2",children:[e.jsx(re,{style:{color:"var(--blue-9)",width:16,height:16}}),e.jsx(k,{size:"2",weight:"bold",style:{color:"var(--gray-12)"},children:"On-Duty Team Roster"}),e.jsx(Q,{size:"1",color:"blue",variant:"solid",radius:"full",children:d.length})]}),e.jsx(Z,{size:"1",variant:"ghost",color:"gray",style:{cursor:"pointer"},onClick:l,children:e.jsx(oe,{})})]}),e.jsxs(Re,{size:"1",variant:"surface",placeholder:"Filter roster...",value:s,onChange:t=>x(t.target.value),children:[e.jsx(ce,{children:e.jsx($e,{style:{color:"var(--gray-9)"}})}),s&&e.jsx(ce,{children:e.jsx(Z,{size:"1",variant:"ghost",color:"gray",onClick:()=>x(""),children:e.jsx(oe,{})})})]})]}),e.jsx(y,{p:"2",style:{flex:1,overflowY:"auto",display:"flex",flexDirection:"column",gap:6},children:v.length===0?e.jsxs(a,{align:"center",justify:"center",direction:"column",gap:"2",p:"4",style:{height:"100%"},children:[e.jsx(re,{style:{color:"var(--gray-8)",width:28,height:28}}),e.jsx(k,{size:"1",color:"gray",children:"No matching officers found"})]}):v.map(t=>{var o,j;const b=f===t.user_id,i=t.status==="active",n=t.punchin_time||"--",g=t.punchout_time;return t.punchin_photo_url||t.profile_image_url,e.jsxs(y,{p:"2",style:{borderRadius:"var(--radius-3)",background:b?"var(--blue-a3)":"var(--gray-a2)",border:b?"1px solid var(--blue-a7)":"1px solid var(--gray-a4)",transition:"all 0.15s ease",cursor:"pointer"},onClick:()=>h(t),children:[e.jsxs(a,{justify:"between",align:"start",gap:"2",children:[e.jsxs(a,{align:"center",gap:"2",style:{minWidth:0,flex:1},children:[e.jsxs(y,{style:{position:"relative",width:34,height:34,borderRadius:"50%",overflow:"hidden",border:`2px solid ${i?B.active:"var(--gray-7)"}`,background:"var(--gray-a4)",display:"flex",alignItems:"center",justifyContent:"center",color:"white",fontWeight:"bold",fontSize:12,flexShrink:0},children:[t.profile_image_url?e.jsx("img",{src:t.profile_image_url,alt:t.name,style:{width:"100%",height:"100%",objectFit:"cover"}}):((j=(o=t.name)==null?void 0:o.charAt(0))==null?void 0:j.toUpperCase())||"?",e.jsx("span",{style:{position:"absolute",bottom:0,right:0,width:8,height:8,borderRadius:"50%",background:i?B.active:B.completed,border:"1px solid var(--color-surface)"}})]}),e.jsxs(y,{style:{minWidth:0,flex:1},children:[e.jsx(k,{size:"2",weight:"bold",style:{color:"var(--gray-12)",whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis",display:"block"},children:t.name}),e.jsxs(k,{size:"1",color:"gray",style:{whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis",display:"block"},children:[t.designation||"Staff"," ",t.employee_id?`• ${t.employee_id}`:""]})]})]}),e.jsx(Z,{size:"1",variant:"soft",color:b?"blue":"gray",onClick:m=>{m.stopPropagation(),h(t)},title:"Fly to marker on map",children:e.jsx(we,{})})]}),e.jsxs(a,{justify:"between",align:"center",mt:"2",pt:"2",style:{borderTop:"1px solid var(--gray-a4)"},children:[e.jsxs(a,{align:"center",gap:"1",children:[e.jsx(te,{style:{color:"var(--green-9)",width:12,height:12}}),e.jsxs(k,{size:"1",weight:"medium",style:{color:"var(--green-11)"},children:["In: ",n]}),g&&e.jsxs(k,{size:"1",color:"gray",ml:"1",children:["• Out: ",g]})]}),e.jsxs(a,{align:"center",gap:"1",children:[t.punchin_photo_url&&e.jsx(Z,{size:"1",variant:"ghost",color:"blue",onClick:m=>{m.stopPropagation(),u({url:t.punchin_photo_url,title:`Check-In Verification: ${t.name}`,timestamp:n,officerName:t.name,employeeId:t.employee_id,designation:t.designation,location:t.punchin_location})},title:"View Check-In Selfie",children:e.jsx(or,{})}),e.jsxs(D,{size:"1",variant:"surface",color:"gray",onClick:m=>{m.stopPropagation(),_(t)},style:{cursor:"pointer",height:22,fontSize:10,padding:"0 6px"},children:["Telemetry",e.jsx(nr,{})]})]})]})]},t.user_id)})})]}):null});We.displayName="MapTeamRosterDrawer";const De=H.memo(({officer:r,selectedDate:l,onClose:d,onOpenPhoto:f,onFocusMap:h})=>{var z;const[_,u]=c.useState(null);if(!r)return null;const{name:s,employee_id:x,designation:v,department:t,profile_image_url:b,status:i,cycles:n=[],punchin_time:g,punchout_time:o,punchin_location:j,punchout_location:m,punchin_photo_url:S,punchout_photo_url:P,attendance_type:I}=r,R=i==="active",F=(p,E)=>{p&&(navigator.clipboard.writeText(p),u(E),setTimeout(()=>u(null),2e3))},C=n&&n.length>0?n:[{attendance_id:"default",punchin_time:g,punchout_time:o,punchin_location:j,punchout_location:m,punchin_photo_url:S,punchout_photo_url:P,is_complete:!!o}];return e.jsx(y,{style:{position:"fixed",inset:0,background:"rgba(5, 10, 20, 0.75)",backdropFilter:"blur(8px)",WebkitBackdropFilter:"blur(8px)",zIndex:99990,display:"flex",alignItems:"center",justifyContent:"center",padding:16,animation:"fadeIn 0.2s ease-out"},onClick:d,children:e.jsxs(y,{style:{width:"100%",maxWidth:580,maxHeight:"90vh",background:"var(--color-panel-solid, #1e293b)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a6)",boxShadow:"0 25px 60px -15px rgba(0,0,0,0.5)",display:"flex",flexDirection:"column",overflow:"hidden"},onClick:p=>p.stopPropagation(),children:[e.jsx(y,{p:"4",style:{background:"linear-gradient(135deg, var(--gray-a3), var(--gray-a4))",borderBottom:"1px solid var(--gray-a5)"},children:e.jsxs(a,{justify:"between",align:"start",children:[e.jsxs(a,{align:"center",gap:"3",children:[e.jsx(y,{style:{width:52,height:52,borderRadius:"50%",overflow:"hidden",border:`3px solid ${R?B.active:"var(--gray-a7)"}`,background:"var(--gray-a4)",display:"flex",alignItems:"center",justifyContent:"center",color:"white",fontWeight:"bold",fontSize:18,flexShrink:0,boxShadow:R?"0 0 12px rgba(16, 185, 129, 0.4)":"none"},children:b?e.jsx("img",{src:b,alt:s,style:{width:"100%",height:"100%",objectFit:"cover"}}):((z=s==null?void 0:s.charAt(0))==null?void 0:z.toUpperCase())||"?"}),e.jsxs(y,{children:[e.jsxs(a,{align:"center",gap:"2",wrap:"wrap",children:[e.jsx(k,{size:"3",weight:"bold",style:{color:"var(--gray-12)"},children:s||"Officer"}),e.jsx(Q,{size:"1",color:R?"green":"blue",variant:"solid",radius:"full",children:R?"🟢 Active On-Duty":"✅ Shift Completed"})]}),e.jsxs(k,{size:"1",color:"gray",children:[v||"Employee"," ",t?`• ${t}`:"",x?` • ID: ${x}`:""]}),I&&e.jsxs(Q,{size:"1",color:"purple",variant:"soft",mt:"1",children:["Zone: ",I.name||"Standard"]})]})]}),e.jsx(Z,{size:"2",variant:"ghost",color:"gray",onClick:d,style:{cursor:"pointer"},children:e.jsx(oe,{})})]})}),e.jsxs(y,{p:"4",style:{overflowY:"auto",flex:1,display:"flex",flexDirection:"column",gap:16},children:[e.jsxs(a,{justify:"between",align:"center",children:[e.jsxs(k,{size:"2",weight:"bold",style:{color:"var(--gray-11)"},children:["Attendance & Patrol Telemetry (",C.length," ",C.length===1?"Cycle":"Cycles",")"]}),e.jsxs(k,{size:"1",color:"gray",children:["Date: ",l||"Today"]})]}),C.map((p,E)=>{const T=p.punchin_location,O=p.punchout_location,U=T&&T.lat&&T.lng?`${parseFloat(T.lat).toFixed(5)}, ${parseFloat(T.lng).toFixed(5)}`:null,W=O&&O.lat&&O.lng?`${parseFloat(O.lat).toFixed(5)}, ${parseFloat(O.lng).toFixed(5)}`:null;return e.jsxs(y,{p:"3",style:{background:"var(--gray-a2)",borderRadius:"var(--radius-3)",border:"1px solid var(--gray-a4)"},children:[e.jsxs(a,{justify:"between",align:"center",mb:"3",children:[e.jsxs(Q,{size:"1",color:"gray",variant:"surface",children:["Shift Cycle #",E+1]}),e.jsx(Q,{size:"1",color:p.is_complete?"blue":"green",variant:"soft",children:p.is_complete?"Cycle Finished":"Active Cycle"})]}),e.jsxs(a,{direction:"column",gap:"3",children:[e.jsxs(a,{align:"start",justify:"between",p:"2",style:{background:"var(--green-a2)",borderRadius:"var(--radius-2)",border:"1px solid var(--green-a4)"},children:[e.jsxs(a,{align:"start",gap:"2",style:{flex:1},children:[e.jsx(y,{style:{width:24,height:24,borderRadius:"50%",background:B.punchin,color:"white",display:"flex",alignItems:"center",justifyContent:"center",flexShrink:0},children:e.jsx(te,{style:{width:14,height:14}})}),e.jsxs(y,{children:[e.jsxs(k,{size:"1",weight:"bold",style:{color:"var(--green-11)"},children:["Check-In: ",p.punchin_time||"--"]}),U?e.jsxs(a,{align:"center",gap:"1",mt:"1",children:[e.jsx(ve,{style:{color:"var(--green-9)",width:12,height:12}}),e.jsx(k,{size:"1",style:{fontSize:11,fontFamily:"monospace",color:"var(--gray-11)"},children:U}),e.jsx(Z,{size:"1",variant:"ghost",style:{height:18,width:18},onClick:()=>F(U,`in-${E}`),children:_===`in-${E}`?e.jsx(de,{}):e.jsx(je,{})})]}):e.jsx(k,{size:"1",color:"gray",style:{fontSize:11},children:"No GPS coordinates"})]})]}),p.punchin_photo_url&&e.jsxs(y,{style:{width:48,height:48,borderRadius:"var(--radius-2)",overflow:"hidden",border:"1px solid var(--green-a6)",cursor:"pointer",position:"relative",flexShrink:0},onClick:()=>f&&f({url:p.punchin_photo_url,officerName:s,designation:v,timestamp:p.punchin_time,location:T,type:"punchin"}),children:[e.jsx("img",{src:p.punchin_photo_url,alt:"Check-in selfie",style:{width:"100%",height:"100%",objectFit:"cover"}}),e.jsx(y,{style:{position:"absolute",bottom:0,insetInline:0,background:"rgba(0,0,0,0.6)",display:"flex",alignItems:"center",justifyContent:"center",padding:1},children:e.jsx(be,{style:{color:"white",width:10,height:10}})})]})]}),p.punchout_time?e.jsxs(a,{align:"start",justify:"between",p:"2",style:{background:"var(--red-a2)",borderRadius:"var(--radius-2)",border:"1px solid var(--red-a4)"},children:[e.jsxs(a,{align:"start",gap:"2",style:{flex:1},children:[e.jsx(y,{style:{width:24,height:24,borderRadius:"50%",background:B.punchout,color:"white",display:"flex",alignItems:"center",justifyContent:"center",flexShrink:0},children:e.jsx(Te,{style:{width:14,height:14}})}),e.jsxs(y,{children:[e.jsxs(k,{size:"1",weight:"bold",style:{color:"var(--red-11)"},children:["Check-Out: ",p.punchout_time]}),W?e.jsxs(a,{align:"center",gap:"1",mt:"1",children:[e.jsx(ve,{style:{color:"var(--red-9)",width:12,height:12}}),e.jsx(k,{size:"1",style:{fontSize:11,fontFamily:"monospace",color:"var(--gray-11)"},children:W}),e.jsx(Z,{size:"1",variant:"ghost",style:{height:18,width:18},onClick:()=>F(W,`out-${E}`),children:_===`out-${E}`?e.jsx(de,{}):e.jsx(je,{})})]}):e.jsx(k,{size:"1",color:"gray",style:{fontSize:11},children:"No GPS coordinates"})]})]}),p.punchout_photo_url&&e.jsxs(y,{style:{width:48,height:48,borderRadius:"var(--radius-2)",overflow:"hidden",border:"1px solid var(--red-a6)",cursor:"pointer",position:"relative",flexShrink:0},onClick:()=>f&&f({url:p.punchout_photo_url,officerName:s,designation:v,timestamp:p.punchout_time,location:O,type:"punchout"}),children:[e.jsx("img",{src:p.punchout_photo_url,alt:"Check-out selfie",style:{width:"100%",height:"100%",objectFit:"cover"}}),e.jsx(y,{style:{position:"absolute",bottom:0,insetInline:0,background:"rgba(0,0,0,0.6)",display:"flex",alignItems:"center",justifyContent:"center",padding:1},children:e.jsx(be,{style:{color:"white",width:10,height:10}})})]})]}):e.jsxs(a,{align:"center",gap:"2",p:"2",style:{background:"var(--gray-a3)",borderRadius:"var(--radius-2)",border:"1px dashed var(--gray-a5)"},children:[e.jsx(te,{style:{color:"var(--amber-9)"}}),e.jsx(k,{size:"1",color:"gray",children:"Officer is currently on active patrol. Check-out not recorded yet."})]})]})]},E)})]}),e.jsx(y,{p:"3",style:{background:"var(--gray-a2)",borderTop:"1px solid var(--gray-a4)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"2",children:[e.jsxs(D,{variant:"surface",color:"blue",size:"2",onClick:()=>{if(d(),h){const p=j||m;p&&p.lat&&p.lng&&h([parseFloat(p.lat),parseFloat(p.lng)])}},children:[e.jsx(we,{})," Focus on Map"]}),e.jsx(D,{variant:"outline",color:"gray",size:"2",onClick:d,children:"Close"})]})})]})})});De.displayName="OfficerDetailModal";const Be=H.memo(({photoData:r,onClose:l})=>{const[d,f]=H.useState(!1);if(!r||!r.url)return null;const{url:h,title:_,officerName:u,designation:s,timestamp:x,location:v,type:t}=r,b=v&&v.lat&&v.lng?`${parseFloat(v.lat).toFixed(6)}, ${parseFloat(v.lng).toFixed(6)}`:null,i=()=>{b&&(navigator.clipboard.writeText(b),f(!0),setTimeout(()=>f(!1),2e3))};return e.jsxs(y,{style:{position:"fixed",inset:0,background:"rgba(0, 0, 0, 0.85)",backdropFilter:"blur(16px)",WebkitBackdropFilter:"blur(16px)",zIndex:99999,display:"flex",alignItems:"center",justifyContent:"center",padding:24,animation:"fadeIn 0.2s ease-out"},onClick:l,children:[e.jsx(Z,{size:"3",variant:"solid",color:"gray",highContrast:!0,style:{position:"absolute",top:24,right:24,borderRadius:"50%",cursor:"pointer",zIndex:10},onClick:n=>{n.stopPropagation(),l()},"aria-label":"Close photo preview",children:e.jsx(oe,{style:{width:22,height:22}})}),e.jsxs(y,{style:{maxWidth:"90vw",maxHeight:"90vh",display:"flex",flexDirection:"column",alignItems:"center",background:"var(--color-panel-solid, var(--color-surface, #ffffff))",border:"1px solid var(--gray-a5)",borderRadius:"var(--radius-4)",boxShadow:"var(--shadow-6, 0 25px 60px -15px rgba(0, 0, 0, 0.5))",overflow:"hidden"},onClick:n=>n.stopPropagation(),children:[e.jsx(y,{p:"3",style:{width:"100%",borderBottom:"1px solid var(--gray-a4)",background:"var(--gray-a2)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",px:"2",children:[e.jsxs(a,{align:"center",gap:"2",children:[e.jsx(re,{style:{color:"var(--blue-9)",width:18,height:18}}),e.jsxs(y,{children:[e.jsx(k,{size:"2",weight:"bold",style:{color:"var(--gray-12)"},children:u||"Officer Photo"}),s&&e.jsx(k,{size:"1",color:"gray",style:{display:"block"},children:s})]})]}),e.jsx(Q,{size:"1",color:t==="punchin"?"green":t==="punchout"?"red":"blue",variant:"solid",children:t==="punchin"?"Check-In Photo":t==="punchout"?"Check-Out Photo":_||"Verification Selfie"})]})}),e.jsx(y,{style:{display:"flex",alignItems:"center",justifyContent:"center",padding:16,maxHeight:"65vh",minWidth:320,maxWidth:720,overflow:"hidden"},children:e.jsx("img",{src:h,alt:"Officer Telemetry Verification",style:{maxWidth:"100%",maxHeight:"60vh",objectFit:"contain",borderRadius:"var(--radius-3)",border:"1px solid var(--gray-a4)",boxShadow:"var(--shadow-4)"}})}),e.jsx(y,{p:"3",style:{width:"100%",borderTop:"1px solid var(--gray-a4)",background:"var(--gray-a2)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",wrap:"wrap",px:"2",children:[e.jsxs(a,{align:"center",gap:"4",wrap:"wrap",children:[x&&e.jsxs(a,{align:"center",gap:"1",children:[e.jsx(te,{style:{color:"var(--purple-9)",width:14,height:14}}),e.jsx(k,{size:"1",style:{color:"var(--gray-12)"},children:x})]}),b&&e.jsxs(a,{align:"center",gap:"2",children:[e.jsx(ve,{style:{color:"var(--green-9)",width:14,height:14}}),e.jsx(k,{size:"1",style:{color:"var(--gray-12)",fontFamily:"monospace"},children:b}),e.jsxs(D,{size:"1",variant:"ghost",color:"gray",style:{cursor:"pointer",padding:"0 4px",height:20},onClick:i,children:[d?e.jsx(de,{style:{color:"var(--green-9)"}}):e.jsx(je,{}),e.jsx("span",{style:{fontSize:10},children:d?"Copied":"Copy"})]})]})]}),e.jsx("a",{href:h,target:"_blank",rel:"noopener noreferrer",download:!0,style:{textDecoration:"none"},children:e.jsxs(D,{size:"1",variant:"soft",color:"blue",style:{cursor:"pointer"},children:[e.jsx(ir,{}),"Download HD"]})})]})})]})]})});Be.displayName="PhotoTelemetryLightbox";const gr=H.memo(({selectedDate:r,updateMap:l})=>{const[d,f]=c.useState([]),[h,_]=c.useState([]),[u,s]=c.useState(!0),[x,v]=c.useState(!1),[t,b]=c.useState(null),[i,n]=c.useState(""),[g,o]=c.useState("all"),[j,m]=c.useState(()=>localStorage.getItem("guardian_map_tile_id")||"voyager"),[S,P]=c.useState({geofences:!0,waypoints:!0,trajectories:!0}),[I,R]=c.useState(!0),[F,C]=c.useState(!1),[z,p]=c.useState(null),[E,T]=c.useState(null),[O,U]=c.useState(null),[W,q]=c.useState(0),[N,M]=c.useState(null),[G,ne]=c.useState(!0),[ee,ie]=c.useState(xe),X=c.useRef(null);c.useRef(null);const he=c.useCallback(w=>{m(w),localStorage.setItem("guardian_map_tile_id",w)},[]),Ge=c.useCallback(w=>{P($=>({...$,[w]:!$[w]}))},[]),J=c.useCallback(async(w=!1)=>{if(r){w?v(!0):s(!0);try{const $=route("getUserLocationsForDate",{date:r.split("T")[0],_t:Date.now()}),V=await fetch($);if(!V.ok)throw new Error(`HTTP ${V.status}: Failed to fetch user locations`);const Y=await V.json(),se=Array.isArray(Y.locations)?Y.locations:[],K=Array.isArray(Y.attendance_type_configs)?Y.attendance_type_configs:[];f(se),_(K),b(new Date),ie(xe)}catch($){console.error("Failed to load team locations:",$)}finally{s(!1),v(!1)}}},[r]);c.useEffect(()=>{J(!1)},[r,l,J]),c.useEffect(()=>{if(!G)return;const w=setInterval(()=>{ie($=>$<=1?(J(!0),xe):$-1)},1e3);return()=>clearInterval(w)},[G,J]);const Ce=c.useMemo(()=>{const w=d.length;let $=0,V=0;return d.forEach(Y=>{Y.status==="active"?$++:V++}),{total:w,checkedIn:$,active:$,completed:V}},[d]),ae=c.useMemo(()=>d.filter(w=>{var $,V,Y,se;if(g==="active"&&w.status!=="active"||g==="completed"&&w.status==="active")return!1;if(i){const K=i.toLowerCase(),Ye=($=w.name)==null?void 0:$.toLowerCase().includes(K),Je=(V=w.employee_id)==null?void 0:V.toLowerCase().includes(K),Qe=(Y=w.designation)==null?void 0:Y.toLowerCase().includes(K),Xe=(se=w.department)==null?void 0:se.toLowerCase().includes(K);if(!Ye&&!Je&&!Qe&&!Xe)return!1}return!0}),[d,g,i]),Ze=c.useMemo(()=>t?t.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:!0}):null,[t]),_e=c.useMemo(()=>{if(!r)return"Invalid Date";try{return new Date(r).toLocaleString("en-US",{weekday:"long",year:"numeric",month:"long",day:"numeric"})}catch{return r}},[r]),ze=c.useCallback(w=>{p(w.user_id);const $=w.punchin_location||w.punchout_location||w.location;$&&$.lat&&$.lng&&M([parseFloat($.lat),parseFloat($.lng)])},[]),He=c.useCallback(w=>{M(w)},[]),qe=c.useCallback(()=>{q(w=>w+1)},[]),Ve=c.useCallback(()=>{X.current&&(document.fullscreenElement?(document.exitFullscreen(),C(!1)):(X.current.requestFullscreen().catch(w=>{console.warn("Fullscreen error:",w)}),C(!0)))},[]);return c.useEffect(()=>{const w=()=>{C(!!document.fullscreenElement)};return document.addEventListener("fullscreenchange",w),()=>document.removeEventListener("fullscreenchange",w)},[]),e.jsxs(y,{children:[e.jsxs(er,{mb:"4",children:[e.jsx(y,{p:"4",style:{borderBottom:"1px solid var(--gray-a4)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",wrap:"wrap",children:[e.jsxs(a,{align:"center",gap:"3",children:[e.jsx(y,{style:{padding:10,borderRadius:"var(--radius-3)",background:"linear-gradient(135deg, var(--blue-a3), var(--blue-a4))",border:"1px solid var(--blue-a6)",width:44,height:44,flexShrink:0,display:"flex",alignItems:"center",justifyContent:"center",boxShadow:"0 2px 8px rgba(0,0,0,0.06)"},children:e.jsx(me,{style:{color:"var(--blue-9)",width:22,height:22}})}),e.jsxs(y,{children:[e.jsx(Ee,{size:"4",style:{letterSpacing:"-0.02em"},children:"Team Locations & Live GIS Command Center"}),e.jsx(k,{size:"2",color:"gray",children:_e})]})]}),e.jsxs(D,{variant:"surface",size:"1",color:"blue",onClick:()=>J(!1),disabled:u||x,style:{cursor:"pointer"},children:[e.jsx(ye,{className:u||x?"animate-spin":""}),"Refresh Live Feed"]})]})}),e.jsx(Pe,{stats:Ce,lastUpdateText:Ze,isPolling:G,secondsLeft:ee}),e.jsx(y,{p:"4",children:u?e.jsx(a,{align:"center",justify:"center",style:{height:"72vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)",background:"var(--gray-a2)"},children:e.jsxs(a,{direction:"column",align:"center",gap:"3",children:[e.jsx(Ke,{size:"3"}),e.jsx(k,{size:"2",weight:"medium",color:"gray",children:"Loading team coordinates & GIS boundaries..."})]})}):d.length===0?e.jsxs(a,{direction:"column",align:"center",justify:"center",gap:"3",p:"6",style:{height:"72vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)",background:"var(--gray-a2)"},children:[e.jsx(me,{style:{width:64,height:64,color:"var(--gray-7)"}}),e.jsx(Ee,{size:"4",children:"No Team Location Records Found"}),e.jsxs(k,{size:"2",color:"gray",align:"center",style:{maxWidth:420},children:["No check-in or patrol coordinates recorded for ",_e,". Ensure team members have logged attendance via mobile GPS or check a different date."]}),e.jsxs(D,{variant:"outline",onClick:()=>J(!1),children:[e.jsx(ye,{})," Refresh Data"]})]}):e.jsxs(y,{ref:X,style:{position:"relative",height:F?"100vh":"72vh",borderRadius:F?0:"var(--radius-3)",overflow:"hidden",border:F?"none":"1px solid var(--gray-a5)",boxShadow:"0 8px 30px rgba(0,0,0,0.12)"},children:[e.jsx(Me,{searchQuery:i,onSearchChange:n,statusFilter:g,onStatusFilterChange:o,stats:Ce,currentTileId:j,onTileChange:he,layerVisibility:S,onToggleLayer:Ge,onFitBounds:qe,onRefresh:()=>J(!0),isRefreshing:x,isDrawerOpen:I,onToggleDrawer:()=>R(w=>!w),isFullscreen:F,onToggleFullscreen:Ve}),e.jsxs(Oe,{currentTileId:j,users:ae,attendanceTypeConfigs:h,fitBoundsTrigger:W,flyToCoords:N,children:[e.jsx(Ne,{attendanceTypeConfigs:h,users:ae,layerVisibility:S}),e.jsx(Ue,{users:ae,selectedUserId:z,onSelectOfficer:ze,onOpenTelemetry:T,onOpenPhoto:U,layerVisibility:S})]}),e.jsx(We,{isOpen:I,onClose:()=>R(!1),users:ae,selectedUserId:z,onSelectOfficer:ze,onOpenTelemetry:T,onOpenPhoto:U})]})})]}),E&&e.jsx(De,{officer:E,selectedDate:r,onClose:()=>T(null),onOpenPhoto:U,onFocusMap:He}),O&&e.jsx(Be,{photoData:O,onClose:()=>U(null)})]})});gr.displayName="UserLocationsCard";export{gr as U};
