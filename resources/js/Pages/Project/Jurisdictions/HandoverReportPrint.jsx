import React from 'react';
import { Head, Link } from '@inertiajs/react';

import { formatDay } from './jurisdictionUtils.js';

const ROLE = { in_charge: 'In-charge', deputy_in_charge: 'Deputy in-charge', member: 'Member' };

/** A4 portrait handover report: who hands to whom, when, the immutable snapshot of open works, and any explicit transfers. */
export default function HandoverReportPrint({ jurisdiction: j, handover: h, works, truncated, team, title_block: tb }) {
    return (
        <div className="jmp-body">
            <Head title={`${tb.title} ${j.code} ${tb.revision}`} />
            <style>{'@page { size: A4 portrait; margin: 0; }'}</style>
            <div className="jmp-controls no-print">
                <strong>Print preview</strong>
                <span className="jmp-spacer" />
                <Link href="/daily-works-unified?tab=jurisdictions">Back to Jurisdictions</Link>
                <button type="button" onClick={() => window.print()}>Print / Save as PDF</button>
            </div>
            <div className="jmp-stack">
                <article className="jmp-sheet jmp-sheet--portrait" data-paper="A4" aria-label={tb.title}>
                    <header className="jmp-head">
                        <div>
                            <h1>{tb.project} <span>{tb.title}</span></h1>
                            <p>{j.code} {j.name}, {j.start_chainage} to {j.end_chainage}</p>
                        </div>
                        <dl className="jmp-title-block">
                            <dt>Generated</dt><dd>{tb.generated_at}</dd>
                            <dt>Effective</dt><dd>{formatDay(h.effective_date)}</dd>
                            <dt>Prepared by</dt><dd>{tb.prepared_by}</dd>
                            <dt>Revision</dt><dd>{tb.revision}</dd>
                        </dl>
                    </header>
                    <section className="jmp-panels" style={{ '--jmp-cols': 2 }}>
                        <div className="jmp-panel"><h3>Outgoing in-charge</h3><p style={{ margin: 0 }}><strong>{h.outgoing?.name ?? 'None recorded'}</strong><br />{h.outgoing?.designation ?? ''}</p></div>
                        <div className="jmp-panel"><h3>Incoming in-charge</h3><p style={{ margin: 0 }}><strong>{h.incoming?.name}</strong><br />{h.incoming?.designation ?? ''}</p></div>
                    </section>
                    {h.note && <p><strong>Note:</strong> {h.note}</p>}
                    <p>Snapshot taken {h.snapshot_at}: <strong>{h.open_works_count}</strong> open works in {j.code} at the time of the handover.{h.transferred > 0 ? ` ${h.transferred} of them were later moved to the incoming in-charge by an explicit, audited transfer.` : ' None has been moved: open works stay recorded under the outgoing in-charge unless a transfer is made.'}</p>
                    <table className="jmp-table">
                        <thead><tr><th>RFI</th><th>Date</th><th>Type</th><th>Location</th><th>Status</th><th>Moved</th></tr></thead>
                        <tbody>
                            {works.length === 0 && <tr><td colSpan="6">No open works at the handover.</td></tr>}
                            {works.map((w) => <tr key={w.id}><td>{w.number}</td><td>{w.date}</td><td>{w.type}</td><td>{w.location}</td><td>{w.status}</td><td>{w.moved ? 'Yes' : 'No'}</td></tr>)}
                        </tbody>
                    </table>
                    {truncated > 0 && <p>{truncated} further works are in the snapshot and are not listed here.</p>}
                    <h3 style={{ margin: '4mm 0 1mm' }}>Team on {formatDay(h.effective_date)}</h3>
                    <ul className="jmp-tree">{team.map((m) => <li key={`${m.employee_id}${m.role}`}><div><strong>{m.name}</strong>, {m.designation ?? 'Employee'} ({ROLE[m.role]})</div></li>)}</ul>
                    <div className="jmp-sign"><div>Outgoing in-charge: signature and date</div><div>Incoming in-charge: signature and date</div></div>
                    <footer className="jmp-foot" style={{ marginTop: 'auto' }}><span>Guardian records; the snapshot cannot be changed after the handover.</span><span>{tb.revision} · Page 1 of 1</span></footer>
                </article>
            </div>
        </div>
    );
}
