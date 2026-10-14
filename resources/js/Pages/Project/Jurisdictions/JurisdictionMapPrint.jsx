import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';

import { formatChainage } from '@/Components/Cyber/Map/geo.js';
import { initialsOf } from '@/Components/Cyber/Map/util.js';

import PrintMapSvg, { PatternDefs, PrintRuler, patternFill } from './PrintMapSvg.jsx';
import { PERIODS, formatDay, lengthKm } from './jurisdictionUtils.js';

const POSITION = { in_charge: 'In-charge', reporting: 'Reports to the in-charge', assigned: 'Assigned' };

function Avatar({ person }) {
    const [failed, setFailed] = useState(false);
    return <span className="jmp-avatar" aria-hidden="true">{person.photo && !failed ? <img src={person.photo} alt="" width="16" height="16" style={{ width: '100%', height: '100%', objectFit: 'cover' }} onError={() => setFailed(true)} /> : initialsOf(person.name)}</span>;
}

/** The team compact for print: in-charge, then deputies, then members, each with their works in the period. */
function TeamList({ nodes }) {
    if (!nodes.length) return <p style={{ margin: 0 }}>No team recorded on this date.</p>;
    return (
        <ul className="jmp-tree">
            {nodes.map((n) => (
                <li key={n.employee_id}>
                    <div><Avatar person={n} /><span><strong>{n.name ?? n.employee_id}</strong>, {n.designation ?? 'Employee'} ({POSITION[n.position]}{n.assigned_role && n.position !== 'assigned' ? ', also assigned' : ''})</span><b>{n.works} works</b></div>
                    {n.children?.length > 0 && <TeamList nodes={n.children} />}
                </li>
            ))}
        </ul>
    );
}

function Panel({ j, period }) {
    const s = j.stats;
    return (
        <section className="jmp-panel" aria-label={`${j.code} ${j.name}`}>
            <h3>
                <svg width="14" height="10" viewBox="0 0 14 10" aria-hidden="true" style={{ flex: 'none' }}><PatternDefs /><rect width="14" height="10" fill={patternFill(j.colour_token)} stroke="#0b1a22" strokeWidth="1" /></svg>
                {j.code} {j.name}<span>{formatChainage(j.start_m)} to {formatChainage(j.end_m)}</span>
            </h3>
            <dl>
                <dt>Length</dt><dd>{lengthKm(j.length_m)}</dd>
                <dt>In-charge</dt><dd>{j.in_charge ? `${j.in_charge.name}${j.in_charge.designation ? `, ${j.in_charge.designation}` : ''}` : 'None on this date'}</dd>
                <dt>Valid</dt><dd>{j.valid_from ? `From ${formatDay(j.valid_from)}` : 'Since records began'}</dd>
            </dl>
            <div className="jmp-kpis" aria-label={`Figures for ${period.label}`}>
                <div><strong>{s.works.toLocaleString('en-GB')}</strong><small>Works</small></div>
                <div><strong>{s.completion_rate === null ? '-' : `${s.completion_rate}%`}</strong><small>Completed</small></div>
                <div><strong>{s.rfis.toLocaleString('en-GB')}</strong><small>RFIs</small></div>
                <div><strong>{s.objections.toLocaleString('en-GB')}</strong><small>Objections</small></div>
            </div>
            <TeamList nodes={j.team} />
        </section>
    );
}

function Head_({ tb, paper, asOf }) {
    return (
        <header className="jmp-head">
            <div>
                <h1>{tb.project} <span>{tb.title}</span></h1>
                <p>Dhaka Bypass Expressway, K0+000 to K48+999. Boundaries and teams as on {formatDay(asOf)}.</p>
            </div>
            <dl className="jmp-title-block">
                <dt>Project</dt><dd>{tb.project}</dd>
                <dt>Generated</dt><dd>{tb.generated_at}</dd>
                <dt>Data as of</dt><dd>{formatDay(asOf)}</dd>
                <dt>Prepared by</dt><dd>{tb.prepared_by}</dd>
                <dt>Revision</dt><dd>{tb.revision}</dd>
                <dt>Paper</dt><dd>{paper} landscape</dd>
            </dl>
        </header>
    );
}

