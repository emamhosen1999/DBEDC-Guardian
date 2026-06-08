import{j as s,p as U,o as Z,b as A,G,d as z,S as ne,s as V,a as oe}from"./vendor-radix-CceonArz.js";import{R as q,a as l}from"./vendor-inertia-B_jPe_ra.js";import"./dayjs.min-Ca8ckx1t.js";import"./useObjectionsListState-Cnd2DxqU.js";import{i as Y,P as re,a as se,b as ie,R as le,m as ae}from"./react-icons.esm-BZz3CXXS.js";import{L as f}from"./leaflet-CpeWTaXg.js";import"./leaflet-routing-machine-CqCZn3Dx.js";import{b as X,M as ce,T as ue}from"./TileLayer-OsRTXKLT.js";import"./ErrorBoundary-C3Q6dlwG.js";import"./MonthlyCalendarTab-szJAPxAk.js";import"./vendor-utils-D6Wd6ilh.js";L.Control.Fullscreen=L.Control.extend({options:{position:"topleft",title:{false:"View Fullscreen",true:"Exit Fullscreen"}},onAdd:function(r){var t=L.DomUtil.create("div","leaflet-control-fullscreen leaflet-bar leaflet-control");return this.link=L.DomUtil.create("a","leaflet-control-fullscreen-button leaflet-bar-part",t),this.link.href="#",this._map=r,this._map.on("fullscreenchange",this._toggleTitle,this),this._toggleTitle(),L.DomEvent.on(this.link,"click",this._click,this),t},_click:function(r){L.DomEvent.stopPropagation(r),L.DomEvent.preventDefault(r),this._map.toggleFullscreen(this.options)},_toggleTitle:function(){this.link.title=this.options.title[this._map.isFullscreen()]}});L.Map.include({isFullscreen:function(){return this._isFullscreen||!1},toggleFullscreen:function(r){var t=this.getContainer();this.isFullscreen()?r&&r.pseudoFullscreen?this._disablePseudoFullscreen(t):document.exitFullscreen?document.exitFullscreen():document.mozCancelFullScreen?document.mozCancelFullScreen():document.webkitCancelFullScreen?document.webkitCancelFullScreen():document.msExitFullscreen?document.msExitFullscreen():this._disablePseudoFullscreen(t):r&&r.pseudoFullscreen?this._enablePseudoFullscreen(t):t.requestFullscreen?t.requestFullscreen():t.mozRequestFullScreen?t.mozRequestFullScreen():t.webkitRequestFullscreen?t.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT):t.msRequestFullscreen?t.msRequestFullscreen():this._enablePseudoFullscreen(t)},_enablePseudoFullscreen:function(r){L.DomUtil.addClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!0),this.fire("fullscreenchange")},_disablePseudoFullscreen:function(r){L.DomUtil.removeClass(r,"leaflet-pseudo-fullscreen"),this._setFullscreen(!1),this.fire("fullscreenchange")},_setFullscreen:function(r){this._isFullscreen=r;var t=this.getContainer();r?L.DomUtil.addClass(t,"leaflet-fullscreen-on"):L.DomUtil.removeClass(t,"leaflet-fullscreen-on"),this.invalidateSize()},_onFullscreenChange:function(r){var t=document.fullscreenElement||document.mozFullScreenElement||document.webkitFullscreenElement||document.msFullscreenElement;t===this.getContainer()&&!this._isFullscreen?(this._setFullscreen(!0),this.fire("fullscreenchange")):t!==this.getContainer()&&this._isFullscreen&&(this._setFullscreen(!1),this.fire("fullscreenchange"))}});L.Map.mergeOptions({fullscreenControl:!1});L.Map.addInitHook(function(){this.options.fullscreenControl&&(this.fullscreenControl=new L.Control.Fullscreen(this.options.fullscreenControl),this.addControl(this.fullscreenControl));var r;if("onfullscreenchange"in document?r="fullscreenchange":"onmozfullscreenchange"in document?r="mozfullscreenchange":"onwebkitfullscreenchange"in document?r="webkitfullscreenchange":"onmsfullscreenchange"in document&&(r="MSFullscreenChange"),r){var t=L.bind(this._onFullscreenChange,this);this.whenReady(function(){L.DomEvent.on(document,r,t)}),this.on("unload",function(){L.DomEvent.off(document,r,t)})}});L.control.fullscreen=function(r){return new L.Control.Fullscreen(r)};const M=(r,t)=>{if(r.startsWith("var("))return`color-mix(in srgb, ${r} ${t*100}%, transparent)`;if(r.startsWith("#")){const i=r.replace("#",""),m=parseInt(i.substr(0,2),16),$=parseInt(i.substr(2,2),16),a=parseInt(i.substr(4,2),16);return`rgba(${m}, ${$}, ${a}, ${t})`}return r.replace(/[\d.]+\)$/g,`${t})`)},O={DEFAULT_ZOOM:12,MIN_ZOOM:8,MAX_ZOOM:19,POSITION_THRESHOLD:1e-4,OFFSET_MULTIPLIER:1e-4,MARKER_SIZE:[40,40],POPUP_MAX_WIDTH:160},Q={lat:23.8103,lng:90.4125},ee=q.memo(({attendanceTypeConfigs:r,theme:t})=>{const i=X(),m=l.useRef([]);return l.useEffect(()=>{if(!i||!(r!=null&&r.length))return;i.eachLayer(a=>{a.options&&(a.options.isAttendanceBoundary||a.options.isPolygon||a.options.isRoute)&&i.removeLayer(a)}),m.current.forEach(a=>{try{i.removeControl(a)}catch(h){console.warn("Error removing routing control:",h)}}),m.current=[],i.getContainer().querySelectorAll(".leaflet-routing-container").forEach(a=>a.remove());const $=[];if(r.forEach((a,h)=>{var W;const{base_slug:y,config:F,name:P}=a;(W=t==null?void 0:t.customColors)!=null&&W.primary;const R=["#3b82f6","#10b981","#f59e0b","#ef4444","#8b5cf6","#ec4899"],j=R[h%R.length];if(y==="geo_polygon"&&F){const N=F.polygon||[],S=F.polygons||[];if(N.length>=3){const b=N.filter(x=>x.lat&&x.lng);if(b.length>=3){const x=b.map(e=>[parseFloat(e.lat),parseFloat(e.lng)]),d=f.polygon(x,{color:j,fillColor:j,fillOpacity:.15,weight:2,opacity:.7,isAttendanceBoundary:!0,isPolygon:!0}).addTo(i);d.bindPopup(`
                            <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                <strong style="color: ${j};">${P}</strong><br>
                                <small>Geofence Zone</small><br>
                                <small>Points: ${b.length}</small>
                            </div>
                        `),$.push(d.getBounds())}}S.forEach((b,x)=>{const d=b.points||[];if(d.length>=3){const e=d.filter(n=>n.lat&&n.lng);if(e.length>=3){const n=e.map(u=>[parseFloat(u.lat),parseFloat(u.lng)]),o=f.polygon(n,{color:j,fillColor:j,fillOpacity:.15,weight:2,opacity:.7,isAttendanceBoundary:!0,isPolygon:!0}).addTo(i);o.bindPopup(`
                                <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                    <strong style="color: ${j};">${P}</strong><br>
                                    <small>${b.name||`Zone ${x+1}`}</small>
                                </div>
                            `),$.push(o.getBounds())}}})}if(y==="route_waypoint"&&F){const N=F.waypoints||[],S=F.routes||[];if(N.length>=2){const b=N.filter(x=>x.lat&&x.lng);if(b.length>=2){const x=b.map(d=>f.latLng(parseFloat(d.lat),parseFloat(d.lng)));b.forEach((d,e)=>{const n=e===0,o=e===b.length-1,u=`
                                <div style="
                                    width: 24px;
                                    height: 24px;
                                    border-radius: 50%;
                                    background: ${n?"#10b981":o?"#ef4444":j};
                                    border: 2px solid white;
                                    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    color: white;
                                    font-weight: bold;
                                    font-size: 11px;
                                ">
                                    ${e+1}
                                </div>
                            `;f.marker([parseFloat(d.lat),parseFloat(d.lng)],{icon:f.divIcon({html:u,className:"route-waypoint-marker",iconSize:[24,24],iconAnchor:[12,12]}),isAttendanceBoundary:!0}).addTo(i).bindPopup(`
                                <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                    <strong style="color: ${j};">${P}</strong><br>
                                    <small>Waypoint ${e+1}${d.name?`: ${d.name}`:""}</small>
                                </div>
                            `)});try{const d=f.Routing.control({waypoints:x,routeWhileDragging:!1,addWaypoints:!1,createMarker:()=>null,lineOptions:{styles:[{color:j,weight:4,opacity:.7,dashArray:"8, 4"}],extendToWaypoints:!0,missingRouteTolerance:0},show:!1,fitSelectedRoutes:!1,router:f.Routing.osrmv1({serviceUrl:"https://router.project-osrm.org/route/v1"})}).addTo(i);m.current.push(d);const e=f.latLngBounds(x);$.push(e)}catch(d){console.warn("Error creating route:",d);const e=f.polyline(x,{color:j,weight:3,opacity:.6,dashArray:"10, 10",isAttendanceBoundary:!0,isRoute:!0}).addTo(i);$.push(e.getBounds())}}}S.forEach((b,x)=>{const d=b.waypoints||[];if(d.length>=2){const e=d.filter(n=>n.lat&&n.lng);if(e.length>=2){const n=e.map(o=>f.latLng(parseFloat(o.lat),parseFloat(o.lng)));try{const o=f.Routing.control({waypoints:n,routeWhileDragging:!1,addWaypoints:!1,createMarker:()=>null,lineOptions:{styles:[{color:j,weight:4,opacity:.7,dashArray:"8, 4"}]},show:!1,fitSelectedRoutes:!1,router:f.Routing.osrmv1({serviceUrl:"https://router.project-osrm.org/route/v1"})}).addTo(i);m.current.push(o),$.push(f.latLngBounds(n))}catch(o){console.warn("Error creating route:",o)}}}})}}),$.length>0){const a=$.reduce((h,y)=>h?h.extend(y):y,null);a&&a.isValid()&&setTimeout(()=>{try{i&&i._container&&i.fitBounds(a,{padding:[50,50],maxZoom:14})}catch{}},500)}return()=>{m.current.forEach(a=>{try{a&&i&&i._container&&i.removeControl(a)}catch{}}),m.current=[]}},[i,r,t]),null});ee.displayName="AttendanceTypeBoundaries";const pe=q.memo(({startLocation:r,endLocation:t,theme:i})=>{const m=X();return l.useEffect(()=>{var a;if(!m||!r||!t)return;const $=f.Routing.control({waypoints:[f.latLng(r.lat,r.lng),f.latLng(t.lat,t.lng)],routeWhileDragging:!1,addWaypoints:!1,createMarker:()=>null,lineOptions:{styles:[{color:((a=i==null?void 0:i.customColors)==null?void 0:a.primary)||"var(--theme-primary, #3b82f6)",weight:4,opacity:.8}]},show:!1}).addTo(m);return()=>{if(m&&$&&m._container)try{m.removeControl($)}catch{}}},[m,r,t,i]),null});pe.displayName="RoutingMachine";const te=q.memo(({selectedDate:r,onUsersLoad:t,theme:i,lastUpdate:m,users:$,setUsers:a,setLoading:h,setError:y,setAttendanceTypeConfigs:F})=>{const P=X(),R=l.useRef([]),j=l.useCallback(async()=>{if(!r){h(!1),a([]),F==null||F([]),t==null||t([]);return}h(!0),y(null);try{const e=route("getUserLocationsForDate",{date:r,_t:Date.now()}),o=(await axios.get(e)).data;if(!o.success||!Array.isArray(o.locations))throw new Error("Unexpected response format from server.");const u=o.locations,c=o.attendance_type_configs||[];JSON.stringify(u)!==JSON.stringify(R.current)&&(a(u),R.current=u),F==null||F(c),t==null||t(u)}catch(e){let n="Error fetching user locations.";e.response?(n+=` Server error (${e.response.status}): ${e.response.statusText}`,typeof e.response.data=="object"&&(n+=`
Details: ${JSON.stringify(e.response.data)}`)):e.request?n+=" No response received from server.":e.message&&(n+=` ${e.message}`),console.error(n,e),y(n),a([]),t==null||t([])}finally{h(!1)}},[r,t,m]);l.useEffect(()=>{j()},[j]);const W=l.useCallback((e,n)=>{const o=O.OFFSET_MULTIPLIER*n;return{lat:e.lat+o,lng:e.lng+o}},[]),N=l.useCallback((e,n)=>Math.abs(e.lat-n.lat)<O.POSITION_THRESHOLD&&Math.abs(e.lng-n.lng)<O.POSITION_THRESHOLD,[]),S=l.useCallback(e=>{if(!e)return null;if(typeof e=="object"&&e.lat&&e.lng){const n=parseFloat(e.lat),o=parseFloat(e.lng);return isNaN(n)||isNaN(o)?null:{lat:n,lng:o}}if(typeof e=="string")try{const n=JSON.parse(e);if(n.lat&&n.lng){const o=parseFloat(n.lat),u=parseFloat(n.lng);return isNaN(o)||isNaN(u)?null:{lat:o,lng:u}}}catch{const o=e.split(",");if(o.length>=2){const u=parseFloat(o[0].trim()),c=parseFloat(o[1].trim());return isNaN(u)||isNaN(c)?null:{lat:u,lng:c}}}return null},[]),b=l.useCallback(e=>{if(!e)return"Not recorded";try{let n;return e.includes("T")?n=new Date(e):n=new Date(`${r}T${e}`),isNaN(n.getTime())?"Invalid time":n.toLocaleTimeString("en-US",{hour:"numeric",minute:"2-digit",hour12:!0})}catch(n){return console.warn("Error formatting time:",n),e}},[r]),x=l.useCallback((e,n="default")=>{var _,T,p,g;const o="var(--theme-primary, #3b82f6)",u="var(--theme-secondary, #8b5cf6)",c="var(--theme-success, #17C964)",w="var(--theme-danger, #ef4444)";let v,C,E="";n==="punchin"?(v=`${c}, #059669`,C=M(c,.4),E='<span style="position: absolute; top: -4px; right: -4px; font-size: 10px;">▶</span>'):n==="punchout"?(v=`${w}, #dc2626`,C=M(w,.4),E='<span style="position: absolute; top: -4px; right: -4px; font-size: 10px;">◼</span>'):(v=`${o}, ${u}`,C=M(o,.4));const k=`
            <div style="
                position: relative;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: linear-gradient(135deg, ${v});
                border: 3px solid white;
                box-shadow: 0 4px 12px ${C};
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                font-size: 14px;
            ">
                ${e.profile_image_url||e.profile_image?`<img src="${e.profile_image_url||e.profile_image}" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${((T=(_=e.name)==null?void 0:_.charAt(0))==null?void 0:T.toUpperCase())||"?"}';" />`:((g=(p=e.name)==null?void 0:p.charAt(0))==null?void 0:g.toUpperCase())||"?"}
                ${E}
            </div>
        `;return f.divIcon({html:k,className:"user-marker-icon",iconSize:O.MARKER_SIZE,iconAnchor:[20,20],popupAnchor:[0,-20]})},[]),d=l.useCallback((e,n="combined")=>{var B,H,K,J;const o=e.punchout_time?"var(--theme-success, #17C964)":"var(--theme-warning, #F5A524)",u="var(--theme-primary, #3b82f6)",c="var(--theme-secondary, #8b5cf6)",w="var(--theme-success, #17C964)",v="var(--theme-danger, #ef4444)",C="var(--theme-content1, #ffffff)",E="var(--theme-foreground, #1f2937)",k="var(--theme-content3, #6b7280)";let _=null,T="";n==="punchin"&&e.punchin_photo_url?(_=e.punchin_photo_url,T="Check In Photo"):n==="punchout"&&e.punchout_photo_url&&(_=e.punchout_photo_url,T="Check Out Photo");const p=_?`
            <div style="margin-top: 6px; margin-bottom: 6px; overflow: hidden; border-radius: 4px;">
                <div style="color: ${k}; font-size: 8px; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px;">
                    ${T}
                </div>
                <img 
                    src="${_}" 
                    data-fullscreen-photo="${_}"
                    style="
                        width: 100%; 
                        height: auto;
                        max-height: 100px;
                        object-fit: contain; 
                        border-radius: 4px;
                        border: 1px solid ${M(u,.2)};
                        display: block;
                        cursor: pointer;
                    " 
                    onmouseover="this.style.opacity='0.85'"
                    onmouseout="this.style.opacity='1'"
                    onerror="this.style.display='none';"
                    title="Click to view full screen"
                />
            </div>
        `:"";let g="",I=u;n==="punchin"?(g=`
                <div style="
                    display: inline-block;
                    padding: 1px 4px;
                    background: ${M(w,.1)};
                    color: ${w};
                    border-radius: 3px;
                    font-size: 7px;
                    font-weight: 600;
                    margin-left: 4px;
                    border: 1px solid ${M(w,.2)};
                ">CHECK IN</div>
            `,I=w):n==="punchout"&&(g=`
                <div style="
                    display: inline-block;
                    padding: 1px 4px;
                    background: ${M(v,.1)};
                    color: ${v};
                    border-radius: 3px;
                    font-size: 7px;
                    font-weight: 600;
                    margin-left: 4px;
                    border: 1px solid ${M(v,.2)};
                ">CHECK OUT</div>
            `,I=v);let D="";return n==="punchin"?D=`
                <div style="display: flex; align-items: center;">
                    <span style="color: ${w}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${k}; font-size: 9px;">
                        Time: ${b(e.punchin_time)}
                    </span>
                </div>
            `:n==="punchout"?D=`
                <div style="display: flex; align-items: center;">
                    <span style="color: ${v}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${k}; font-size: 9px;">
                        Time: ${b(e.punchout_time)}
                    </span>
                </div>
            `:D=`
                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                    <span style="color: ${w}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${k}; font-size: 9px;">
                        Check In: ${b(e.punchin_time)}
                    </span>
                </div>
                <div style="display: flex; align-items: center;">
                    <span style="color: ${v}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${k}; font-size: 9px;">
                        Check Out: ${b(e.punchout_time)}
                    </span>
                </div>
            `,`
            <div style="
                min-width: 140px;
                max-width: 160px;
                padding: 8px;
                background: linear-gradient(135deg, ${M(C,.95)}, ${M(I,.05)});
                border-radius: 8px;
                border: 1px solid ${M(I,.2)};
                backdrop-filter: blur(20px);
                overflow: hidden;
            ">
                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                    <div style="
                        width: 22px;
                        height: 22px;
                        min-width: 22px;
                        border-radius: 50%;
                        background: linear-gradient(135deg, ${n==="punchin"?w:n==="punchout"?v:u}, ${c});
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-weight: bold;
                        font-size: 10px;
                        margin-right: 6px;
                    ">
                        ${e.profile_image_url||e.profile_image?`<img src="${e.profile_image_url||e.profile_image}" style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${((H=(B=e.name)==null?void 0:B.charAt(0))==null?void 0:H.toUpperCase())||"?"}';" />`:((J=(K=e.name)==null?void 0:K.charAt(0))==null?void 0:J.toUpperCase())||"?"}
                    </div>
                    <div style="flex: 1; min-width: 0; overflow: hidden;">
                        <div style="display: flex; align-items: center; flex-wrap: wrap;">
                            <span style="font-weight: 600; color: ${E}; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70px;">
                                ${e.name||"Unknown"}
                            </span>
                            ${g}
                        </div>
                        <div style="color: ${k}; font-size: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            ${e.designation||"No designation"}
                        </div>
                    </div>
                </div>
                ${n==="combined"?`
                <div style="
                    display: inline-block;
                    padding: 2px 4px;
                    background: ${M(o,.1)};
                    color: ${o};
                    border-radius: 4px;
                    font-size: 8px;
                    font-weight: 600;
                    margin-bottom: 6px;
                    border: 1px solid ${M(o,.2)};
                ">
                    ${e.punchout_time?"✓ Completed":"⏱ Active"}
                </div>`:""}
                ${p}
                <div style="space-y: 4px;">
                    ${D}
                </div>
            </div>
        `},[b]);return l.useEffect(()=>{if(!P||!$.length)return;P.eachLayer(o=>{(o instanceof f.Marker&&o.options.userData||o instanceof f.Polyline&&o.options.userRoute)&&P.removeLayer(o)});const e=[],n=o=>{let u={...o},c=0;const w=10;for(;c<w&&e.some(C=>N(u,C));)u=W(o,c+1),c++;return e.push(u),u};$.forEach(o=>{const u=o.cycles||[];if(u.length>0)u.forEach((c,w)=>{const v=S(c.punchin_location),C=S(c.punchout_location);if(!v&&!C)return;const E=v&&C&&c.is_complete,k={...o,punchin_time:c.punchin_time,punchout_time:c.punchout_time,punchin_photo_url:c.punchin_photo_url,punchout_photo_url:c.punchout_photo_url};if(E){const _=n(v),T=n(C),p=f.marker([_.lat,_.lng],{icon:x(o,"punchin"),userData:!0});p.bindPopup(d(k,"punchin"),{maxWidth:O.POPUP_MAX_WIDTH,className:"custom-popup"}),p.addTo(P);const g=f.marker([T.lat,T.lng],{icon:x(o,"punchout"),userData:!0});g.bindPopup(d(k,"punchout"),{maxWidth:O.POPUP_MAX_WIDTH,className:"custom-popup"}),g.addTo(P),f.polyline([[_.lat,_.lng],[T.lat,T.lng]],{color:"var(--theme-primary, #3b82f6)",weight:3,opacity:.7,dashArray:"10, 10",userRoute:!0}).addTo(P)}else{const T=n(v||C),p=v?"punchin":"punchout",g=f.marker([T.lat,T.lng],{icon:x(o,p),userData:!0});g.bindPopup(d(k,p),{maxWidth:O.POPUP_MAX_WIDTH,className:"custom-popup"}),g.addTo(P)}});else{const c=S(o.punchin_location),w=S(o.punchout_location);if(!c&&!w)return;if(c&&w&&o.punchout_time){const C=n(c),E=n(w),k=f.marker([C.lat,C.lng],{icon:x(o,"punchin"),userData:!0});k.bindPopup(d(o,"punchin"),{maxWidth:O.POPUP_MAX_WIDTH,className:"custom-popup"}),k.addTo(P);const _=f.marker([E.lat,E.lng],{icon:x(o,"punchout"),userData:!0});_.bindPopup(d(o,"punchout"),{maxWidth:O.POPUP_MAX_WIDTH,className:"custom-popup"}),_.addTo(P),f.polyline([[C.lat,C.lng],[E.lat,E.lng]],{color:"var(--theme-primary, #3b82f6)",weight:3,opacity:.7,dashArray:"10, 10",userRoute:!0}).addTo(P)}else{const E=n(c||w),k=c?"punchin":"punchout",_=f.marker([E.lat,E.lng],{icon:x(o,k),userData:!0});_.bindPopup(d(o,k),{maxWidth:O.POPUP_MAX_WIDTH,className:"custom-popup"}),_.addTo(P)}}})},[P,$,i,S,N,W,x,d]),null});te.displayName="UserMarkers";const de=r=>l.useMemo(()=>{const t=r.reduce((h,y)=>{const F=y.user_id;return h[F]||(h[F]=[]),h[F].push(y),h},{}),i=Object.keys(t),m=i.length;let $=0,a=0;return i.forEach(h=>{const y=t[h];y.sort((R,j)=>R.punchin_time?j.punchin_time?R.punchin_time.localeCompare(j.punchin_time):-1:1);const F=y[y.length-1];y.some(R=>R.punchin_time)&&(F.punchout_time?a++:$++)}),{checkedIn:$,completed:a,total:m}},[r]),ke=q.memo(({updateMap:r,selectedDate:t})=>{const[i,m]=l.useState([]),[$,a]=l.useState([]),[h,y]=l.useState(!0),[F,P]=l.useState(null),[R,j]=l.useState(null),[W,N]=l.useState(!1),[S,b]=l.useState(null),[x,d]=l.useState(!0),[e,n]=l.useState(0),[o,u]=l.useState(new Date),c=l.useRef([]),w=l.useRef(null);l.useRef(null),l.useEffect(()=>{const p=g=>{var D;const I=(D=g.target.dataset)==null?void 0:D.fullscreenPhoto;I&&j(I)};return document.addEventListener("click",p),()=>{document.removeEventListener("click",p)}},[]);const v=l.useCallback(async()=>{y(!0);try{const p=route("getUserLocationsForDate",{date:t,_t:Date.now()});if(!t){m([]),a([]),c.current=[],n(H=>H+1),u(new Date),b(new Date);return}const g=await fetch(p);if(!g.ok)throw new Error(`HTTP ${g.status}: Failed to refresh user locations`);const I=await g.json(),D=Array.isArray(I.locations)?I.locations:[],B=Array.isArray(I.attendance_type_configs)?I.attendance_type_configs:[];m(D),a(B),c.current=D,n(H=>H+1),u(new Date),b(new Date)}catch(p){console.error("Error refreshing map:",p),m([]),a([]),c.current=[]}finally{y(!1)}},[t]),C=l.useMemo(()=>{if(!t)return"Invalid Date";try{return new Date(t).toLocaleString("en-US",{weekday:"long",year:"numeric",month:"long",day:"numeric"})}catch{return"Invalid Date"}},[t]),E=de(i),k=l.useCallback(async()=>{if(!t){y(!1);return}try{const p=route("check-user-locations-updates",{date:t.split("T")[0]}),g=await fetch(p);if(!g.ok)throw new Error(`HTTP ${g.status}: Failed to check for updates`);const I=await g.json();I.success&&I.last_updated!==w.current&&I.last_updated&&(w.current=I.last_updated,v(),b(new Date)),u(new Date)}catch(p){console.error("Error checking for updates:",p),y(!1)}},[t,v]);l.useEffect(()=>{if(!x)return;k();const p=setInterval(k,5e3);return()=>clearInterval(p)},[x,k]),l.useEffect(()=>{i.length===0&&h&&W&&y(!1)},[i,h,W]),l.useEffect(()=>{if(h){const p=setTimeout(()=>{h&&y(!1)},1e4);return()=>clearTimeout(p)}},[h]);const _=l.useMemo(()=>o?o.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:!0}):null,[o]),T=l.useCallback(p=>{const g=Array.isArray(p)?p:[];JSON.stringify(g)!==JSON.stringify(c.current)&&(m(g),c.current=g),N(!0),y(!1)},[]);return s.jsxs(U,{children:[s.jsxs(Z,{mb:"4",children:[s.jsx(U,{p:"4",style:{borderBottom:"1px solid var(--gray-a4)"},children:s.jsxs(A,{justify:"between",align:"center",gap:"3",wrap:"wrap",children:[s.jsxs(A,{align:"center",gap:"3",children:[s.jsx(U,{style:{padding:10,borderRadius:"var(--radius-3)",background:"var(--accent-a3)",border:"1px solid var(--accent-a6)",width:44,height:44,flexShrink:0,display:"flex",alignItems:"center",justifyContent:"center"},children:s.jsx(Y,{style:{color:"var(--accent-9)",width:22,height:22}})}),s.jsxs(U,{children:[s.jsx(G,{size:"4",children:"Team Locations"}),s.jsx(z,{size:"2",color:"gray",children:C})]})]}),_&&s.jsxs(z,{size:"1",color:"gray",children:["Updated: ",_]})]})}),s.jsx(U,{p:"4",style:{borderBottom:"1px solid var(--gray-a4)"},children:s.jsxs(ne,{columns:"3",gap:"3",children:[s.jsx(Z,{variant:"surface",children:s.jsxs(A,{direction:"column",align:"center",p:"3",gap:"1",children:[s.jsx(re,{style:{color:"var(--accent-9)",width:20,height:20}}),s.jsx(z,{size:"4",weight:"bold",color:"blue",children:E.total}),s.jsx(z,{size:"1",color:"gray",children:"Total"})]})}),s.jsx(Z,{variant:"surface",children:s.jsxs(A,{direction:"column",align:"center",p:"3",gap:"1",children:[s.jsx(se,{style:{color:"var(--amber-9)",width:20,height:20}}),s.jsx(z,{size:"4",weight:"bold",color:"amber",children:E.checkedIn}),s.jsx(z,{size:"1",color:"gray",children:"Active"})]})}),s.jsx(Z,{variant:"surface",children:s.jsxs(A,{direction:"column",align:"center",p:"3",gap:"1",children:[s.jsx(ie,{style:{color:"var(--green-9)",width:20,height:20}}),s.jsx(z,{size:"4",weight:"bold",color:"green",children:E.completed}),s.jsx(z,{size:"1",color:"gray",children:"Completed"})]})})]})}),s.jsx(U,{p:"4",children:i.length>0?s.jsxs(U,{style:{position:"relative",height:"70vh",borderRadius:"var(--radius-3)",overflow:"hidden",border:"1px solid var(--gray-a4)"},children:[h&&s.jsx(A,{align:"center",justify:"center",style:{position:"absolute",inset:0,background:"var(--gray-a3)",zIndex:50},children:s.jsxs(A,{direction:"column",align:"center",gap:"2",children:[s.jsx(V,{size:"3"}),s.jsx(z,{size:"2",color:"gray",children:"Loading locations..."})]})}),s.jsxs(ce,{center:[Q.lat,Q.lng],zoom:O.DEFAULT_ZOOM,minZoom:O.MIN_ZOOM,maxZoom:O.MAX_ZOOM,style:{height:"100%",width:"100%"},scrollWheelZoom:!0,doubleClickZoom:!0,dragging:!0,touchZoom:!0,fullscreenControl:!0,attributionControl:!1,zoomControl:!1,children:[s.jsx(ue,{url:"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",maxZoom:O.MAX_ZOOM,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}),s.jsx(ee,{attendanceTypeConfigs:$,theme:null}),s.jsx(te,{users:i,setUsers:m,setLoading:y,setError:P,setAttendanceTypeConfigs:a,lastUpdate:S,selectedDate:t,onUsersLoad:T,theme:null})]},`${r}-${e}`)]}):h?s.jsx(A,{align:"center",justify:"center",style:{height:"70vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)"},children:s.jsxs(A,{direction:"column",align:"center",gap:"2",children:[s.jsx(V,{size:"3"}),s.jsx(z,{size:"2",color:"gray",children:"Loading locations..."})]})}):s.jsxs(A,{direction:"column",align:"center",justify:"center",gap:"3",p:"6",style:{height:"70vh",border:"1px solid var(--gray-a4)",borderRadius:"var(--radius-3)"},children:[s.jsx(Y,{style:{width:64,height:64,color:"var(--gray-6)"}}),s.jsx(G,{size:"4",children:"No Location Data Available"}),s.jsxs(z,{size:"2",color:"gray",align:"center",children:["No team location data found for ",C,"."," ",t&&new Date(t)>new Date?"This date is in the future.":"Try selecting a different date or refreshing the data."]}),s.jsx(oe,{variant:"outline",onClick:v,children:s.jsxs(A,{align:"center",gap:"2",children:[s.jsx(le,{})," Refresh Data"]})})]})})]}),R&&s.jsxs(U,{style:{position:"fixed",inset:0,background:"rgba(0,0,0,0.95)",zIndex:99999,display:"flex",alignItems:"center",justifyContent:"center"},onClick:()=>j(null),children:[s.jsx("button",{style:{position:"absolute",top:24,right:24,padding:10,borderRadius:"50%",background:"rgba(255,255,255,0.15)",border:"2px solid rgba(255,255,255,0.3)",color:"white",cursor:"pointer",lineHeight:0,display:"flex",alignItems:"center",justifyContent:"center"},onClick:p=>{p.stopPropagation(),j(null)},"aria-label":"Close fullscreen",children:s.jsx(ae,{style:{width:24,height:24}})}),s.jsxs(U,{style:{maxWidth:"95vw",maxHeight:"95vh",display:"flex",flexDirection:"column",alignItems:"center",gap:16},onClick:p=>p.stopPropagation(),children:[s.jsx("img",{src:R,alt:"Attendance photo",style:{maxWidth:"90vw",maxHeight:"85vh",objectFit:"contain",borderRadius:"var(--radius-3)"}}),s.jsx(z,{size:"2",style:{color:"rgba(255,255,255,0.7)"},children:"Click anywhere to close"})]})]})]})});export{ke as U};
