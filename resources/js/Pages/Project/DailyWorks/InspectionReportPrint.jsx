import React from 'react';
import { Head, Link } from '@inertiajs/react';

import { RESULTS, formatDay, statusMeta } from './dwUtils.js';

const ROLE = { in_charge: 'In-charge', deputy_in_charge: 'Deputy in-charge', member: 'Member' };

/** A4 portrait Daily Inspection Report for one date (and optionally one jurisdiction): works, status totals, the team that day, signatures. */
const FIRST = 30; // rows that fit under the title block and totals on the first sheet
const NEXT = 50; // rows on a continuation sheet
const LAST_RESERVE = 14; // rows' worth of room the team list and the signatures need on the final sheet

/** Split the works over sheets so nothing is cut off; the final sheet always has room for the team and signatures. */
export function paginate(works, withTeam = true) {
    const pages = [];
    let i = 0;
    let cap = FIRST;
    while (i < works.length) { pages.push(works.slice(i, i + cap)); i += cap; cap = NEXT; }
    if (pages.length === 0) pages.push([]);
    const reserve = LAST_RESERVE + (withTeam ? 0 : -6);
    const lastCap = pages.length === 1 ? FIRST : NEXT;
    if (pages[pages.length - 1].length > lastCap - reserve) pages.push([]);
    return pages;
}

/** A4 portrait Daily Inspection Report for one date (and optionally one jurisdiction): works, status totals, the team that day, signatures. */
export default function InspectionReportPrint({ date, jurisdiction: j, works, total, truncated, status_totals: totals, result_totals: results, team, title_block: tb }) {
    const statusRows = Object.entries(totals).map(([k, n]) => [statusMeta(k).label, n]);
    const pages = paginate(works, !!j);
    const count = pages.length;
    return (
        <div className="jmp-body">
            <Head title={`${tb.title} ${tb.revision}`} />
            <style>{'@page { size: A4 portrait; margin: 0; }'}</style>
            <div className="jmp-controls no-print">
                <strong>Print preview</strong>
                <span className="jmp-spacer" />
                <Link href="/daily-works-unified">Back to Daily Works</Link>
                <button type="button" onClick={() => window.print()}>Print / Save as PDF</button>
            </div>
            <div className="jmp-stack">
                {pages.map((rows, n) => (
                    <article key={n} className="jmp-sheet jmp-sheet--portrait" data-paper="A4" aria-label={`${tb.title}, page ${n + 1} of ${count}`}>
                        {n === 0 ? (
                            <>
                                <header className="jmp-head">
                                    <div>
                                        <h1>{tb.project} <span>{tb.title}</span></h1>
                                        <p>{formatDay(date)}{j ? `, ${j.code} ${j.name}, ${j.start_chainage} to ${j.end_chainage}` : ', all jurisdictions in your scope'}</p>
                                    </div>
                                    <dl className="jmp-title-block">
                                        <dt>Generated</dt><dd>{tb.generated_at}</dd>
                                        <dt>Inspection date</dt><dd>{formatDay(date)}</dd>
                                        <dt>Prepared by</dt><dd>{tb.prepared_by}</dd>
                                        <dt>Revision</dt><dd>{tb.revision}</dd>
                                    </dl>
                                </header>
                                <section className="jmp-panels" style={{ '--jmp-cols': 3 }}>
                                    <div className="jmp-panel"><h3>RFIs</h3><p style={{ margin: 0 }}><strong>{total}</strong> on this date</p></div>
                                    <div className="jmp-panel"><h3>By status</h3><p style={{ margin: 0 }}>{statusRows.length ? statusRows.map(([k, c]) => `${k} ${c}`).join(', ') : 'None'}</p></div>
                                    <div className="jmp-panel"><h3>Inspection results</h3><p style={{ margin: 0 }}>{Object.keys(results).length ? Object.entries(results).map(([k, c]) => `${RESULTS[k]?.label ?? k} ${c}`).join(', ') : 'None recorded'}</p></div>
                                </section>
                            </>
                        ) : (
                            <header className="jmp-head"><div><h1>{tb.project} <span>{tb.title}</span></h1><p>{formatDay(date)}{j ? `, ${j.code}` : ''} (continued)</p></div><dl className="jmp-title-block"><dt>Revision</dt><dd>{tb.revision}</dd></dl></header>
                        )}
                        {rows.length > 0 || n === 0 ? (
                            <table className="jmp-table">
                                <thead><tr><th>RFI</th><th>Type</th><th>Location</th><th>Side</th><th>Status</th><th>Result</th><th>In-charge</th></tr></thead>
                                <tbody>
                                    {works.length === 0 && <tr><td colSpan="7">No daily works are recorded for this date{j ? ` in ${j.code}` : ''}.</td></tr>}
                                    {rows.map((w) => <tr key={w.id}><td>{w.number}</td><td>{w.type}</td><td>{w.location}</td><td>{w.side}</td><td>{statusMeta(w.status).label}</td><td>{w.result ? (RESULTS[w.result]?.label ?? w.result) : ''}</td><td>{w.incharge}</td></tr>)}
                                </tbody>
                            </table>
                        ) : null}
                        {n === count - 1 && (
                            <>
                                {truncated > 0 && <p>{truncated} further RFIs on this date are not listed here. Narrow the report to a jurisdiction to list them.</p>}
                                {j && (
                                    <>
                                        <h3 style={{ margin: '4mm 0 1mm' }}>Team on {formatDay(date)}</h3>
                                        {team.length === 0 ? <p>No team is recorded for {j.code} on this date.</p> : <ul className="jmp-tree">{team.map((m) => <li key={`${m.employee_id}${m.role}`}><div><strong>{m.name}</strong>, {m.designation ?? 'Employee'} ({ROLE[m.role] ?? m.role})</div></li>)}</ul>}
                                    </>
                                )}
                                <div className="jmp-sign" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}><div>Inspected by: signature and date</div><div>Section in-charge: signature and date</div><div>Quality control: signature and date</div></div>
                            </>
                        )}
                        <footer className="jmp-foot" style={{ marginTop: 'auto' }}><span>Guardian records as at {tb.generated_at}.</span><span>{tb.revision} · Page {n + 1} of {count}</span></footer>
                    </article>
                ))}
            </div>
        </div>
    );
}