function Foot({ tb, page, pages }) {
    return (
        <footer className="jmp-foot">
            <span>{tb.source}</span>
            <span>{tb.title} · {tb.revision} · Page {page} of {pages}</span>
        </footer>
    );
}

/**
 * Printable jurisdiction map (cartographic standard): A3 landscape by default, A4 on request, vector SVG, always the light
 * print palette. Controls above the sheet are screen-only. When the panels do not fit beside the map they flow onto a
 * second page with the header and footer repeated and "Page x of y".
 */
export default function JurisdictionMapPrint({ paper = 'A3', data, title_block: tb }) {
    const side = useRef(null);
    const [overflow, setOverflow] = useState(false);

    const jurisdictions = useMemo(() => {
        const bands = new Map((data.map?.layers ?? []).flatMap((l) => l.features).filter((f) => f.shape === 'band' && f.jurisdiction_id).map((f) => [f.jurisdiction_id, f]));
        return data.jurisdictions.filter((j) => j.kind === 'section').map((j) => { const f = bands.get(j.id); return { ...j, map_path: f?.path ?? null, map_mid: f ? [f.lat, f.lng] : null }; });
    }, [data]);
    const structures = useMemo(() => (data.map?.layers ?? []).find((l) => l.key === 'corridor.structures')?.features ?? [], [data]);

    // Do the panels fit beside the map? Measured after layout, after the fonts and images settle, and whenever the box resizes;
    // if they do not, they move to page 2 (the header and footer repeat there).
    useEffect(() => {
        const el = side.current;
        if (!el || overflow) return undefined;
        const check = () => { if (el.scrollHeight > el.clientHeight + 2) setOverflow(true); };
        check();
        const ro = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(check);
        ro?.observe(el);
        document.fonts?.ready?.then(check);
        window.addEventListener('load', check);
        const t = setTimeout(check, 600);
        return () => { ro?.disconnect(); window.removeEventListener('load', check); clearTimeout(t); };
    }, [jurisdictions, paper, overflow]);
    const pages = overflow ? 2 : 1;

    // Always print on the chosen paper; never inherit the app theme.
    useEffect(() => {
        const root = document.documentElement;
        const prev = { cls: root.className, theme: root.getAttribute('data-theme') };
        root.classList.remove('dark'); root.classList.add('light');
        return () => { root.className = prev.cls; if (prev.theme) root.setAttribute('data-theme', prev.theme); };
    }, []);

    const go = (changes) => router.get(route('jurisdictions.map.print'), { paper, as_of: data.as_of === data.generated_day ? undefined : data.as_of, period: data.period.key, ...changes }, { preserveState: false });
    const cols = paper === 'A3' ? 3 : 3;

    return (
        <div className="jmp-body">
            <Head title={`${tb.title} ${tb.revision}`} />
            <style>{`@page { size: ${paper} landscape; margin: 0; }`}</style>
            <div className="jmp-controls no-print">
                <strong>Print preview</strong>
                <label>Paper <select value={paper} onChange={(e) => go({ paper: e.target.value })}><option value="A3">A3 landscape</option><option value="A4">A4 landscape</option></select></label>
                <label>Period <select value={data.period.key === 'custom' ? '30d' : data.period.key} onChange={(e) => go({ period: e.target.value })}>{PERIODS.filter((p) => p.value !== 'custom').map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}</select></label>
                <label>As of <input type="date" value={data.as_of} onChange={(e) => e.target.value && go({ as_of: e.target.value })} /></label>
                <span className="jmp-spacer" />
                <Link href={`/daily-works-unified?tab=jurisdictions&ju_asof=${data.is_today ? '' : data.as_of}`}>Back to Jurisdictions</Link>
                <button type="button" onClick={() => window.print()}>Print / Save as PDF</button>
            </div>

            <div className="jmp-stack">
                <article className="jmp-sheet" data-paper={paper} aria-label={`${tb.title}, page 1 of ${pages}`}>
                    <Head_ tb={tb} paper={paper} asOf={data.as_of} />
                    <div className="jmp-frame" style={{ flexDirection: 'column' }}>
                        <div className="jmp-row">
                            <div className="jmp-map">
                                {data.map?.alignment ? <PrintMapSvg alignment={data.map.alignment} jurisdictions={jurisdictions.filter((j) => j.map_path)} structures={structures} /> : <p role="status" style={{ padding: '4mm' }}>The corridor centreline is not available.</p>}
                            </div>
                            {!overflow && (
                                <aside ref={side} className="jmp-side" aria-label="Jurisdiction panels" style={{ width: paper === 'A3' ? '34%' : '38%', overflow: 'hidden', borderLeft: '1px solid #0b1a22', padding: '2mm', display: 'grid', gap: '2mm', alignContent: 'start' }}>
                                    {jurisdictions.map((j) => <Panel key={j.id} j={j} period={data.period} />)}
                                    <p style={{ margin: 0, color: '#4a5a63' }}>Figures: {data.period.label.toLowerCase()}, works attributed to each jurisdiction on their own date.</p>
                                </aside>
                            )}
                        </div>
                        <div className="jmp-legend" aria-label="Legend">
                            <h2>Legend</h2>
                            {jurisdictions.map((j) => (
                                <div key={j.id}><svg width="40" height="12" viewBox="0 0 40 12" aria-hidden="true"><PatternDefs /><rect x="0.5" y="0.5" width="39" height="11" fill={patternFill(j.colour_token)} stroke="#0b1a22" /></svg>{j.code} {j.name}</div>
                            ))}
                            <div><svg width="40" height="12" viewBox="0 0 40 12" aria-hidden="true"><line x1="0" y1="6" x2="40" y2="6" stroke="#0b1a22" strokeWidth="2" /><line x1="20" y1="2" x2="20" y2="10" stroke="#0b1a22" /></svg>Centreline, tick each km</div>
                            <div><svg width="40" height="12" viewBox="0 0 40 12" aria-hidden="true"><path d="M20 1 l5 5 l-5 5 l-5 -5 z" fill="#fff" stroke="#0b1a22" strokeWidth="1.2" /></svg>Toll plaza, bridge, interchange</div>
                            <div><svg width="40" height="12" viewBox="0 0 40 12" aria-hidden="true"><circle cx="20" cy="6" r="5" fill="#fff" stroke="#0b1a22" strokeWidth="1.5" /></svg>In-charge of the band</div>
                            {data.coverage.gaps.length > 0 && <div><svg width="40" height="12" viewBox="0 0 40 12" aria-hidden="true"><PatternDefs /><rect x="0.5" y="0.5" width="39" height="11" fill="url(#jp-gap)" stroke="#c0392b" /></svg>Gap: no jurisdiction</div>}
                        </div>
                        <PrintRuler jurisdictions={jurisdictions} coverage={data.coverage} />
                    </div>
                    <Foot tb={tb} page={1} pages={pages} />
                </article>

                {overflow && (
                    <article className="jmp-sheet" data-paper={paper} aria-label={`${tb.title}, page 2 of 2`}>
                        <Head_ tb={tb} paper={paper} asOf={data.as_of} />
                        <div className="jmp-panels" style={{ '--jmp-cols': cols, flex: 1, alignContent: 'start' }}>
                            {jurisdictions.map((j) => <Panel key={j.id} j={j} period={data.period} />)}
                        </div>
                        <p style={{ margin: '2mm 0 0', color: '#4a5a63' }}>Figures: {data.period.label.toLowerCase()}, works attributed to each jurisdiction on their own date.</p>
                        <Foot tb={tb} page={2} pages={2} />
                    </article>
                )}
            </div>
        </div>
    );
}
