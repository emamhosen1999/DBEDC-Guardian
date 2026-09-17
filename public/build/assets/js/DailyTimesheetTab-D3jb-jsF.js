import{j as e,a as y,p as a,b as C,e as K,u as Re,c as ce,h as G,d as D,_ as Fe,$ as Le,a0 as Se,am as Ie,a1 as le,q as Ee,s as er}from"./vendor-radix-BajkvXqg.js";import{R as Z,a as c}from"./vendor-inertia-EFmYJ5Li.js";import"./logRange-MpAeUqVc.js";import"./useObjectionsListState-Zq9XzyLx.js";import{u as rr}from"./useQueryFilters-BS0-L5O6.js";import{P as tr}from"./VerifyEmail-DJo4-eVx.js";import{m as te,c as oe,d as Te,M as $e,g as ne,G as me,_ as de,L as or,u as he,ae as ge,av as we,R as ye,aw as nr,ax as be,ac as ir,b as ar,ab as ve,$ as je,p as sr}from"./react-icons.esm-BXoXVZ9Y.js";import{L as O}from"./leaflet-GWQjmKsu.js";import{d as ke,M as lr,T as cr,n as fe,f as dr}from"./TileLayer-qKJi77bL.js";import"./DepartmentForm-CE7_b-mg.js";import"./ErrorBoundary-Bwd65k7C.js";import"./MonthlyCalendarTab-Cri_HnwV.js";import"./index.esm-MmCp14hd.js";import"./firebase-config-u-yGv1BU.js";import"./vendor-utils-DZdOoBG3.js";const pe={voyager:{id:"voyager",name:"Voyager (Crisp Light)",icon:"Compass",url:"https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png",subdomains:"abcd",maxZoom:20,attribution:'&copy; <a href="https://carto.com/">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'},darkMatter:{id:"darkMatter",name:"Dark Matter (Midnight)",icon:"Moon",url:"https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png",subdomains:"abcd",maxZoom:20,attribution:'&copy; <a href="https://carto.com/">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'},positron:{id:"positron",name:"Positron (Minimal Light)",icon:"Sun",url:"https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png",subdomains:"abcd",maxZoom:20,attribution:'&copy; <a href="https://carto.com/">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'},satellite:{id:"satellite",name:"Satellite (Aerial HD)",icon:"Globe",url:"https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",subdomains:"",maxZoom:19,attribution:"Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community"},osm:{id:"osm",name:"OpenStreetMap Standard",icon:"Map",url:"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",subdomains:"abc",maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}},pr=[23.8103,90.4125],ur=12,hr=7,gr=19,xe=15,B={active:"#10b981",completed:"#3b82f6",punchin:"#10b981",punchout:"#ef4444"},fr=`
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
`,Pe=Z.memo(({stats:r,lastUpdateText:l,isPolling:d,secondsLeft:g})=>{const u=(r==null?void 0:r.total)||0,z=(r==null?void 0:r.checkedIn)??(r==null?void 0:r.active)??0,h=(r==null?void 0:r.completed)||0,s=u>0?Math.round(z/u*100):0;return e.jsx(y,{p:"3",style:{background:"linear-gradient(135deg, var(--gray-a2), var(--gray-a3))",borderBottom:"1px solid var(--gray-a4)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",wrap:"wrap",children:[e.jsxs(a,{align:"center",gap:"3",wrap:"wrap",children:[e.jsxs(a,{align:"center",gap:"2",px:"3",py:"2",style:{borderRadius:"var(--radius-3)",background:"var(--color-panel-solid, #ffffff)",border:"1px solid var(--gray-a4)",boxShadow:"0 1px 3px rgba(0,0,0,0.05)"},children:[e.jsx(a,{align:"center",justify:"center",style:{width:28,height:28,borderRadius:"50%",background:"var(--blue-a3)",color:"var(--blue-9)"},children:e.jsx(te,{style:{width:16,height:16}})}),e.jsxs(y,{children:[e.jsxs(a,{align:"baseline",gap:"1",children:[e.jsx(C,{size:"4",weight:"bold",style:{color:"var(--gray-12)"},children:u}),e.jsx(C,{size:"1",color:"gray",children:"Officers"})]}),e.jsx(C,{size:"1",color:"gray",style:{fontSize:10,display:"block",marginTop:-2},children:"Total Tracked"})]})]}),e.jsxs(a,{align:"center",gap:"2",px:"3",py:"2",style:{borderRadius:"var(--radius-3)",background:"var(--color-panel-solid, #ffffff)",border:"1px solid var(--green-a5)",boxShadow:"0 1px 3px rgba(0,0,0,0.05)"},children:[e.jsxs(a,{align:"center",justify:"center",style:{width:28,height:28,borderRadius:"50%",background:"var(--green-a3)",color:"var(--green-9)",position:"relative"},children:[e.jsx(oe,{style:{width:16,height:16}}),z>0&&e.jsx("span",{style:{position:"absolute",top:1,right:1,width:8,height:8,borderRadius:"50%",background:B.active,border:"1.5px solid white"}})]}),e.jsxs(y,{children:[e.jsxs(a,{align:"baseline",gap:"1",children:[e.jsx(C,{size:"4",weight:"bold",style:{color:"var(--green-11)"},children:z}),e.jsxs(K,{size:"1",color:"green",variant:"soft",radius:"full",children:[s,"%"]})]}),e.jsx(C,{size:"1",color:"gray",style:{fontSize:10,display:"block",marginTop:-2},children:"Active On-Duty"})]})]}),e.jsxs(a,{align:"center",gap:"2",px:"3",py:"2",style:{borderRadius:"var(--radius-3)",background:"var(--color-panel-solid, #ffffff)",border:"1px solid var(--gray-a4)",boxShadow:"0 1px 3px rgba(0,0,0,0.05)"},children:[e.jsx(a,{align:"center",justify:"center",style:{width:28,height:28,borderRadius:"50%",background:"var(--blue-a3)",color:"var(--blue-9)"},children:e.jsx(Te,{style:{width:16,height:16}})}),e.jsxs(y,{children:[e.jsxs(a,{align:"baseline",gap:"1",children:[e.jsx(C,{size:"4",weight:"bold",style:{color:"var(--blue-11)"},children:h}),e.jsx(C,{size:"1",color:"gray",children:"Completed"})]}),e.jsx(C,{size:"1",color:"gray",style:{fontSize:10,display:"block",marginTop:-2},children:"Finished Shifts"})]})]})]}),e.jsxs(a,{align:"center",gap:"2",children:[e.jsxs(a,{align:"center",gap:"2",px:"2",py:"1",style:{background:"var(--gray-a3)",borderRadius:"var(--radius-2)",border:"1px solid var(--gray-a4)"},children:[e.jsx(a,{align:"center",justify:"center",style:{width:8,height:8,borderRadius:"50%",background:d?B.active:"var(--gray-8)",boxShadow:d?"0 0 8px #10b981":"none"}}),e.jsx(C,{size:"1",color:"gray",children:d?`Live Sync (${g}s)`:"Polling Paused"})]}),l&&e.jsxs(C,{size:"1",color:"gray",style:{fontSize:11},children:["Updated: ",l]})]})]})})});Pe.displayName="MapStatsRibbon";const Me=Z.memo(({searchQuery:r,onSearchChange:l,statusFilter:d,onStatusFilterChange:g,stats:u,currentTileId:z,onTileChange:h,layerVisibility:s,onToggleLayer:x,onFitBounds:j,onRefresh:o,isRefreshing:b,isDrawerOpen:i,onToggleDrawer:n,isFullscreen:m,onToggleFullscreen:t})=>{var w;return e.jsx(y,{style:{position:"absolute",top:14,left:14,right:14,zIndex:1e3,pointerEvents:"none"},children:e.jsxs(a,{gap:"2",align:"center",justify:"between",wrap:"wrap",style:{pointerEvents:"auto"},children:[e.jsxs(a,{align:"center",gap:"2",wrap:"wrap",p:"2",style:{background:"var(--color-surface)",backdropFilter:"blur(16px)",WebkitBackdropFilter:"blur(16px)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a5)",boxShadow:"var(--shadow-4, 0 8px 30px rgba(0, 0, 0, 0.12))"},children:[e.jsx(y,{style:{width:190},children:e.jsxs(Re,{size:"1",variant:"surface",placeholder:"Search officer / ID...",value:r,onChange:f=>l(f.target.value),children:[e.jsx(ce,{children:e.jsx($e,{style:{color:"var(--gray-9)"}})}),r&&e.jsx(ce,{children:e.jsx(G,{size:"1",variant:"ghost",color:"gray",style:{cursor:"pointer"},onClick:()=>l(""),children:e.jsx(ne,{})})})]})}),e.jsxs(a,{align:"center",gap:"1",children:[e.jsxs(D,{size:"1",variant:d==="all"?"solid":"soft",color:"gray",onClick:()=>g("all"),style:{cursor:"pointer",fontWeight:600},children:["All (",u.total,")"]}),e.jsxs(D,{size:"1",variant:d==="active"?"solid":"soft",color:"green",onClick:()=>g("active"),style:{cursor:"pointer",fontWeight:600},children:["🟢 Active (",u.active,")"]}),e.jsxs(D,{size:"1",variant:d==="completed"?"solid":"soft",color:"blue",onClick:()=>g("completed"),style:{cursor:"pointer",fontWeight:600},children:["✅ Done (",u.completed,")"]})]})]}),e.jsxs(a,{align:"center",gap:"2",p:"2",style:{background:"var(--color-surface)",backdropFilter:"blur(16px)",WebkitBackdropFilter:"blur(16px)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a5)",boxShadow:"var(--shadow-4, 0 8px 30px rgba(0, 0, 0, 0.12))"},children:[e.jsxs(Fe,{children:[e.jsx(Le,{children:e.jsxs(D,{size:"1",variant:"soft",color:"gray",style:{cursor:"pointer",fontWeight:600},children:[e.jsx(me,{}),((w=pe[z])==null?void 0:w.name)||"Basemap"]})}),e.jsxs(Se,{variant:"solid",size:"1",children:[e.jsx(Ie,{children:"Select Map Tile"}),Object.values(pe).map(f=>e.jsxs(le,{onClick:()=>h(f.id),style:{cursor:"pointer",display:"flex",alignItems:"center",justifyContent:"between"},children:[e.jsx("span",{children:f.name}),z===f.id&&e.jsx(de,{style:{marginLeft:8}})]},f.id))]})]}),e.jsxs(Fe,{children:[e.jsx(Le,{children:e.jsxs(D,{size:"1",variant:"soft",color:"gray",style:{cursor:"pointer",fontWeight:600},children:[e.jsx(or,{}),"Layers"]})}),e.jsxs(Se,{variant:"solid",size:"1",children:[e.jsx(Ie,{children:"Toggle Overlays"}),e.jsx(le,{onClick:()=>x("geofences"),style:{cursor:"pointer"},children:e.jsxs(a,{align:"center",gap:"2",children:[s.geofences?e.jsx(he,{style:{color:"var(--purple-9)"}}):e.jsx(ge,{}),e.jsx("span",{children:"Geofence Zones"})]})}),e.jsx(le,{onClick:()=>x("waypoints"),style:{cursor:"pointer"},children:e.jsxs(a,{align:"center",gap:"2",children:[s.waypoints?e.jsx(he,{style:{color:"var(--cyan-9)"}}):e.jsx(ge,{}),e.jsx("span",{children:"Route Waypoints"})]})}),e.jsx(le,{onClick:()=>x("trajectories"),style:{cursor:"pointer"},children:e.jsxs(a,{align:"center",gap:"2",children:[s.trajectories?e.jsx(he,{style:{color:"var(--blue-9)"}}):e.jsx(ge,{}),e.jsx("span",{children:"Patrol Trajectories"})]})})]})]}),e.jsxs(D,{size:"1",variant:"soft",color:"gray",onClick:j,style:{cursor:"pointer"},title:"Fit all markers in view",children:[e.jsx(we,{}),"Fit All"]}),e.jsx(G,{size:"1",variant:"soft",color:"blue",onClick:o,disabled:b,style:{cursor:"pointer"},title:"Refresh live coordinates",children:e.jsx(ye,{className:b?"animate-spin":""})}),e.jsxs(D,{size:"1",variant:i?"solid":"soft",color:i?"blue":"gray",onClick:n,style:{cursor:"pointer",fontWeight:600},children:[e.jsx(te,{}),"Roster (",u.total,")"]}),e.jsx(G,{size:"1",variant:"soft",color:"gray",onClick:t,style:{cursor:"pointer"},title:m?"Exit Fullscreen":"Enter Fullscreen",children:m?e.jsx(nr,{}):e.jsx(be,{})})]})]})})});Me.displayName="MapHudControls";L.Control.Fullscreen=L.Control.extend({options:{position:"topleft",title:{false:"View Fullscreen",true:"Exit Fullscreen"}},onAdd:function(r){var l=L.DomUtil.create("div","leaflet-control-fullscreen leaflet-bar leaflet-control");return this.link=L.DomUtil.create("a","leaflet-control-fullscreen-button leaflet-bar-part",l),this.link.href="#",this._map=r,this._map.on("fullscreenchange",this._toggleTitle,this),this._toggleTitle(),L.DomEvent.on(this.link,"click",this._click,this),l},_click:function(r){L.DomEvent.stopPropagation(r),L.DomEvent.preventDefault(r),this._map.toggleFullscreen(this.options)},_toggleTitle:function(){this.link.title=this.options.title[this._map.isFullscreen()]}});L.Map.include({isFullscreen:function(){return this._isFullscreen||!1},toggleFullscreen:function(r){var l=this.getContainer();this.isFullscreen()?r&&r.pseudoFullscreen?this._disablePseudoFullscreen(l):document.exitFullscreen?document.exitFullscreen():document.mozCancelFullScreen?document.mozCancelFullScreen():document.webkitCancelFullScreen?document.webkitCancelFullScreen():document.msExitFullscreen?document.msExitFullscreen():this._disablePseudoFullscreen(l):r&&r.pseudoFullscreen?this._enablePseudoFullscreen(l):l.requestFullscreen?l.requestFullscreen():l.mozRequestFullScreen?l.mozRequestFullScreen():l.webkitRequestFullscreen?l.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT):l.msRequestFullscreen?l.msRequestFullscreen():this._enablePseudoFullscreen(l)},_enablePseudoFullscreen:function(r){L.DomUtil.addClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!0),this.fire("fullscreenchange")},_disablePseudoFullscreen:function(r){L.DomUtil.removeClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!1),this.fire("fullscreenchange")},_setFullscreen:function(r){this._isFullscreen=r;var l=this.getContainer();r?L.DomUtil.addClass(l,"leaflet-fullscreen-on"):L.DomUtil.removeClass(l,"leaflet-fullscreen-on"),this.invalidateSize()},_onFullscreenChange:function(r){var l=document.fullscreenElement||document.mozFullScreenElement||document.webkitFullscreenElement||document.msFullscreenElement;l===this.getContainer()&&!this._isFullscreen?(this._setFullscreen(!0),this.fire("fullscreenchange")):l!==this.getContainer()&&this._isFullscreen&&(this._setFullscreen(!1),this.fire("fullscreenchange"))}});L.Map.mergeOptions({fullscreenControl:!1});L.Map.addInitHook(function(){this.options.fullscreenControl&&(this.fullscreenControl=new L.Control.Fullscreen(this.options.fullscreenControl),this.addControl(this.fullscreenControl));var r;if("onfullscreenchange"in document?r="fullscreenchange":"onmozfullscreenchange"in document?r="mozfullscreenchange":"onwebkitfullscreenchange"in document?r="webkitfullscreenchange":"onmsfullscreenchange"in document&&(r="MSFullscreenChange"),r){var l=L.bind(this._onFullscreenChange,this);this.whenReady(function(){L.DomEvent.on(document,r,l)}),this.on("unload",function(){L.DomEvent.off(document,r,l)})}});L.control.fullscreen=function(r){return new L.Control.Fullscreen(r)};const Ae=Z.memo(({fitBoundsTrigger:r,users:l,flyToCoords:d,attendanceTypeConfigs:g})=>{const u=ke();return c.useEffect(()=>{if(!u||r===0)return;const z=O.latLngBounds([]);(l||[]).forEach(h=>{const s=h.punchin_location||h.location,x=h.punchout_location;s&&s.lat&&s.lng&&z.extend([parseFloat(s.lat),parseFloat(s.lng)]),x&&x.lat&&x.lng&&z.extend([parseFloat(x.lat),parseFloat(x.lng)])}),(g||[]).forEach(h=>{var s,x;(s=h.config)!=null&&s.polygon&&h.config.polygon.forEach(j=>{j.lat&&j.lng&&z.extend([parseFloat(j.lat),parseFloat(j.lng)])}),(x=h.config)!=null&&x.waypoints&&h.config.waypoints.forEach(j=>{j.lat&&j.lng&&z.extend([parseFloat(j.lat),parseFloat(j.lng)])})}),z.isValid()&&u.fitBounds(z,{padding:[60,60],maxZoom:15,animate:!0,duration:.8})},[u,r,l,g]),c.useEffect(()=>{!u||!d||u.flyTo(d,16,{animate:!0,duration:1.2})},[u,d]),null});Ae.displayName="MapController";const Oe=Z.memo(({currentTileId:r="voyager",users:l=[],attendanceTypeConfigs:d=[],fitBoundsTrigger:g=0,flyToCoords:u=null,children:z})=>{const h=pe[r]||pe.voyager;return c.useEffect(()=>{const s="team-map-injected-styles";if(!document.getElementById(s)){const x=document.createElement("style");x.id=s,x.innerHTML=fr,document.head.appendChild(x)}},[]),e.jsx("div",{style:{position:"relative",width:"100%",height:"100%"},children:e.jsxs(lr,{center:pr,zoom:ur,minZoom:hr,maxZoom:gr,style:{width:"100%",height:"100%",background:"#0f172a"},scrollWheelZoom:!0,doubleClickZoom:!0,dragging:!0,touchZoom:!0,zoomControl:!1,attributionControl:!1,children:[e.jsx(cr,{url:h.url,subdomains:h.subdomains,maxZoom:h.maxZoom,attribution:h.attribution},h.id),e.jsx(Ae,{fitBoundsTrigger:g,users:l,flyToCoords:u,attendanceTypeConfigs:d}),z]})})});Oe.displayName="MapContainerView";const Ne=Z.memo(({attendanceTypeConfigs:r=[],users:l=[],layerVisibility:d={geofences:!0,waypoints:!0,trajectories:!0}})=>{const g=ke(),u=c.useRef([]);return c.useEffect(()=>{if(!g)return;let z=!1;if(u.current.forEach(s=>{try{g.removeLayer(s)}catch{}}),u.current=[],!r||r.length===0)return;const h=["#0284c7","#10b981","#f59e0b","#8b5cf6","#ec4899","#06b6d4","#14b8a6","#f97316"];return r.forEach((s,x)=>{var w,f,S,T;const{base_slug:j,slug:o,config:b,name:i}=s,n=h[x%h.length];if(!b)return;if((j==="geo_polygon"||(o==null?void 0:o.includes("polygon"))||(o==null?void 0:o.includes("geofence"))||!!((w=b.polygon)!=null&&w.length||(f=b.polygons)!=null&&f.length))&&d.geofences!==!1){const I=b.polygon||[],E=b.polygons||[],F=(k,_)=>{const p=(k||[]).map(fe).filter(Boolean);if(p.length<3)return;const R=p.map(U=>[U.lat,U.lng]),$=O.polygon(R,{color:n,fillColor:n,fillOpacity:.16,weight:2.5,opacity:.85,dashArray:"6, 6"}).addTo(g),M=$.getBounds(),W=M.getCenter(),A=l.filter(U=>{const H=fe(U.punchin_location||U.punchout_location||U.location);return H?M.contains(O.latLng(H.lat,H.lng)):!1}).length,q=`
                        <div class="geofence-centroid-badge" style="border-color: ${n}88;">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${n};"></span>
                            <span>${_||i}</span>
                            ${A>0?`<span style="background:${n}; color:white; border-radius:10px; padding:0 6px; font-size:10px;">${A} Officers</span>`:""}
                        </div>
                    `,N=O.marker(W,{icon:O.divIcon({html:q,className:"geofence-label-marker",iconSize:[120,26],iconAnchor:[60,13]}),interactive:!1}).addTo(g);$.bindPopup(`
                        <div style="font-family: inherit; padding: 6px; min-width: 140px; color: var(--gray-12, #1e293b);">
                            <div style="font-weight: 700; color: ${n}; font-size: 13px; margin-bottom: 2px;">
                                🛡️ ${_||i}
                            </div>
                            <div style="font-size: 11px; color: var(--gray-10, #64748b);">Geofence Zone Perimeter</div>
                            <div style="font-size: 11px; margin-top: 4px; font-weight: 600;">
                                Verified Officers: <span style="color:${n};">${A}</span>
                            </div>
                        </div>
                    `),u.current.push($),u.current.push(N)};I.length>=3&&F(I,i),E.forEach((k,_)=>{const p=k.points||k.coordinates||k;Array.isArray(p)&&p.length>=3&&F(p,k.name||`${i} Zone ${_+1}`)})}if((j==="route_waypoint"||(o==null?void 0:o.includes("route"))||(o==null?void 0:o.includes("waypoint"))||(o==null?void 0:o.includes("patrol"))||!!((S=b.waypoints)!=null&&S.length||(T=b.routes)!=null&&T.length))&&d.waypoints!==!1){const I=b.waypoints||[],E=b.routes||[],F=(_,p,R)=>{const $=(_||[]).map(fe).filter(Boolean);if($.length===0)return;const M=$.map(N=>[N.lat,N.lng]);let W=null,A=null,q=null;M.length>=2&&(W=O.polyline(M,{color:n,weight:12,opacity:.2,lineCap:"round",lineJoin:"round"}).addTo(g),A=O.polyline(M,{color:n,weight:4.5,opacity:.85,lineCap:"round",lineJoin:"round"}).addTo(g),q=O.polyline(M,{color:"#ffffff",weight:2,opacity:.9,dashArray:"8, 8",className:"patrol-trajectory-path",lineCap:"round",lineJoin:"round"}).addTo(g),u.current.push(W),u.current.push(A),u.current.push(q),dr($).then(N=>{if(z||!N||!N.latLngs)return;const U=N.latLngs;W&&g.hasLayer(W)&&W.setLatLngs(U),A&&g.hasLayer(A)&&A.setLatLngs(U),q&&g.hasLayer(q)&&q.setLatLngs(U)}).catch(N=>{console.warn("Road snapping fallback active:",N)})),$.forEach((N,U)=>{const H=U===0,J=U===$.length-1&&$.length>1,ie=H?"#10b981":J?"#ef4444":n;if(R&&R>0){const re=O.circle([N.lat,N.lng],{radius:R,color:ie,fillColor:ie,fillOpacity:.08,weight:1.5,dashArray:"4, 4"}).addTo(g);u.current.push(re)}const ue=`
                            <div style="
                                width: 28px;
                                height: 28px;
                                border-radius: 50%;
                                background: ${ie};
                                border: 2.5px solid var(--color-surface, #ffffff);
                                box-shadow: 0 4px 10px rgba(0,0,0,0.4);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: white;
                                font-weight: 800;
                                font-size: 11px;
                            ">
                                ${$.length===1?"📍":H?"S":J?"E":U+1}
                            </div>
                        `,ee=O.marker([N.lat,N.lng],{icon:O.divIcon({html:ue,className:"waypoint-marker",iconSize:[28,28],iconAnchor:[14,14]})}).addTo(g);ee.bindPopup(`
                            <div style="font-family: inherit; padding: 4px; color: var(--gray-12, #1e293b);">
                                <strong style="color: ${n};">${p||i}</strong><br>
                                <span style="font-size: 11px; color: var(--gray-10, #64748b);">
                                    ${$.length===1?"🎯 Patrol Checkpoint":H?"🚀 Expressway Route Start":J?"🏁 Expressway Route End":`Waypoint #${U+1}`}
                                </span>
                                ${R?`<div style="font-size: 10px; color: var(--gray-9); margin-top: 2px;">Highway Attendance Tolerance: ${R}m</div>`:""}
                            </div>
                        `),u.current.push(ee)})},k=b.tolerance||150;I.length>0&&F(I,i,k),E.forEach((_,p)=>{const R=_.waypoints||_.points||_.coords;Array.isArray(R)&&R.length>0&&F(R,_.name||`${i} Route ${p+1}`,_.tolerance||k)})}}),()=>{z=!0,u.current.forEach(s=>{try{g.removeLayer(s)}catch{}}),u.current=[]}},[g,r,l,d]),null});Ne.displayName="MapGeofenceLayers";const Ue=Z.memo(({users:r=[],selectedUserId:l,onSelectOfficer:d,onOpenTelemetry:g,onOpenPhoto:u,layerVisibility:z={trajectories:!0}})=>{const h=ke(),s=c.useRef([]),x=c.useRef([]),j=c.useCallback(i=>{if(!i)return null;if(typeof i=="object"&&i.lat&&i.lng){const n=parseFloat(i.lat),m=parseFloat(i.lng);if(!isNaN(n)&&!isNaN(m))return{lat:n,lng:m}}if(typeof i=="string")try{const n=JSON.parse(i);if(n.lat&&n.lng){const m=parseFloat(n.lat),t=parseFloat(n.lng);if(!isNaN(m)&&!isNaN(t))return{lat:m,lng:t}}}catch{const m=i.split(",");if(m.length>=2){const t=parseFloat(m[0].trim()),w=parseFloat(m[1].trim());if(!isNaN(t)&&!isNaN(w))return{lat:t,lng:w}}}return null},[]),o=c.useCallback((i,n="active",m=!1)=>{var _,p;const t=i.status==="active"||n==="punchin",w=n==="punchout",f=i.profile_image_url,S=((p=(_=i.name)==null?void 0:_.charAt(0))==null?void 0:p.toUpperCase())||"?",T=t?'<div class="living-marker-radar-ring"></div>':"",I=`living-marker-core ${t?"is-active":w?"is-punchout":"is-completed"}`,E=t?B.active:w?B.punchout:B.completed,F=t?"▶":w?"◼":"✓",k=`
            <div class="living-marker-wrapper" style="${m?"transform: scale(1.25); z-index: 9999;":""}">
                ${T}
                <div class="${I}" style="${m?"border-color: #38bdf8; box-shadow: 0 0 16px #38bdf8;":""}">
                    ${f?`<img src="${f}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerText='${S}';" />`:S}
                </div>
                <div class="living-marker-badge" style="background: ${E};">
                    ${F}
                </div>
            </div>
        `;return O.divIcon({html:k,className:"custom-living-marker",iconSize:[44,44],iconAnchor:[22,22],popupAnchor:[0,-22]})},[]),b=c.useCallback((i,n,m="current")=>{var F,k;const t=i.status==="active",w=(n==null?void 0:n.punchin_time)||i.punchin_time||"--",f=(n==null?void 0:n.punchout_time)||i.punchout_time,S=(n==null?void 0:n.punchin_photo_url)||i.punchin_photo_url,T=(n==null?void 0:n.punchout_photo_url)||i.punchout_photo_url,I=m==="punchout"&&T||S,E=I?`
            <div style="margin: 8px 0; border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.15); max-height: 90px; cursor: pointer; position: relative;"
                 onclick="window.__openMapPhoto && window.__openMapPhoto('${I}', '${i.name.replace(/'/g,"\\'")}', '${w}', '${m}')">
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
                        border: 2px solid ${t?B.active:"var(--gray-8, #94a3b8)"};
                        background: var(--gray-a4, #e2e8f0);
                        color: var(--gray-12, #1e293b);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: bold;
                        font-size: 12px;
                        flex-shrink: 0;
                    ">
                        ${i.profile_image_url?`<img src="${i.profile_image_url}" style="width:100%; height:100%; object-fit:cover;" />`:((k=(F=i.name)==null?void 0:F.charAt(0))==null?void 0:k.toUpperCase())||"?"}
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
                        background: ${t?"var(--green-a3, rgba(16, 185, 129, 0.15))":"var(--blue-a3, rgba(59, 130, 246, 0.15))"};
                        color: ${t?"var(--green-11, #059669)":"var(--blue-11, #2563eb)"};
                        border: 1px solid ${t?"var(--green-a5, rgba(16, 185, 129, 0.4))":"var(--blue-a5, rgba(59, 130, 246, 0.4))"};
                    ">
                        ${t?"🟢 ACTIVE":"✅ DONE"}
                    </div>
                </div>

                <!-- Timestamps -->
                <div style="background: var(--gray-a3, rgba(0, 0, 0, 0.04)); border-radius: 6px; padding: 6px; margin-bottom: 6px; font-size: 11px; border: 1px solid var(--gray-a4);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px;">
                        <span style="color: var(--gray-10, #64748b);">Check In:</span>
                        <span style="font-weight: 600; color: var(--green-11, #059669);">${w}</span>
                    </div>
                    ${f?`
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="color: var(--gray-10, #64748b);">Check Out:</span>
                        <span style="font-weight: 600; color: var(--red-11, #dc2626);">${f}</span>
                    </div>`:""}
                </div>

                ${E}

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
        `},[]);return c.useEffect(()=>(window.__inspectOfficer=i=>{const n=r.find(m=>m.user_id===i);n&&g&&g(n)},window.__openMapPhoto=(i,n,m,t)=>{u&&u({url:i,officerName:n,timestamp:m,type:t})},()=>{delete window.__inspectOfficer,delete window.__openMapPhoto}),[r,g,u]),c.useEffect(()=>{if(!h||(s.current.forEach(t=>{try{h.removeLayer(t)}catch{}}),s.current=[],x.current.forEach(t=>{try{h.removeLayer(t)}catch{}}),x.current=[],!r||r.length===0))return;const i=[],n=15e-5,m=t=>{let w=t.lat,f=t.lng;const S=i.filter(T=>Math.abs(T.lat-w)<n&&Math.abs(T.lng-f)<n).length;if(S>0){const T=S*1.25,I=18e-5*Math.sqrt(S);w+=Math.cos(T)*I,f+=Math.sin(T)*I}return i.push({lat:w,lng:f}),{lat:w,lng:f}};return r.forEach(t=>{const w=t.cycles&&t.cycles.length>0?t.cycles:null,f=l===t.user_id;if(w)w.forEach((S,T)=>{const I=j(S.punchin_location),E=j(S.punchout_location);if(I&&E&&S.is_complete){const F=m(I),k=m(E),_=O.marker([F.lat,F.lng],{icon:o(t,"punchin",f),zIndexOffset:f?1e3:100}).addTo(h);_.bindPopup(b(t,S,"punchin")),_.on("click",()=>d&&d(t)),s.current.push(_);const p=O.marker([k.lat,k.lng],{icon:o(t,"punchout",f),zIndexOffset:f?1e3:90}).addTo(h);if(p.bindPopup(b(t,S,"punchout")),p.on("click",()=>d&&d(t)),s.current.push(p),z.trajectories){const R=O.polyline([[F.lat,F.lng],[k.lat,k.lng]],{color:"#06b6d4",weight:3.5,opacity:.8,className:"patrol-trajectory-path"}).addTo(h);x.current.push(R)}}else{const F=I||E;if(F){const k=m(F),_=O.marker([k.lat,k.lng],{icon:o(t,t.status,f),zIndexOffset:f?1e3:150}).addTo(h);_.bindPopup(b(t,S,"punchin")),_.on("click",()=>d&&d(t)),s.current.push(_)}}});else{const S=j(t.punchin_location||t.location),T=j(t.punchout_location),I=S||T;if(I){const E=m(I),F=O.marker([E.lat,E.lng],{icon:o(t,t.status,f),zIndexOffset:f?1e3:100}).addTo(h);if(F.bindPopup(b(t,t,t.status)),F.on("click",()=>d&&d(t)),s.current.push(F),S&&T&&t.punchout_time&&z.trajectories){const k=m(T),_=O.polyline([[E.lat,E.lng],[k.lat,k.lng]],{color:"#06b6d4",weight:3.5,opacity:.8,className:"patrol-trajectory-path"}).addTo(h);x.current.push(_)}}}}),()=>{s.current.forEach(t=>{try{h.removeLayer(t)}catch{}}),s.current=[],x.current.forEach(t=>{try{h.removeLayer(t)}catch{}}),x.current=[]}},[h,r,l,z,o,b,j,d]),null});Ue.displayName="MapLivingMarkers";const We=Z.memo(({isOpen:r,onClose:l,users:d=[],selectedUserId:g,onSelectOfficer:u,onOpenTelemetry:z,onOpenPhoto:h})=>{const[s,x]=c.useState(""),j=c.useMemo(()=>{if(!s)return d;const o=s.toLowerCase();return d.filter(b=>{var i,n,m,t;return((i=b.name)==null?void 0:i.toLowerCase().includes(o))||((n=b.employee_id)==null?void 0:n.toLowerCase().includes(o))||((m=b.designation)==null?void 0:m.toLowerCase().includes(o))||((t=b.department)==null?void 0:t.toLowerCase().includes(o))})},[d,s]);return r?e.jsxs(y,{style:{position:"absolute",top:74,right:14,bottom:14,width:320,maxWidth:"calc(100vw - 28px)",background:"var(--color-panel-solid, var(--color-surface, #ffffff))",backdropFilter:"blur(20px)",WebkitBackdropFilter:"blur(20px)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a5)",boxShadow:"var(--shadow-5, 0 20px 40px rgba(0, 0, 0, 0.25))",zIndex:1e3,display:"flex",flexDirection:"column",overflow:"hidden",animation:"slideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1)"},children:[e.jsxs(y,{p:"3",style:{borderBottom:"1px solid var(--gray-a4)",background:"var(--gray-a2)"},children:[e.jsxs(a,{justify:"between",align:"center",mb:"2",children:[e.jsxs(a,{align:"center",gap:"2",children:[e.jsx(te,{style:{color:"var(--blue-9)",width:16,height:16}}),e.jsx(C,{size:"2",weight:"bold",style:{color:"var(--gray-12)"},children:"On-Duty Team Roster"}),e.jsx(K,{size:"1",color:"blue",variant:"solid",radius:"full",children:d.length})]}),e.jsx(G,{size:"1",variant:"ghost",color:"gray",style:{cursor:"pointer"},onClick:l,children:e.jsx(ne,{})})]}),e.jsxs(Re,{size:"1",variant:"surface",placeholder:"Filter roster...",value:s,onChange:o=>x(o.target.value),children:[e.jsx(ce,{children:e.jsx($e,{style:{color:"var(--gray-9)"}})}),s&&e.jsx(ce,{children:e.jsx(G,{size:"1",variant:"ghost",color:"gray",onClick:()=>x(""),children:e.jsx(ne,{})})})]})]}),e.jsx(y,{p:"2",style:{flex:1,overflowY:"auto",display:"flex",flexDirection:"column",gap:6},children:j.length===0?e.jsxs(a,{align:"center",justify:"center",direction:"column",gap:"2",p:"4",style:{height:"100%"},children:[e.jsx(te,{style:{color:"var(--gray-8)",width:28,height:28}}),e.jsx(C,{size:"1",color:"gray",children:"No matching officers found"})]}):j.map(o=>{var t,w;const b=g===o.user_id,i=o.status==="active",n=o.punchin_time||"--",m=o.punchout_time;return o.punchin_photo_url||o.profile_image_url,e.jsxs(y,{p:"2",style:{borderRadius:"var(--radius-3)",background:b?"var(--blue-a3)":"var(--gray-a2)",border:b?"1px solid var(--blue-a7)":"1px solid var(--gray-a4)",transition:"all 0.15s ease",cursor:"pointer"},onClick:()=>u(o),children:[e.jsxs(a,{justify:"between",align:"start",gap:"2",children:[e.jsxs(a,{align:"center",gap:"2",style:{minWidth:0,flex:1},children:[e.jsxs(y,{style:{position:"relative",width:34,height:34,borderRadius:"50%",overflow:"hidden",border:`2px solid ${i?B.active:"var(--gray-7)"}`,background:"var(--gray-a4)",display:"flex",alignItems:"center",justifyContent:"center",color:"white",fontWeight:"bold",fontSize:12,flexShrink:0},children:[o.profile_image_url?e.jsx("img",{src:o.profile_image_url,alt:o.name,style:{width:"100%",height:"100%",objectFit:"cover"}}):((w=(t=o.name)==null?void 0:t.charAt(0))==null?void 0:w.toUpperCase())||"?",e.jsx("span",{style:{position:"absolute",bottom:0,right:0,width:8,height:8,borderRadius:"50%",background:i?B.active:B.completed,border:"1px solid var(--color-surface)"}})]}),e.jsxs(y,{style:{minWidth:0,flex:1},children:[e.jsx(C,{size:"2",weight:"bold",style:{color:"var(--gray-12)",whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis",display:"block"},children:o.name}),e.jsxs(C,{size:"1",color:"gray",style:{whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis",display:"block"},children:[o.designation||"Staff"," ",o.employee_id?`• ${o.employee_id}`:""]})]})]}),e.jsx(G,{size:"1",variant:"soft",color:b?"blue":"gray",onClick:f=>{f.stopPropagation(),u(o)},title:"Fly to marker on map",children:e.jsx(we,{})})]}),e.jsxs(a,{justify:"between",align:"center",mt:"2",pt:"2",style:{borderTop:"1px solid var(--gray-a4)"},children:[e.jsxs(a,{align:"center",gap:"1",children:[e.jsx(oe,{style:{color:"var(--green-9)",width:12,height:12}}),e.jsxs(C,{size:"1",weight:"medium",style:{color:"var(--green-11)"},children:["In: ",n]}),m&&e.jsxs(C,{size:"1",color:"gray",ml:"1",children:["• Out: ",m]})]}),e.jsxs(a,{align:"center",gap:"1",children:[o.punchin_photo_url&&e.jsx(G,{size:"1",variant:"ghost",color:"blue",onClick:f=>{f.stopPropagation(),h({url:o.punchin_photo_url,title:`Check-In Verification: ${o.name}`,timestamp:n,officerName:o.name,employeeId:o.employee_id,designation:o.designation,location:o.punchin_location})},title:"View Check-In Selfie",children:e.jsx(ir,{})}),e.jsxs(D,{size:"1",variant:"surface",color:"gray",onClick:f=>{f.stopPropagation(),z(o)},style:{cursor:"pointer",height:22,fontSize:10,padding:"0 6px"},children:["Telemetry",e.jsx(ar,{})]})]})]})]},o.user_id)})})]}):null});We.displayName="MapTeamRosterDrawer";const De=Z.memo(({officer:r,selectedDate:l,onClose:d,onOpenPhoto:g,onFocusMap:u})=>{var _;const[z,h]=c.useState(null);if(!r)return null;const{name:s,employee_id:x,designation:j,department:o,profile_image_url:b,status:i,cycles:n=[],punchin_time:m,punchout_time:t,punchin_location:w,punchout_location:f,punchin_photo_url:S,punchout_photo_url:T,attendance_type:I}=r,E=i==="active",F=(p,R)=>{p&&(navigator.clipboard.writeText(p),h(R),setTimeout(()=>h(null),2e3))},k=n&&n.length>0?n:[{attendance_id:"default",punchin_time:m,punchout_time:t,punchin_location:w,punchout_location:f,punchin_photo_url:S,punchout_photo_url:T,is_complete:!!t}];return e.jsx(y,{style:{position:"fixed",inset:0,background:"rgba(5, 10, 20, 0.75)",backdropFilter:"blur(8px)",WebkitBackdropFilter:"blur(8px)",zIndex:99990,display:"flex",alignItems:"center",justifyContent:"center",padding:16,animation:"fadeIn 0.2s ease-out"},onClick:d,children:e.jsxs(y,{style:{width:"100%",maxWidth:580,maxHeight:"90vh",background:"var(--color-panel-solid, #1e293b)",borderRadius:"var(--radius-4)",border:"1px solid var(--gray-a6)",boxShadow:"0 25px 60px -15px rgba(0,0,0,0.5)",display:"flex",flexDirection:"column",overflow:"hidden"},onClick:p=>p.stopPropagation(),children:[e.jsx(y,{p:"4",style:{background:"linear-gradient(135deg, var(--gray-a3), var(--gray-a4))",borderBottom:"1px solid var(--gray-a5)"},children:e.jsxs(a,{justify:"between",align:"start",children:[e.jsxs(a,{align:"center",gap:"3",children:[e.jsx(y,{style:{width:52,height:52,borderRadius:"50%",overflow:"hidden",border:`3px solid ${E?B.active:"var(--gray-a7)"}`,background:"var(--gray-a4)",display:"flex",alignItems:"center",justifyContent:"center",color:"white",fontWeight:"bold",fontSize:18,flexShrink:0,boxShadow:E?"0 0 12px rgba(16, 185, 129, 0.4)":"none"},children:b?e.jsx("img",{src:b,alt:s,style:{width:"100%",height:"100%",objectFit:"cover"}}):((_=s==null?void 0:s.charAt(0))==null?void 0:_.toUpperCase())||"?"}),e.jsxs(y,{children:[e.jsxs(a,{align:"center",gap:"2",wrap:"wrap",children:[e.jsx(C,{size:"3",weight:"bold",style:{color:"var(--gray-12)"},children:s||"Officer"}),e.jsx(K,{size:"1",color:E?"green":"blue",variant:"solid",radius:"full",children:E?"🟢 Active On-Duty":"✅ Shift Completed"})]}),e.jsxs(C,{size:"1",color:"gray",children:[j||"Employee"," ",o?`• ${o}`:"",x?` • ID: ${x}`:""]}),I&&e.jsxs(K,{size:"1",color:"purple",variant:"soft",mt:"1",children:["Zone: ",I.name||"Standard"]})]})]}),e.jsx(G,{size:"2",variant:"ghost",color:"gray",onClick:d,style:{cursor:"pointer"},children:e.jsx(ne,{})})]})}),e.jsxs(y,{p:"4",style:{overflowY:"auto",flex:1,display:"flex",flexDirection:"column",gap:16},children:[e.jsxs(a,{justify:"between",align:"center",children:[e.jsxs(C,{size:"2",weight:"bold",style:{color:"var(--gray-11)"},children:["Attendance & Patrol Telemetry (",k.length," ",k.length===1?"Cycle":"Cycles",")"]}),e.jsxs(C,{size:"1",color:"gray",children:["Date: ",l||"Today"]})]}),k.map((p,R)=>{const $=p.punchin_location,M=p.punchout_location,W=$&&$.lat&&$.lng?`${parseFloat($.lat).toFixed(5)}, ${parseFloat($.lng).toFixed(5)}`:null,A=M&&M.lat&&M.lng?`${parseFloat(M.lat).toFixed(5)}, ${parseFloat(M.lng).toFixed(5)}`:null;return e.jsxs(y,{p:"3",style:{background:"var(--gray-a2)",borderRadius:"var(--radius-3)",border:"1px solid var(--gray-a4)"},children:[e.jsxs(a,{justify:"between",align:"center",mb:"3",children:[e.jsxs(K,{size:"1",color:"gray",variant:"surface",children:["Shift Cycle #",R+1]}),e.jsx(K,{size:"1",color:p.is_complete?"blue":"green",variant:"soft",children:p.is_complete?"Cycle Finished":"Active Cycle"})]}),e.jsxs(a,{direction:"column",gap:"3",children:[e.jsxs(a,{align:"start",justify:"between",p:"2",style:{background:"var(--green-a2)",borderRadius:"var(--radius-2)",border:"1px solid var(--green-a4)"},children:[e.jsxs(a,{align:"start",gap:"2",style:{flex:1},children:[e.jsx(y,{style:{width:24,height:24,borderRadius:"50%",background:B.punchin,color:"white",display:"flex",alignItems:"center",justifyContent:"center",flexShrink:0},children:e.jsx(oe,{style:{width:14,height:14}})}),e.jsxs(y,{children:[e.jsxs(C,{size:"1",weight:"bold",style:{color:"var(--green-11)"},children:["Check-In: ",p.punchin_time||"--"]}),W?e.jsxs(a,{align:"center",gap:"1",mt:"1",children:[e.jsx(ve,{style:{color:"var(--green-9)",width:12,height:12}}),e.jsx(C,{size:"1",style:{fontSize:11,fontFamily:"monospace",color:"var(--gray-11)"},children:W}),e.jsx(G,{size:"1",variant:"ghost",style:{height:18,width:18},onClick:()=>F(W,`in-${R}`),children:z===`in-${R}`?e.jsx(de,{}):e.jsx(je,{})})]}):e.jsx(C,{size:"1",color:"gray",style:{fontSize:11},children:"No GPS coordinates"})]})]}),p.punchin_photo_url&&e.jsxs(y,{style:{width:48,height:48,borderRadius:"var(--radius-2)",overflow:"hidden",border:"1px solid var(--green-a6)",cursor:"pointer",position:"relative",flexShrink:0},onClick:()=>g&&g({url:p.punchin_photo_url,officerName:s,designation:j,timestamp:p.punchin_time,location:$,type:"punchin"}),children:[e.jsx("img",{src:p.punchin_photo_url,alt:"Check-in selfie",style:{width:"100%",height:"100%",objectFit:"cover"}}),e.jsx(y,{style:{position:"absolute",bottom:0,insetInline:0,background:"rgba(0,0,0,0.6)",display:"flex",alignItems:"center",justifyContent:"center",padding:1},children:e.jsx(be,{style:{color:"white",width:10,height:10}})})]})]}),p.punchout_time?e.jsxs(a,{align:"start",justify:"between",p:"2",style:{background:"var(--red-a2)",borderRadius:"var(--radius-2)",border:"1px solid var(--red-a4)"},children:[e.jsxs(a,{align:"start",gap:"2",style:{flex:1},children:[e.jsx(y,{style:{width:24,height:24,borderRadius:"50%",background:B.punchout,color:"white",display:"flex",alignItems:"center",justifyContent:"center",flexShrink:0},children:e.jsx(Te,{style:{width:14,height:14}})}),e.jsxs(y,{children:[e.jsxs(C,{size:"1",weight:"bold",style:{color:"var(--red-11)"},children:["Check-Out: ",p.punchout_time]}),A?e.jsxs(a,{align:"center",gap:"1",mt:"1",children:[e.jsx(ve,{style:{color:"var(--red-9)",width:12,height:12}}),e.jsx(C,{size:"1",style:{fontSize:11,fontFamily:"monospace",color:"var(--gray-11)"},children:A}),e.jsx(G,{size:"1",variant:"ghost",style:{height:18,width:18},onClick:()=>F(A,`out-${R}`),children:z===`out-${R}`?e.jsx(de,{}):e.jsx(je,{})})]}):e.jsx(C,{size:"1",color:"gray",style:{fontSize:11},children:"No GPS coordinates"})]})]}),p.punchout_photo_url&&e.jsxs(y,{style:{width:48,height:48,borderRadius:"var(--radius-2)",overflow:"hidden",border:"1px solid var(--red-a6)",cursor:"pointer",position:"relative",flexShrink:0},onClick:()=>g&&g({url:p.punchout_photo_url,officerName:s,designation:j,timestamp:p.punchout_time,location:M,type:"punchout"}),children:[e.jsx("img",{src:p.punchout_photo_url,alt:"Check-out selfie",style:{width:"100%",height:"100%",objectFit:"cover"}}),e.jsx(y,{style:{position:"absolute",bottom:0,insetInline:0,background:"rgba(0,0,0,0.6)",display:"flex",alignItems:"center",justifyContent:"center",padding:1},children:e.jsx(be,{style:{color:"white",width:10,height:10}})})]})]}):e.jsxs(a,{align:"center",gap:"2",p:"2",style:{background:"var(--gray-a3)",borderRadius:"var(--radius-2)",border:"1px dashed var(--gray-a5)"},children:[e.jsx(oe,{style:{color:"var(--amber-9)"}}),e.jsx(C,{size:"1",color:"gray",children:"Officer is currently on active patrol. Check-out not recorded yet."})]})]})]},R)})]}),e.jsx(y,{p:"3",style:{background:"var(--gray-a2)",borderTop:"1px solid var(--gray-a4)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"2",children:[e.jsxs(D,{variant:"surface",color:"blue",size:"2",onClick:()=>{if(d(),u){const p=w||f;p&&p.lat&&p.lng&&u([parseFloat(p.lat),parseFloat(p.lng)])}},children:[e.jsx(we,{})," Focus on Map"]}),e.jsx(D,{variant:"outline",color:"gray",size:"2",onClick:d,children:"Close"})]})})]})})});De.displayName="OfficerDetailModal";const Be=Z.memo(({photoData:r,onClose:l})=>{const[d,g]=Z.useState(!1);if(!r||!r.url)return null;const{url:u,title:z,officerName:h,designation:s,timestamp:x,location:j,type:o}=r,b=j&&j.lat&&j.lng?`${parseFloat(j.lat).toFixed(6)}, ${parseFloat(j.lng).toFixed(6)}`:null,i=()=>{b&&(navigator.clipboard.writeText(b),g(!0),setTimeout(()=>g(!1),2e3))};return e.jsxs(y,{style:{position:"fixed",inset:0,background:"rgba(0, 0, 0, 0.85)",backdropFilter:"blur(16px)",WebkitBackdropFilter:"blur(16px)",zIndex:99999,display:"flex",alignItems:"center",justifyContent:"center",padding:24,animation:"fadeIn 0.2s ease-out"},onClick:l,children:[e.jsx(G,{size:"3",variant:"solid",color:"gray",highContrast:!0,style:{position:"absolute",top:24,right:24,borderRadius:"50%",cursor:"pointer",zIndex:10},onClick:n=>{n.stopPropagation(),l()},"aria-label":"Close photo preview",children:e.jsx(ne,{style:{width:22,height:22}})}),e.jsxs(y,{style:{maxWidth:"90vw",maxHeight:"90vh",display:"flex",flexDirection:"column",alignItems:"center",background:"var(--color-panel-solid, var(--color-surface, #ffffff))",border:"1px solid var(--gray-a5)",borderRadius:"var(--radius-4)",boxShadow:"var(--shadow-6, 0 25px 60px -15px rgba(0, 0, 0, 0.5))",overflow:"hidden"},onClick:n=>n.stopPropagation(),children:[e.jsx(y,{p:"3",style:{width:"100%",borderBottom:"1px solid var(--gray-a4)",background:"var(--gray-a2)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",px:"2",children:[e.jsxs(a,{align:"center",gap:"2",children:[e.jsx(te,{style:{color:"var(--blue-9)",width:18,height:18}}),e.jsxs(y,{children:[e.jsx(C,{size:"2",weight:"bold",style:{color:"var(--gray-12)"},children:h||"Officer Photo"}),s&&e.jsx(C,{size:"1",color:"gray",style:{display:"block"},children:s})]})]}),e.jsx(K,{size:"1",color:o==="punchin"?"green":o==="punchout"?"red":"blue",variant:"solid",children:o==="punchin"?"Check-In Photo":o==="punchout"?"Check-Out Photo":z||"Verification Selfie"})]})}),e.jsx(y,{style:{display:"flex",alignItems:"center",justifyContent:"center",padding:16,maxHeight:"65vh",minWidth:320,maxWidth:720,overflow:"hidden"},children:e.jsx("img",{src:u,alt:"Officer Telemetry Verification",style:{maxWidth:"100%",maxHeight:"60vh",objectFit:"contain",borderRadius:"var(--radius-3)",border:"1px solid var(--gray-a4)",boxShadow:"var(--shadow-4)"}})}),e.jsx(y,{p:"3",style:{width:"100%",borderTop:"1px solid var(--gray-a4)",background:"var(--gray-a2)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",wrap:"wrap",px:"2",children:[e.jsxs(a,{align:"center",gap:"4",wrap:"wrap",children:[x&&e.jsxs(a,{align:"center",gap:"1",children:[e.jsx(oe,{style:{color:"var(--purple-9)",width:14,height:14}}),e.jsx(C,{size:"1",style:{color:"var(--gray-12)"},children:x})]}),b&&e.jsxs(a,{align:"center",gap:"2",children:[e.jsx(ve,{style:{color:"var(--green-9)",width:14,height:14}}),e.jsx(C,{size:"1",style:{color:"var(--gray-12)",fontFamily:"monospace"},children:b}),e.jsxs(D,{size:"1",variant:"ghost",color:"gray",style:{cursor:"pointer",padding:"0 4px",height:20},onClick:i,children:[d?e.jsx(de,{style:{color:"var(--green-9)"}}):e.jsx(je,{}),e.jsx("span",{style:{fontSize:10},children:d?"Copied":"Copy"})]})]})]}),e.jsx("a",{href:u,target:"_blank",rel:"noopener noreferrer",download:!0,style:{textDecoration:"none"},children:e.jsxs(D,{size:"1",variant:"soft",color:"blue",style:{cursor:"pointer"},children:[e.jsx(sr,{}),"Download HD"]})})]})})]})]})});Be.displayName="PhotoTelemetryLightbox";const xr=Z.memo(({selectedDate:r,updateMap:l})=>{const[d,g]=c.useState([]),[u,z]=c.useState([]),[h,s]=c.useState(!0),[x,j]=c.useState(!1),[o,b]=c.useState(null),[i,n]=c.useState(""),m=rr({mode:"client",debounceKeys:[],defaults:{loc_status:"all"}}),t=m.values.loc_status,w=v=>m.set("loc_status",v),[f,S]=c.useState(()=>localStorage.getItem("guardian_map_tile_id")||"voyager"),[T,I]=c.useState({geofences:!0,waypoints:!0,trajectories:!0}),[E,F]=c.useState(!0),[k,_]=c.useState(!1),[p,R]=c.useState(null),[$,M]=c.useState(null),[W,A]=c.useState(null),[q,N]=c.useState(0),[U,H]=c.useState(null),[J,ie]=c.useState(!0),[ue,ee]=c.useState(xe),re=c.useRef(null);c.useRef(null);const Ge=c.useCallback(v=>{S(v),localStorage.setItem("guardian_map_tile_id",v)},[]),Ze=c.useCallback(v=>{I(P=>({...P,[v]:!P[v]}))},[]),Q=c.useCallback(async(v=!1)=>{if(r){v?j(!0):s(!0);try{const P=route("getUserLocationsForDate",{date:r.split("T")[0],_t:Date.now()}),V=await fetch(P);if(!V.ok)throw new Error(`HTTP ${V.status}: Failed to fetch user locations`);const Y=await V.json(),se=Array.isArray(Y.locations)?Y.locations:[],X=Array.isArray(Y.attendance_type_configs)?Y.attendance_type_configs:[];g(se),z(X),b(new Date),ee(xe)}catch(P){console.error("Failed to load team locations:",P)}finally{s(!1),j(!1)}}},[r]);c.useEffect(()=>{Q(!1)},[r,l,Q]),c.useEffect(()=>{if(!J)return;const v=setInterval(()=>{ee(P=>P<=1?(Q(!0),xe):P-1)},1e3);return()=>clearInterval(v)},[J,Q]);const Ce=c.useMemo(()=>{const v=d.length;let P=0,V=0;return d.forEach(Y=>{Y.status==="active"?P++:V++}),{total:v,checkedIn:P,active:P,completed:V}},[d]),ae=c.useMemo(()=>d.filter(v=>{var P,V,Y,se;if(t==="active"&&v.status!=="active"||t==="completed"&&v.status==="active")return!1;if(i){const X=i.toLowerCase(),Je=(P=v.name)==null?void 0:P.toLowerCase().includes(X),Qe=(V=v.employee_id)==null?void 0:V.toLowerCase().includes(X),Ke=(Y=v.designation)==null?void 0:Y.toLowerCase().includes(X),Xe=(se=v.department)==null?void 0:se.toLowerCase().includes(X);if(!Je&&!Qe&&!Ke&&!Xe)return!1}return!0}),[d,t,i]),He=c.useMemo(()=>o?o.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:!0}):null,[o]),_e=c.useMemo(()=>{if(!r)return"Invalid Date";try{return new Date(r).toLocaleString("en-US",{weekday:"long",year:"numeric",month:"long",day:"numeric"})}catch{return r}},[r]),ze=c.useCallback(v=>{R(v.user_id);const P=v.punchin_location||v.punchout_location||v.location;P&&P.lat&&P.lng&&H([parseFloat(P.lat),parseFloat(P.lng)])},[]),qe=c.useCallback(v=>{H(v)},[]),Ve=c.useCallback(()=>{N(v=>v+1)},[]),Ye=c.useCallback(()=>{re.current&&(document.fullscreenElement?(document.exitFullscreen(),_(!1)):(re.current.requestFullscreen().catch(v=>{console.warn("Fullscreen error:",v)}),_(!0)))},[]);return c.useEffect(()=>{const v=()=>{_(!!document.fullscreenElement)};return document.addEventListener("fullscreenchange",v),()=>document.removeEventListener("fullscreenchange",v)},[]),e.jsxs(y,{children:[e.jsxs(tr,{mb:"4",children:[e.jsx(y,{p:"4",style:{borderBottom:"1px solid var(--gray-a4)"},children:e.jsxs(a,{justify:"between",align:"center",gap:"3",wrap:"wrap",children:[e.jsxs(a,{align:"center",gap:"3",children:[e.jsx(y,{style:{padding:10,borderRadius:"var(--radius-3)",background:"linear-gradient(135deg, var(--blue-a3), var(--blue-a4))",border:"1px solid var(--blue-a6)",width:44,height:44,flexShrink:0,display:"flex",alignItems:"center",justifyContent:"center",boxShadow:"0 2px 8px rgba(0,0,0,0.06)"},children:e.jsx(me,{style:{color:"var(--blue-9)",width:22,height:22}})}),e.jsxs(y,{children:[e.jsx(Ee,{size:"4",style:{letterSpacing:"-0.02em"},children:"Team Locations & Live GIS Command Center"}),e.jsx(C,{size:"2",color:"gray",children:_e})]})]}),e.jsxs(D,{variant:"surface",size:"1",color:"blue",onClick:()=>Q(!1),disabled:h||x,style:{cursor:"pointer"},children:[e.jsx(ye,{className:h||x?"animate-spin":""}),"Refresh Live Feed"]})]})}),e.jsx(Pe,{stats:Ce,lastUpdateText:He,isPolling:J,secondsLeft:ue}),e.jsx(y,{p:"4",children:h?e.jsx(a,{align:"center",justify:"center",style:{height:"72vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)",background:"var(--gray-a2)"},children:e.jsxs(a,{direction:"column",align:"center",gap:"3",children:[e.jsx(er,{size:"3"}),e.jsx(C,{size:"2",weight:"medium",color:"gray",children:"Loading team coordinates & GIS boundaries..."})]})}):d.length===0?e.jsxs(a,{direction:"column",align:"center",justify:"center",gap:"3",p:"6",style:{height:"72vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)",background:"var(--gray-a2)"},children:[e.jsx(me,{style:{width:64,height:64,color:"var(--gray-7)"}}),e.jsx(Ee,{size:"4",children:"No Team Location Records Found"}),e.jsxs(C,{size:"2",color:"gray",align:"center",style:{maxWidth:420},children:["No check-in or patrol coordinates recorded for ",_e,". Ensure team members have logged attendance via mobile GPS or check a different date."]}),e.jsxs(D,{variant:"outline",onClick:()=>Q(!1),children:[e.jsx(ye,{})," Refresh Data"]})]}):e.jsxs(y,{ref:re,style:{position:"relative",height:k?"100vh":"72vh",borderRadius:k?0:"var(--radius-3)",overflow:"hidden",border:k?"none":"1px solid var(--gray-a5)",boxShadow:"0 8px 30px rgba(0,0,0,0.12)"},children:[e.jsx(Me,{searchQuery:i,onSearchChange:n,statusFilter:t,onStatusFilterChange:w,stats:Ce,currentTileId:f,onTileChange:Ge,layerVisibility:T,onToggleLayer:Ze,onFitBounds:Ve,onRefresh:()=>Q(!0),isRefreshing:x,isDrawerOpen:E,onToggleDrawer:()=>F(v=>!v),isFullscreen:k,onToggleFullscreen:Ye}),e.jsxs(Oe,{currentTileId:f,users:ae,attendanceTypeConfigs:u,fitBoundsTrigger:q,flyToCoords:U,children:[e.jsx(Ne,{attendanceTypeConfigs:u,users:ae,layerVisibility:T}),e.jsx(Ue,{users:ae,selectedUserId:p,onSelectOfficer:ze,onOpenTelemetry:M,onOpenPhoto:A,layerVisibility:T})]}),e.jsx(We,{isOpen:E,onClose:()=>F(!1),users:ae,selectedUserId:p,onSelectOfficer:ze,onOpenTelemetry:M,onOpenPhoto:A})]})})]}),$&&e.jsx(De,{officer:$,selectedDate:r,onClose:()=>M(null),onOpenPhoto:A,onFocusMap:qe}),W&&e.jsx(Be,{photoData:W,onClose:()=>A(null)})]})});xr.displayName="UserLocationsCard";export{xr as U};
