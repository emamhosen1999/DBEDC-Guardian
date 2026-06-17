import{j as o,p as M,o as W,b as z,E as q,d as A,D as Q,s as X,a as ee}from"./vendor-radix-W2H-9_hK.js";import{R as H,a as f}from"./vendor-inertia-B_jPe_ra.js";import"./dayjs.min-Ca8ckx1t.js";import"./useObjectionsListState-GCsTGlcr.js";import{i as K,P as te,a as ne,b as oe,R as re,m as se}from"./react-icons.esm-BZz3CXXS.js";import{L as v}from"./leaflet-CpeWTaXg.js";import"./leaflet-routing-machine-CqCZn3Dx.js";import{b as Z,M as ie,T as le}from"./TileLayer-OsRTXKLT.js";import"./ErrorBoundary-Cjt5z8KV.js";import"./MonthlyCalendarTab-D9nvL9V1.js";import"./vendor-utils-D6Wd6ilh.js";L.Control.Fullscreen=L.Control.extend({options:{position:"topleft",title:{false:"View Fullscreen",true:"Exit Fullscreen"}},onAdd:function(r){var n=L.DomUtil.create("div","leaflet-control-fullscreen leaflet-bar leaflet-control");return this.link=L.DomUtil.create("a","leaflet-control-fullscreen-button leaflet-bar-part",n),this.link.href="#",this._map=r,this._map.on("fullscreenchange",this._toggleTitle,this),this._toggleTitle(),L.DomEvent.on(this.link,"click",this._click,this),n},_click:function(r){L.DomEvent.stopPropagation(r),L.DomEvent.preventDefault(r),this._map.toggleFullscreen(this.options)},_toggleTitle:function(){this.link.title=this.options.title[this._map.isFullscreen()]}});L.Map.include({isFullscreen:function(){return this._isFullscreen||!1},toggleFullscreen:function(r){var n=this.getContainer();this.isFullscreen()?r&&r.pseudoFullscreen?this._disablePseudoFullscreen(n):document.exitFullscreen?document.exitFullscreen():document.mozCancelFullScreen?document.mozCancelFullScreen():document.webkitCancelFullScreen?document.webkitCancelFullScreen():document.msExitFullscreen?document.msExitFullscreen():this._disablePseudoFullscreen(n):r&&r.pseudoFullscreen?this._enablePseudoFullscreen(n):n.requestFullscreen?n.requestFullscreen():n.mozRequestFullScreen?n.mozRequestFullScreen():n.webkitRequestFullscreen?n.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT):n.msRequestFullscreen?n.msRequestFullscreen():this._enablePseudoFullscreen(n)},_enablePseudoFullscreen:function(r){L.DomUtil.addClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!0),this.fire("fullscreenchange")},_disablePseudoFullscreen:function(r){L.DomUtil.removeClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!1),this.fire("fullscreenchange")},_setFullscreen:function(r){this._isFullscreen=r;var n=this.getContainer();r?L.DomUtil.addClass(n,"leaflet-fullscreen-on"):L.DomUtil.removeClass(n,"leaflet-fullscreen-on"),this.invalidateSize()},_onFullscreenChange:function(r){var n=document.fullscreenElement||document.mozFullScreenElement||document.webkitFullscreenElement||document.msFullscreenElement;n===this.getContainer()&&!this._isFullscreen?(this._setFullscreen(!0),this.fire("fullscreenchange")):n!==this.getContainer()&&this._isFullscreen&&(this._setFullscreen(!1),this.fire("fullscreenchange"))}});L.Map.mergeOptions({fullscreenControl:!1});L.Map.addInitHook(function(){this.options.fullscreenControl&&(this.fullscreenControl=new L.Control.Fullscreen(this.options.fullscreenControl),this.addControl(this.fullscreenControl));var r;if("onfullscreenchange"in document?r="fullscreenchange":"onmozfullscreenchange"in document?r="mozfullscreenchange":"onwebkitfullscreenchange"in document?r="webkitfullscreenchange":"onmsfullscreenchange"in document&&(r="MSFullscreenChange"),r){var n=L.bind(this._onFullscreenChange,this);this.whenReady(function(){L.DomEvent.on(document,r,n)}),this.on("unload",function(){L.DomEvent.off(document,r,n)})}});L.control.fullscreen=function(r){return new L.Control.Fullscreen(r)};const I=(r,n)=>{if(r.startsWith("var("))return`color-mix(in srgb, ${r} ${n*100}%, transparent)`;if(r.startsWith("#")){const l=r.replace("#",""),d=parseInt(l.substr(0,2),16),k=parseInt(l.substr(2,2),16),g=parseInt(l.substr(4,2),16);return`rgba(${d}, ${k}, ${g}, ${n})`}return r.replace(/[\d.]+\)$/g,`${n})`)},F={DEFAULT_ZOOM:12,MIN_ZOOM:8,MAX_ZOOM:19,POSITION_THRESHOLD:1e-4,OFFSET_MULTIPLIER:1e-4,MARKER_SIZE:[40,40],POPUP_MAX_WIDTH:160},G={lat:23.8103,lng:90.4125},J=H.memo(({attendanceTypeConfigs:r,theme:n})=>{const l=Z(),d=f.useRef([]);return f.useEffect(()=>{if(!l||!(r!=null&&r.length))return;l.eachLayer(g=>{g.options&&(g.options.isAttendanceBoundary||g.options.isPolygon||g.options.isRoute)&&l.removeLayer(g)}),d.current.forEach(g=>{try{l.removeControl(g)}catch(m){console.warn("Error removing routing control:",m)}}),d.current=[],l.getContainer().querySelectorAll(".leaflet-routing-container").forEach(g=>g.remove());const k=[];if(r.forEach((g,m)=>{var s;const{base_slug:b,config:C,name:P}=g;(s=n==null?void 0:n.customColors)!=null&&s.primary;const e=["#3b82f6","#10b981","#f59e0b","#ef4444","#8b5cf6","#ec4899"],t=e[m%e.length];if(b==="geo_polygon"&&C){const y=C.polygon||[],x=C.polygons||[];if(y.length>=3){const c=y.filter(a=>a.lat&&a.lng);if(c.length>=3){const a=c.map(p=>[parseFloat(p.lat),parseFloat(p.lng)]),i=v.polygon(a,{color:t,fillColor:t,fillOpacity:.15,weight:2,opacity:.7,isAttendanceBoundary:!0,isPolygon:!0}).addTo(l);i.bindPopup(`
                            <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                <strong style="color: ${t};">${P}</strong><br>
                                <small>Geofence Zone</small><br>
                                <small>Points: ${c.length}</small>
                            </div>
                        `),k.push(i.getBounds())}}x.forEach((c,a)=>{const i=c.points||[];if(i.length>=3){const p=i.filter(h=>h.lat&&h.lng);if(p.length>=3){const h=p.map(w=>[parseFloat(w.lat),parseFloat(w.lng)]),u=v.polygon(h,{color:t,fillColor:t,fillOpacity:.15,weight:2,opacity:.7,isAttendanceBoundary:!0,isPolygon:!0}).addTo(l);u.bindPopup(`
                                <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                    <strong style="color: ${t};">${P}</strong><br>
                                    <small>${c.name||`Zone ${a+1}`}</small>
                                </div>
                            `),k.push(u.getBounds())}}})}if(b==="route_waypoint"&&C){const y=C.waypoints||[],x=C.routes||[];if(y.length>=2){const c=y.filter(a=>a.lat&&a.lng);if(c.length>=2){const a=c.map(i=>v.latLng(parseFloat(i.lat),parseFloat(i.lng)));c.forEach((i,p)=>{const h=p===0,u=p===c.length-1,w=`
                                <div style="
                                    width: 24px;
                                    height: 24px;
                                    border-radius: 50%;
                                    background: ${h?"#10b981":u?"#ef4444":t};
                                    border: 2px solid white;
                                    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    color: white;
                                    font-weight: bold;
                                    font-size: 11px;
                                ">
                                    ${p+1}
                                </div>
                            `;v.marker([parseFloat(i.lat),parseFloat(i.lng)],{icon:v.divIcon({html:w,className:"route-waypoint-marker",iconSize:[24,24],iconAnchor:[12,12]}),isAttendanceBoundary:!0}).addTo(l).bindPopup(`
                                <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                    <strong style="color: ${t};">${P}</strong><br>
                                    <small>Waypoint ${p+1}${i.name?`: ${i.name}`:""}</small>
                                </div>
                            `)});try{const i=v.Routing.control({waypoints:a,routeWhileDragging:!1,addWaypoints:!1,createMarker:()=>null,lineOptions:{styles:[{color:t,weight:4,opacity:.7,dashArray:"8, 4"}],extendToWaypoints:!0,missingRouteTolerance:0},show:!1,fitSelectedRoutes:!1,router:v.Routing.osrmv1({serviceUrl:"https://router.project-osrm.org/route/v1"})}).addTo(l);d.current.push(i);const p=v.latLngBounds(a);k.push(p)}catch(i){console.warn("Error creating route:",i);const p=v.polyline(a,{color:t,weight:3,opacity:.6,dashArray:"10, 10",isAttendanceBoundary:!0,isRoute:!0}).addTo(l);k.push(p.getBounds())}}}x.forEach((c,a)=>{const i=c.waypoints||[];if(i.length>=2){const p=i.filter(h=>h.lat&&h.lng);if(p.length>=2){const h=p.map(u=>v.latLng(parseFloat(u.lat),parseFloat(u.lng)));try{const u=v.Routing.control({waypoints:h,routeWhileDragging:!1,addWaypoints:!1,createMarker:()=>null,lineOptions:{styles:[{color:t,weight:4,opacity:.7,dashArray:"8, 4"}]},show:!1,fitSelectedRoutes:!1,router:v.Routing.osrmv1({serviceUrl:"https://router.project-osrm.org/route/v1"})}).addTo(l);d.current.push(u),k.push(v.latLngBounds(h))}catch(u){console.warn("Error creating route:",u)}}}})}}),k.length>0){const g=k.reduce((m,b)=>m?m.extend(b):b,null);g&&g.isValid()&&setTimeout(()=>{try{l&&l._container&&l.fitBounds(g,{padding:[50,50],maxZoom:14})}catch{}},500)}return()=>{d.current.forEach(g=>{try{g&&l&&l._container&&l.removeControl(g)}catch{}}),d.current=[]}},[l,r,n]),null});J.displayName="AttendanceTypeBoundaries";const ae=H.memo(({startLocation:r,endLocation:n,theme:l})=>{const d=Z();return f.useEffect(()=>{var g;if(!d||!r||!n)return;const k=v.Routing.control({waypoints:[v.latLng(r.lat,r.lng),v.latLng(n.lat,n.lng)],routeWhileDragging:!1,addWaypoints:!1,createMarker:()=>null,lineOptions:{styles:[{color:((g=l==null?void 0:l.customColors)==null?void 0:g.primary)||"var(--theme-primary, #3b82f6)",weight:4,opacity:.8}]},show:!1}).addTo(d);return()=>{if(d&&k&&d._container)try{d.removeControl(k)}catch{}}},[d,r,n,l]),null});ae.displayName="RoutingMachine";const V=H.memo(({selectedDate:r,theme:n,users:l})=>{const d=Z(),k=f.useCallback((e,t)=>{const s=F.OFFSET_MULTIPLIER*t;return{lat:e.lat+s,lng:e.lng+s}},[]),g=f.useCallback((e,t)=>Math.abs(e.lat-t.lat)<F.POSITION_THRESHOLD&&Math.abs(e.lng-t.lng)<F.POSITION_THRESHOLD,[]),m=f.useCallback(e=>{if(!e)return null;if(typeof e=="object"&&e.lat&&e.lng){const t=parseFloat(e.lat),s=parseFloat(e.lng);return isNaN(t)||isNaN(s)?null:{lat:t,lng:s}}if(typeof e=="string")try{const t=JSON.parse(e);if(t.lat&&t.lng){const s=parseFloat(t.lat),y=parseFloat(t.lng);return isNaN(s)||isNaN(y)?null:{lat:s,lng:y}}}catch{const s=e.split(",");if(s.length>=2){const y=parseFloat(s[0].trim()),x=parseFloat(s[1].trim());return isNaN(y)||isNaN(x)?null:{lat:y,lng:x}}}return null},[]),b=f.useCallback(e=>{if(!e)return"Not recorded";try{let t;return e.includes("T")?t=new Date(e):t=new Date(`${r}T${e}`),isNaN(t.getTime())?"Invalid time":t.toLocaleTimeString("en-US",{hour:"numeric",minute:"2-digit",hour12:!0})}catch(t){return console.warn("Error formatting time:",t),e}},[r]),C=f.useCallback((e,t="default")=>{var u,w,j,$;const s="var(--theme-primary, #3b82f6)",y="var(--theme-secondary, #8b5cf6)",x="var(--theme-success, #17C964)",c="var(--theme-danger, #ef4444)";let a,i,p="";t==="punchin"?(a=`${x}, #059669`,i=I(x,.4),p='<span style="position: absolute; top: -4px; right: -4px; font-size: 10px;">▶</span>'):t==="punchout"?(a=`${c}, #dc2626`,i=I(c,.4),p='<span style="position: absolute; top: -4px; right: -4px; font-size: 10px;">◼</span>'):(a=`${s}, ${y}`,i=I(s,.4));const h=`
            <div style="
                position: relative;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: linear-gradient(135deg, ${a});
                border: 3px solid white;
                box-shadow: 0 4px 12px ${i};
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                font-size: 14px;
            ">
                ${e.profile_image_url||e.profile_image?`<img src="${e.profile_image_url||e.profile_image}" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${((w=(u=e.name)==null?void 0:u.charAt(0))==null?void 0:w.toUpperCase())||"?"}';" />`:(($=(j=e.name)==null?void 0:j.charAt(0))==null?void 0:$.toUpperCase())||"?"}
                ${p}
            </div>
        `;return v.divIcon({html:h,className:"user-marker-icon",iconSize:F.MARKER_SIZE,iconAnchor:[20,20],popupAnchor:[0,-20]})},[]),P=f.useCallback((e,t="combined")=>{var U,S,N,_;const s=e.punchout_time?"var(--theme-success, #17C964)":"var(--theme-warning, #F5A524)",y="var(--theme-primary, #3b82f6)",x="var(--theme-secondary, #8b5cf6)",c="var(--theme-success, #17C964)",a="var(--theme-danger, #ef4444)",i="var(--theme-content1, #ffffff)",p="var(--theme-foreground, #1f2937)",h="var(--theme-content3, #6b7280)";let u=null,w="";t==="punchin"&&e.punchin_photo_url?(u=e.punchin_photo_url,w="Check In Photo"):t==="punchout"&&e.punchout_photo_url&&(u=e.punchout_photo_url,w="Check Out Photo");const j=u?`
            <div style="margin-top: 6px; margin-bottom: 6px; overflow: hidden; border-radius: 4px;">
                <div style="color: ${h}; font-size: 8px; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px;">
                    ${w}
                </div>
                <img 
                    src="${u}" 
                    data-fullscreen-photo="${u}"
                    style="
                        width: 100%; 
                        height: auto;
                        max-height: 100px;
                        object-fit: contain; 
                        border-radius: 4px;
                        border: 1px solid ${I(y,.2)};
                        display: block;
                        cursor: pointer;
                    " 
                    onmouseover="this.style.opacity='0.85'"
                    onmouseout="this.style.opacity='1'"
                    onerror="this.style.display='none';"
                    title="Click to view full screen"
                />
            </div>
        `:"";let $="",R=y;t==="punchin"?($=`
                <div style="
                    display: inline-block;
                    padding: 1px 4px;
                    background: ${I(c,.1)};
                    color: ${c};
                    border-radius: 3px;
                    font-size: 7px;
                    font-weight: 600;
                    margin-left: 4px;
                    border: 1px solid ${I(c,.2)};
                ">CHECK IN</div>
            `,R=c):t==="punchout"&&($=`
                <div style="
                    display: inline-block;
                    padding: 1px 4px;
                    background: ${I(a,.1)};
                    color: ${a};
                    border-radius: 3px;
                    font-size: 7px;
                    font-weight: 600;
                    margin-left: 4px;
                    border: 1px solid ${I(a,.2)};
                ">CHECK OUT</div>
            `,R=a);let O="";return t==="punchin"?O=`
                <div style="display: flex; align-items: center;">
                    <span style="color: ${c}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${h}; font-size: 9px;">
                        Time: ${b(e.punchin_time)}
                    </span>
                </div>
            `:t==="punchout"?O=`
                <div style="display: flex; align-items: center;">
                    <span style="color: ${a}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${h}; font-size: 9px;">
                        Time: ${b(e.punchout_time)}
                    </span>
                </div>
            `:O=`
                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                    <span style="color: ${c}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${h}; font-size: 9px;">
                        Check In: ${b(e.punchin_time)}
                    </span>
                </div>
                <div style="display: flex; align-items: center;">
                    <span style="color: ${a}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${h}; font-size: 9px;">
                        Check Out: ${b(e.punchout_time)}
                    </span>
                </div>
            `,`
            <div style="
                min-width: 140px;
                max-width: 160px;
                padding: 8px;
                background: linear-gradient(135deg, ${I(i,.95)}, ${I(R,.05)});
                border-radius: 8px;
                border: 1px solid ${I(R,.2)};
                backdrop-filter: blur(20px);
                overflow: hidden;
            ">
                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                    <div style="
                        width: 22px;
                        height: 22px;
                        min-width: 22px;
                        border-radius: 50%;
                        background: linear-gradient(135deg, ${t==="punchin"?c:t==="punchout"?a:y}, ${x});
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-weight: bold;
                        font-size: 10px;
                        margin-right: 6px;
                    ">
                        ${e.profile_image_url||e.profile_image?`<img src="${e.profile_image_url||e.profile_image}" style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${((S=(U=e.name)==null?void 0:U.charAt(0))==null?void 0:S.toUpperCase())||"?"}';" />`:((_=(N=e.name)==null?void 0:N.charAt(0))==null?void 0:_.toUpperCase())||"?"}
                    </div>
                    <div style="flex: 1; min-width: 0; overflow: hidden;">
                        <div style="display: flex; align-items: center; flex-wrap: wrap;">
                            <span style="font-weight: 600; color: ${p}; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70px;">
                                ${e.name||"Unknown"}
                            </span>
                            ${$}
                        </div>
                        <div style="color: ${h}; font-size: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            ${e.designation||"No designation"}
                        </div>
                    </div>
                </div>
                ${t==="combined"?`
                <div style="
                    display: inline-block;
                    padding: 2px 4px;
                    background: ${I(s,.1)};
                    color: ${s};
                    border-radius: 4px;
                    font-size: 8px;
                    font-weight: 600;
                    margin-bottom: 6px;
                    border: 1px solid ${I(s,.2)};
                ">
                    ${e.punchout_time?"✓ Completed":"⏱ Active"}
                </div>`:""}
                ${j}
                <div style="space-y: 4px;">
                    ${O}
                </div>
            </div>
        `},[b]);return f.useEffect(()=>{if(!d||!l.length)return;d.eachLayer(s=>{(s instanceof v.Marker&&s.options.userData||s instanceof v.Polyline&&s.options.userRoute)&&d.removeLayer(s)});const e=[],t=s=>{let y={...s},x=0;const c=10;for(;x<c&&e.some(i=>g(y,i));)y=k(s,x+1),x++;return e.push(y),y};l.forEach(s=>{const y=s.cycles||[];if(y.length>0)y.forEach((x,c)=>{const a=m(x.punchin_location),i=m(x.punchout_location);if(!a&&!i)return;const p=a&&i&&x.is_complete,h={...s,punchin_time:x.punchin_time,punchout_time:x.punchout_time,punchin_photo_url:x.punchin_photo_url,punchout_photo_url:x.punchout_photo_url};if(p){const u=t(a),w=t(i),j=v.marker([u.lat,u.lng],{icon:C(s,"punchin"),userData:!0});j.bindPopup(P(h,"punchin"),{maxWidth:F.POPUP_MAX_WIDTH,className:"custom-popup"}),j.addTo(d);const $=v.marker([w.lat,w.lng],{icon:C(s,"punchout"),userData:!0});$.bindPopup(P(h,"punchout"),{maxWidth:F.POPUP_MAX_WIDTH,className:"custom-popup"}),$.addTo(d),v.polyline([[u.lat,u.lng],[w.lat,w.lng]],{color:"var(--theme-primary, #3b82f6)",weight:3,opacity:.7,dashArray:"10, 10",userRoute:!0}).addTo(d)}else{const w=t(a||i),j=a?"punchin":"punchout",$=v.marker([w.lat,w.lng],{icon:C(s,j),userData:!0});$.bindPopup(P(h,j),{maxWidth:F.POPUP_MAX_WIDTH,className:"custom-popup"}),$.addTo(d)}});else{const x=m(s.punchin_location),c=m(s.punchout_location);if(!x&&!c)return;if(x&&c&&s.punchout_time){const i=t(x),p=t(c),h=v.marker([i.lat,i.lng],{icon:C(s,"punchin"),userData:!0});h.bindPopup(P(s,"punchin"),{maxWidth:F.POPUP_MAX_WIDTH,className:"custom-popup"}),h.addTo(d);const u=v.marker([p.lat,p.lng],{icon:C(s,"punchout"),userData:!0});u.bindPopup(P(s,"punchout"),{maxWidth:F.POPUP_MAX_WIDTH,className:"custom-popup"}),u.addTo(d),v.polyline([[i.lat,i.lng],[p.lat,p.lng]],{color:"var(--theme-primary, #3b82f6)",weight:3,opacity:.7,dashArray:"10, 10",userRoute:!0}).addTo(d)}else{const p=t(x||c),h=x?"punchin":"punchout",u=v.marker([p.lat,p.lng],{icon:C(s,h),userData:!0});u.bindPopup(P(s,h),{maxWidth:F.POPUP_MAX_WIDTH,className:"custom-popup"}),u.addTo(d)}}})},[d,l,n,m,g,k,C,P]),null});V.displayName="UserMarkers";const ce=r=>f.useMemo(()=>{const n=r.reduce((m,b)=>{const C=b.user_id;return m[C]||(m[C]=[]),m[C].push(b),m},{}),l=Object.keys(n),d=l.length;let k=0,g=0;return l.forEach(m=>{const b=n[m];b.sort((e,t)=>e.punchin_time?t.punchin_time?e.punchin_time.localeCompare(t.punchin_time):-1:1);const C=b[b.length-1];b.some(e=>e.punchin_time)&&(C.punchout_time?g++:k++)}),{checkedIn:k,completed:g,total:d}},[r]),_e=H.memo(({updateMap:r,selectedDate:n})=>{const[l,d]=f.useState([]),[k,g]=f.useState([]),[m,b]=f.useState(!0),[C,P]=f.useState(null),[e,t]=f.useState(null),[s,y]=f.useState(!1),[x,c]=f.useState(null),[a,i]=f.useState(!0),[p,h]=f.useState(0),[u,w]=f.useState(new Date),j=f.useRef([]),$=f.useRef(null);f.useRef(null),f.useEffect(()=>{const _=T=>{var D;const E=(D=T.target.dataset)==null?void 0:D.fullscreenPhoto;E&&t(E)};return document.addEventListener("click",_),()=>{document.removeEventListener("click",_)}},[]);const R=f.useCallback(async()=>{b(!0);try{const _=route("getUserLocationsForDate",{date:n,_t:Date.now()});if(!n){d([]),g([]),j.current=[],h(B=>B+1),w(new Date),c(new Date),y(!0);return}const T=await fetch(_);if(!T.ok)throw new Error(`HTTP ${T.status}: Failed to refresh user locations`);const E=await T.json(),D=Array.isArray(E.locations)?E.locations:[],Y=Array.isArray(E.attendance_type_configs)?E.attendance_type_configs:[];d(D),g(Y),j.current=D,h(B=>B+1),w(new Date),c(new Date),y(!0)}catch(_){console.error("Error refreshing map:",_),d([]),g([]),j.current=[],y(!0)}finally{b(!1)}},[n]),O=f.useMemo(()=>{if(!n)return"Invalid Date";try{return new Date(n).toLocaleString("en-US",{weekday:"long",year:"numeric",month:"long",day:"numeric"})}catch{return"Invalid Date"}},[n]),U=ce(l),S=f.useCallback(async()=>{if(!n){b(!1);return}try{const _=route("check-user-locations-updates",{date:n.split("T")[0]}),T=await fetch(_);if(!T.ok)throw new Error(`HTTP ${T.status}: Failed to check for updates`);const E=await T.json();E.success&&E.last_updated!==$.current&&E.last_updated&&($.current=E.last_updated,R(),c(new Date)),w(new Date)}catch(_){console.error("Error checking for updates:",_),b(!1)}},[n,R]);f.useEffect(()=>{R()},[n,R]),f.useEffect(()=>{if(!a)return;S();const _=setInterval(S,5e3);return()=>clearInterval(_)},[a,S]),f.useEffect(()=>{l.length===0&&m&&s&&b(!1)},[l,m,s]),f.useEffect(()=>{if(m){const _=setTimeout(()=>{m&&b(!1)},1e4);return()=>clearTimeout(_)}},[m]);const N=f.useMemo(()=>u?u.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:!0}):null,[u]);return f.useCallback(_=>{const T=Array.isArray(_)?_:[];JSON.stringify(T)!==JSON.stringify(j.current)&&(d(T),j.current=T),y(!0),b(!1)},[]),o.jsxs(M,{children:[o.jsxs(W,{mb:"4",children:[o.jsx(M,{p:"4",style:{borderBottom:"1px solid var(--gray-a4)"},children:o.jsxs(z,{justify:"between",align:"center",gap:"3",wrap:"wrap",children:[o.jsxs(z,{align:"center",gap:"3",children:[o.jsx(M,{style:{padding:10,borderRadius:"var(--radius-3)",background:"var(--accent-a3)",border:"1px solid var(--accent-a6)",width:44,height:44,flexShrink:0,display:"flex",alignItems:"center",justifyContent:"center"},children:o.jsx(K,{style:{color:"var(--accent-9)",width:22,height:22}})}),o.jsxs(M,{children:[o.jsx(q,{size:"4",children:"Team Locations"}),o.jsx(A,{size:"2",color:"gray",children:O})]})]}),N&&o.jsxs(A,{size:"1",color:"gray",children:["Updated: ",N]})]})}),o.jsx(M,{p:"4",style:{borderBottom:"1px solid var(--gray-a4)"},children:o.jsxs(Q,{columns:"3",gap:"3",children:[o.jsx(W,{variant:"surface",children:o.jsxs(z,{direction:"column",align:"center",p:"3",gap:"1",children:[o.jsx(te,{style:{color:"var(--accent-9)",width:20,height:20}}),o.jsx(A,{size:"4",weight:"bold",color:"blue",children:U.total}),o.jsx(A,{size:"1",color:"gray",children:"Total"})]})}),o.jsx(W,{variant:"surface",children:o.jsxs(z,{direction:"column",align:"center",p:"3",gap:"1",children:[o.jsx(ne,{style:{color:"var(--amber-9)",width:20,height:20}}),o.jsx(A,{size:"4",weight:"bold",color:"amber",children:U.checkedIn}),o.jsx(A,{size:"1",color:"gray",children:"Active"})]})}),o.jsx(W,{variant:"surface",children:o.jsxs(z,{direction:"column",align:"center",p:"3",gap:"1",children:[o.jsx(oe,{style:{color:"var(--green-9)",width:20,height:20}}),o.jsx(A,{size:"4",weight:"bold",color:"green",children:U.completed}),o.jsx(A,{size:"1",color:"gray",children:"Completed"})]})})]})}),o.jsx(M,{p:"4",children:l.length>0?o.jsxs(M,{style:{position:"relative",height:"70vh",borderRadius:"var(--radius-3)",overflow:"hidden",border:"1px solid var(--gray-a4)"},children:[m&&o.jsx(z,{align:"center",justify:"center",style:{position:"absolute",inset:0,background:"var(--gray-a3)",zIndex:50},children:o.jsxs(z,{direction:"column",align:"center",gap:"2",children:[o.jsx(X,{size:"3"}),o.jsx(A,{size:"2",color:"gray",children:"Loading locations..."})]})}),o.jsxs(ie,{center:[G.lat,G.lng],zoom:F.DEFAULT_ZOOM,minZoom:F.MIN_ZOOM,maxZoom:F.MAX_ZOOM,style:{height:"100%",width:"100%"},scrollWheelZoom:!0,doubleClickZoom:!0,dragging:!0,touchZoom:!0,fullscreenControl:!0,attributionControl:!1,zoomControl:!1,children:[o.jsx(le,{url:"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",maxZoom:F.MAX_ZOOM,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}),o.jsx(J,{attendanceTypeConfigs:k,theme:null}),o.jsx(V,{users:l,selectedDate:n,theme:null})]},`${r}-${p}`)]}):m?o.jsx(z,{align:"center",justify:"center",style:{height:"70vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)"},children:o.jsxs(z,{direction:"column",align:"center",gap:"2",children:[o.jsx(X,{size:"3"}),o.jsx(A,{size:"2",color:"gray",children:"Loading locations..."})]})}):o.jsxs(z,{direction:"column",align:"center",justify:"center",gap:"3",p:"6",style:{height:"70vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)"},children:[o.jsx(K,{style:{width:64,height:64,color:"var(--gray-6)"}}),o.jsx(q,{size:"4",children:"No Location Data Available"}),o.jsxs(A,{size:"2",color:"gray",align:"center",children:["No team location data found for ",O,"."," ",n&&new Date(n)>new Date?"This date is in the future.":"Try selecting a different date or refreshing the data."]}),o.jsx(ee,{variant:"outline",onClick:R,children:o.jsxs(z,{align:"center",gap:"2",children:[o.jsx(re,{})," Refresh Data"]})})]})})]}),e&&o.jsxs(M,{style:{position:"fixed",inset:0,background:"rgba(0,0,0,0.95)",zIndex:99999,display:"flex",alignItems:"center",justifyContent:"center"},onClick:()=>t(null),children:[o.jsx("button",{style:{position:"absolute",top:24,right:24,padding:10,borderRadius:"50%",background:"rgba(255,255,255,0.15)",border:"2px solid rgba(255,255,255,0.3)",color:"white",cursor:"pointer",lineHeight:0,display:"flex",alignItems:"center",justifyContent:"center"},onClick:_=>{_.stopPropagation(),t(null)},"aria-label":"Close fullscreen",children:o.jsx(se,{style:{width:24,height:24}})}),o.jsxs(M,{style:{maxWidth:"95vw",maxHeight:"95vh",display:"flex",flexDirection:"column",alignItems:"center",gap:16},onClick:_=>_.stopPropagation(),children:[o.jsx("img",{src:e,alt:"Attendance photo",style:{maxWidth:"90vw",maxHeight:"85vh",objectFit:"contain",borderRadius:"var(--radius-3)"}}),o.jsx(A,{size:"2",style:{color:"rgba(255,255,255,0.7)"},children:"Click anywhere to close"})]})]})]})});export{_e as U};
