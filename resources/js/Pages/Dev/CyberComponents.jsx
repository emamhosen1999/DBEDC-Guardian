import React, { useState } from 'react';
import { Head } from '@inertiajs/react';

import App from '@/Layouts/App.jsx';
import { Accordion, Alert, Badge, Button, Card, Checkbox, FormField, Icon, Input, Pagination, Progress, StatTile, Switch, Tabs, Textarea } from '@/Components/Cyber';
import { Timeline, Tree } from '@/Components/Cyber';

/**
 * Local-only component gallery for the Cyber conformance check (scripts/design/review/component-conformance.cjs):
 * every shared Cyber component and variant once, each tagged with data-conf so the check can measure it against the
 * matching element on Cyber's own ui pages. Registered as a route in the local environment only; static labels, no data.
 */
export default function CyberComponents() {
    const [tab, setTab] = useState('a');
    return (
        <>
            <Head title="Cyber components" />
            <div className="dl-page">
                <div className="dl-row">
                    <div className="dl-col dl-col--6">
                        <Card id="dev:buttons" title="Buttons and badges">
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', alignItems: 'center' }}>
                                <Button data-conf="btn-theme">Theme</Button>
                                <Button variant="outline" data-conf="btn-outline-theme">Outline theme</Button>
                                <Button color="secondary" data-conf="btn-secondary">Secondary</Button>
                                <Button variant="outline" color="secondary" data-conf="btn-outline-secondary">Outline secondary</Button>
                                <Button size="sm" variant="outline" data-conf="btn-sm">Small</Button>
                                <Badge data-conf="badge-theme">Theme</Badge>
                                <Badge color="success">Success</Badge>
                                <Badge color="warning" outline data-conf="badge-outline">Warning</Badge>
                            </div>
                        </Card>
                    </div>
                    <div className="dl-col dl-col--6">
                        <Card id="dev:forms" title="Form controls">
                            <div style={{ display: 'grid', gap: '0.5rem' }}>
                                <input className="cy-input" data-conf="input" placeholder="Default input" />
                                <input className="cy-input cy-input--lg" data-conf="input-lg" placeholder="Large input" />
                                <select className="cy-select" data-conf="select" defaultValue="1"><option value="1">Option one</option><option value="2">Option two</option></select>
                                <FormField label="Form label" htmlFor="dev-label-input"><Input id="dev-label-input" placeholder="Input with label" data-conf="form-input" /></FormField>
                                <Textarea data-conf="textarea" placeholder="Textarea" rows={2} />
                                <div data-conf="checkbox"><Checkbox label="Checkbox" checked onCheckedChange={() => {}} /></div>
                                <div data-conf="switch"><Switch label="Switch" checked={false} onCheckedChange={() => {}} /></div>
                                <div className="cy-actions"><Button color="success" size="sm" data-conf="btn-success">Success</Button><Button color="danger" variant="outline" size="sm" data-conf="btn-outline-danger">Outline danger</Button></div>
                            </div>
                        </Card>
                    </div>
                </div>
                <div className="dl-row">
                    <div className="dl-col dl-col--6">
                        <Card id="dev:tabs" title="Tabs and accordion" flush>
                            <div data-conf="tabs">
                                <Tabs tabs={[{ key: 'a', label: 'First', count: 3 }, { key: 'b', label: 'Second' }, { key: 'c', label: 'Third' }]} value={tab} onChange={setTab} idPrefix="dev-tabs" label="Example tabs" />
                            </div>
                            <div data-conf="accordion">
                                <Accordion items={[{ key: 'one', title: 'Accordion item one', content: 'Panel content.' }, { key: 'two', title: 'Accordion item two', content: 'Panel content.' }]} />
                            </div>
                        </Card>
                    </div>
                    <div className="dl-col dl-col--6">
                        <Card id="dev:feedback" title="Alerts and progress">
                            <div style={{ display: 'grid', gap: '0.5rem' }}>
                                <div className="cy-alert" data-tone="danger" data-conf="alert-danger"><Icon name="exclamation-triangle" /><span>Danger alert</span></div>
                                <div className="cy-alert" data-tone="warning" data-conf="alert-warning"><Icon name="exclamation-triangle" /><span>Warning alert</span></div>
                                <div data-conf="progress"><Progress value={60} label="Example progress" /></div>
                            </div>
                        </Card>
                    </div>
                </div>
                <div className="dl-row">
                    <div className="dl-col dl-col--6">
                        <Card id="dev:table" title="Table and list" flush>
                            <table className="cy-table" data-conf="table">
                                <thead><tr><th>Column</th><th>Column</th></tr></thead>
                                <tbody><tr><td>Cell</td><td>Cell</td></tr><tr><td>Cell</td><td>Cell</td></tr></tbody>
                            </table>
                            <ul className="dl-list" data-conf="list"><li className="dl-list__row"><div className="cy-row"><span className="cy-row__title">List row</span></div></li><li className="dl-list__row"><div className="cy-row"><span className="cy-row__title">List row</span></div></li></ul>
                        </Card>
                    </div>
                    <div className="dl-col dl-col--6">
                        <Card id="dev:paging" title="Pagination, tiles and modal" flush>
                            <div data-conf="pagination"><Pagination pagination={{ currentPage: 2, perPage: 10, total: 45 }} onPageChange={() => {}} onRowsPerPageChange={() => {}} /></div>
                            <div className="dl-tiles"><StatTile label="Tile" value="12" /><StatTile label="Tile" value="34" tone="theme" /></div>
                            <div className="cy-modal" data-conf="modal" style={{ position: 'static' }}>
                                <div className="cy-modal__head"><h2>Modal title</h2></div>
                                <div className="cy-modal__section">Modal body</div>
                            </div>
                        </Card>
                    </div>
                </div>
                <div className="dl-row">
                    <div className="dl-col dl-col--6">
                        <Card id="dev:tree" title="Tree" flush>
                            <div className="jr-detail__section">
                                <Tree data-conf="tree" label="Gallery tree" selectedId="a" items={[{ id: 'a', label: 'services', icon: 'layers', children: [{ id: 'b', label: 'app_development', icon: 'layers', children: [{ id: 'c', label: 'index.html', icon: 'file-earmark-pdf' }] }] }, { id: 'd', label: 'readme.txt', icon: 'file-earmark-pdf' }]} />
                            </div>
                        </Card>
                    </div>
                    <div className="dl-col dl-col--6">
                        <Card id="dev:timeline" title="Timeline" flush>
                            <Timeline data-conf="timeline" label="Gallery timeline" events={[{ id: 'e1', date: '2026-01-02', title: 'Event title', detail: 'Event detail', tone: 'theme' }, { id: 'e2', date: null, title: 'Earlier event', tone: 'secondary' }]} />
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

CyberComponents.layout = (page) => <App>{page}</App>;
