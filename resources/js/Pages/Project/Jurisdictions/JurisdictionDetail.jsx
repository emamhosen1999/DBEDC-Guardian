import React, { useMemo, useState } from 'react';

import { Badge, Button, Card, Icon, StatTile, Tabs } from '@/Components/Cyber';
import { Avatar } from '@/Components/Cyber/Map/Avatar.jsx';

import JurisdictionHistory from './JurisdictionHistory.jsx';
import { JurisdictionTeamTree } from './JurisdictionTeamTree.jsx';
import { kindLabel } from './JurisdictionDialogs.jsx';
import { chainageRange, formatDay, lengthKm } from './jurisdictionUtils.js';

const STATUS_TONE = { completed: 'success', complete: 'success', new: 'secondary', 'in-progress': 'info', resubmission: 'warning', rejected: 'danger', emergency: 'danger', pending: 'warning' };

/** Everything about one jurisdiction on the as-of date: figures for the period, the in-charge, the team tree, the history. */
export default function JurisdictionDetail({ jurisdiction: j, period, isToday = true, abilities, tab, onTab, onEdit, onDelete, onSplit, onMerge, onRetire, onTeam, onTransfer }) {
    const [member, setMember] = useState(null);
    const s = j.stats;
    const isSection = j.kind === 'section';
    const tabs = useMemo(() => [{ key: 'team', label: 'Team' }, { key: 'history', label: 'History' }, ...(isSection ? [{ key: 'works', label: 'Works' }] : [])], [isSection]);
    const ic = j.in_charge;

    return (
        <Card id={`jurisdictions:detail`} title={`${j.code} ${j.name}`} flush
            actions={(abilities.update || abilities.delete || abilities.retire || abilities.restructure) ? (
                <>
                    {abilities.update && <Button size="sm" variant="outline" color="secondary" onClick={onEdit}><Icon name="pencil" /> Edit</Button>}
                    {abilities.restructure && isSection && <Button size="sm" variant="outline" color="secondary" onClick={onSplit}>Split</Button>}
                    {abilities.restructure && isSection && <Button size="sm" variant="outline" color="secondary" onClick={onMerge}>Merge</Button>}
                    {abilities.retire && <Button size="sm" variant="outline" color="danger" onClick={onRetire}>Retire</Button>}
                    {abilities.delete && <Button size="sm" variant="outline" color="danger" onClick={onDelete} title="Only for a jurisdiction added by mistake"><Icon name="trash" /> Delete</Button>}
                </>
            ) : null}
            footer={<span className="cy-fresh">{isSection ? `${period.label}, works attributed to this jurisdiction on their own date` : `${kindLabel(j.kind)}${j.subtype ? `, ${j.subtype.replace('_', ' ')}` : ''}: not a work-attribution kind`}</span>}>
            {isSection && <div className="dl-tiles">
                <StatTile label="Works" value={s.works.toLocaleString('en-GB')} tone="theme" />
                <StatTile label="Completion" value={s.completion_rate === null ? '—' : `${s.completion_rate}%`} tone={s.completion_rate >= 80 ? 'good' : 'neutral'} />
                <StatTile label="RFIs" value={s.rfis.toLocaleString('en-GB')} />
                <StatTile label="Objections" value={s.objections.toLocaleString('en-GB')} tone={s.objections ? 'warn' : 'neutral'} />
                <StatTile label="Last activity" value={s.last_activity ? formatDay(s.last_activity) : 'None'} />
                <StatTile label="In-charge open load" value={ic ? ic.open_workload.toLocaleString('en-GB') : '—'} tone={ic?.open_workload > 200 ? 'warn' : 'neutral'} />
            </div>}
            <div className="jr-detail__section">
                <h4 className="jr-detail__title">Definition</h4>
                <dl className="jr-kv">
                    <dt>Kind</dt><dd><Badge soft color={isSection ? 'theme' : 'info'}>{kindLabel(j.kind)}</Badge>{j.subtype ? <> <Badge soft color="secondary">{j.subtype.replace('_', ' ')}</Badge></> : null}{j.carriageway ? <> <Badge soft color="secondary">{j.carriageway}</Badge></> : null}</dd>
                    {j.start_m !== null && (<><dt>Chainage</dt><dd>{chainageRange(j)} ({lengthKm(j.length_m)})</dd></>)}
                    {j.has_area && (<><dt>Area</dt><dd>Polygon, shown on the map</dd></>)}
                    <dt>Valid</dt><dd>{j.valid_from ? `From ${formatDay(j.valid_from)}` : 'Since records began'}{j.valid_to ? ` until ${formatDay(j.valid_to)}` : ''}{j.retired ? ' (jurisdiction since deleted)' : ''}</dd>
                    {j.description && (<><dt>About</dt><dd>{j.description}</dd></>)}
                    <dt>In-charge</dt>
                    <dd>{ic ? (
                        <span className="jr-person"><Avatar name={ic.name} photo={ic.photo} /><span className="jr-person__text"><strong>{ic.name}</strong><small>{ic.designation ?? 'Employee'}{ic.since ? `, since ${formatDay(ic.since)}` : ', since records began'}</small></span></span>
                    ) : <Badge soft color="danger">No in-charge on this date</Badge>}</dd>
                </dl>
                {j.upcoming.length > 0 && (
                    <div className="jr-upcoming" role="list" aria-label="Planned changes">
                        {j.upcoming.map((u) => <Badge key={`${u.type}${u.from}`} soft color="warning" role="listitem"><Icon name="calendar-event" /> From {formatDay(u.from)}: {u.label}</Badge>)}
                    </div>
                )}
            </div>
            <div className="jr-detail__section">
                <Tabs tabs={tabs} value={tab} onChange={onTab} idPrefix="jr-detail" label="Jurisdiction detail" />
                <div role="tabpanel" id="jr-detail-panel-team" hidden={tab !== 'team'} aria-labelledby="jr-detail-tab-team">
                    {tab === 'team' && (
                        <>
                            {abilities.team && (
                                <div className="jr-detail__actions">
                                    <Button size="sm" onClick={() => onTeam('add')}><Icon name="people" /> Add member</Button>
                                    <Button size="sm" variant="outline" color="secondary" onClick={() => onTeam('in-charge')}><Icon name="arrow-left-right" /> Change in-charge</Button>
                                    {member && member.member_id && member.position !== 'in_charge' && (
                                        <>
                                            <Button size="sm" variant="outline" color="secondary" onClick={() => onTeam('role', member)}>Change role of {member.name}</Button>
                                            <Button size="sm" variant="outline" color="danger" onClick={() => onTeam('remove', member)}>Remove {member.name}</Button>
                                        </>
                                    )}
                                </div>
                            )}
                            {j.team_kpis && (
                                <div className="dl-tiles" aria-label="Team figures">
                                    <StatTile label="Headcount" value={j.team_kpis.headcount} tone="theme" />
                                    <StatTile label={isToday ? 'Present today' : 'Present'} value={j.team_kpis.present} tone="good" />
                                    <StatTile label="On leave" value={j.team_kpis.on_leave} />
                                    <StatTile label={isToday ? 'Not punched in' : 'Absent'} value={j.team_kpis.absent} tone={j.team_kpis.absent ? 'warn' : 'neutral'} />
                                    <StatTile label="Works per person" value={j.team_kpis.works_per_person ?? '—'} />
                                </div>
                            )}
                            {j.team_note && <p className="cy-formtext" role="note"><Icon name="clock-history" /> {j.team_note}</p>}
                            {j.team_warnings > 0 && <p className="cy-formtext" role="note"><Badge soft color="warning">Data quality</Badge> {j.team_warnings} reporting-line problem{j.team_warnings > 1 ? 's' : ''} found (a loop or a very deep line). Each person is shown once.</p>}
                            <p className="cy-formtext">The team is the in-charge, everyone who reports to them (directly or indirectly) and anyone assigned to the jurisdiction.</p>
                            <JurisdictionTeamTree jurisdiction={j} periodLabel={period.label} onSelectMember={setMember} />
                        </>
                    )}
                </div>
                <div role="tabpanel" id="jr-detail-panel-history" hidden={tab !== 'history'} aria-labelledby="jr-detail-tab-history">
                    {tab === 'history' && <JurisdictionHistory jurisdiction={j} canManageTeam={abilities.team} onTransfer={onTransfer} />}
                </div>
                <div role="tabpanel" id="jr-detail-panel-works" hidden={tab !== 'works'} aria-labelledby="jr-detail-tab-works">
                    {tab === 'works' && (
                        <dl className="jr-kv">
                            <dt>By status</dt>
                            <dd>{Object.keys(s.by_status).length ? Object.entries(s.by_status).map(([k, n]) => <Badge key={k} soft color={STATUS_TONE[k] ?? 'secondary'} className="me-1">{k} {n.toLocaleString('en-GB')}</Badge>) : 'No works in this period'}</dd>
                            <dt>By type</dt>
                            <dd>{Object.keys(s.by_type).length ? Object.entries(s.by_type).map(([k, n]) => <Badge key={k} soft color="info" className="me-1">{k} {n.toLocaleString('en-GB')}</Badge>) : 'None'}</dd>
                        </dl>
                    )}
                </div>
            </div>
        </Card>
    );
}
