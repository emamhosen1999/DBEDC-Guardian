import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';
import PageHeader from '@/Components/PageHeader';
import { Badge, Button, Card, Icon, Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs.jsx';

const ALL = 'all';
const TABS_ID = 'search-groups';

/* Cyber page_search_results composition: one large field with its action inside, group tabs (nav-tabs-v2),
   then result rows (uppercase title, description, link row). Results only include records the viewer may see. */
export default function GlobalSearch({ query = '', groups = [], minimumLength = 2 }) {
    const [value, setValue] = useState(query);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const [tab, setTab] = useState(ALL);
    useEffect(() => setValue(query), [query]);
    // A new search can drop the group that was selected: fall back to "All".
    useEffect(() => { if (tab !== ALL && !groups.some((g) => g.key === tab)) setTab(ALL); }, [groups, tab]);
    const resultCount = groups.reduce((total, group) => total + group.items.length, 0);
    const searched = query.length >= minimumLength;

    const submit = (event) => {
        event.preventDefault();
        const q = value.trim();
        if (processing || q.length < minimumLength) return;
        router.get(route('search'), { q }, {
            preserveState: true,
            replace: true,
            onStart: () => { setProcessing(true); setError(''); },
            onError: (errors) => setError(errors.q || 'Search could not be completed. Please retry.'),
            onFinish: () => setProcessing(false),
        });
    };

    const tabs = [{ key: ALL, label: 'All', count: resultCount }, ...groups.map((g) => ({ key: g.key, label: g.label, count: g.items.length }))];
    const visible = tab === ALL ? groups : groups.filter((g) => g.key === tab);

    return (
        <App>
            <Head title="Search" />
            <PageHeader
                upper
                title="Search"
                muted="Guardian"
                subtitle="Results only include records you are allowed to view."
                chips={[
                    searched ? { value: resultCount, label: 'Results', tone: resultCount > 0 ? 'theme' : 'default' } : null,
                    searched ? { value: groups.length, label: 'Groups', tone: 'default' } : null,
                ]}
            />

            <form onSubmit={submit} role="search" className="cy-searchbar">
                <div className="cy-searchbar__field">
                    <input
                        type="text"
                        className="cy-input cy-input--lg"
                        value={value}
                        onChange={(event) => setValue(event.target.value)}
                        placeholder="Employee, RFI, objection, work order, incident…"
                        aria-label="Search Guardian"
                        maxLength={100}
                        autoFocus
                    />
                    <Button type="submit" color="secondary" className="cy-searchbar__action" disabled={processing || value.trim().length < minimumLength} aria-busy={processing || undefined}>
                        {processing ? 'SEARCHING…' : 'SEARCH'}
                    </Button>
                </div>
                {error && <p className="cy-searchbar__hint cy-searchbar__hint--error" role="alert">{error}</p>}
                {query.length > 0 && query.length < minimumLength && (
                    <p className="cy-searchbar__hint cy-searchbar__hint--warn">Enter at least {minimumLength} characters.</p>
                )}
            </form>

            {groups.length > 0 && <Tabs tabs={tabs} value={tab} onChange={setTab} idPrefix={TABS_ID} label="Result groups" />}

            {searched && resultCount === 0 && (
                <div className="cy-empty">
                    <Icon name="search" className="cy-empty__icon" />
                    <p className="cy-empty__title">No accessible results for “{query}”</p>
                    <p className="cy-empty__text">Try a record number, employee ID, name, location, or description.</p>
                </div>
            )}

            <div role="tabpanel" id={panelId(TABS_ID, tab)} aria-labelledby={tabId(TABS_ID, tab)}>
                {visible.map((group) => (
                    <Card
                        key={group.key}
                        id={`search-group-${group.key}`}
                        title={group.label}
                        flush
                        actions={<Badge color="theme">{group.items.length}</Badge>}
                    >
                        <ul className="cy-results">
                            {group.items.map((item) => (
                                <li key={`${group.key}-${item.id}`}>
                                    <Link href={item.url} className="cy-result">
                                        <span className="cy-result__title">{item.title}</span>
                                        {item.subtitle && <span className="cy-result__text">{item.subtitle}</span>}
                                        {item.meta && <span className="cy-result__meta"><Icon name="link-45deg" />{item.meta}</span>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ))}
            </div>
        </App>
    );
}
