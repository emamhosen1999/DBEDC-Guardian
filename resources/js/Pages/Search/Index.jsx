import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { MagnifyingGlassIcon } from '@radix-ui/react-icons';
import { Badge, Button, Text, TextField } from '@radix-ui/themes';
import App from '@/Layouts/App.jsx';
import PageHeader from '@/Components/PageHeader';

export default function GlobalSearch({ query = '', groups = [], minimumLength = 2 }) {
    const [value, setValue] = useState(query);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => setValue(query), [query]);
    const resultCount = groups.reduce((total, group) => total + group.items.length, 0);

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

    return (
        <App>
            <Head title="Search" />
            <PageHeader
                title="Search Guardian"
                subtitle="Results only include records you are allowed to view."
                chips={[
                    query.length >= minimumLength ? { value: resultCount, label: 'Results', tone: resultCount > 0 ? 'theme' : 'default' } : null,
                    query.length >= minimumLength ? { value: groups.length, label: 'Groups', tone: 'default' } : null,
                ]}
            />

            {/* Cyber page_search_results: one .form-control-lg with its action inside the field */}
            <form onSubmit={submit} role="search" className="dl-search-bar">
                <TextField.Root
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    placeholder="Employee, RFI, objection, work order, incident…"
                    size="3"
                    aria-label="Search Guardian"
                    maxLength={100}
                    autoFocus
                >
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    <TextField.Slot side="right">
                        <Button type="submit" size="1" className="dl-btn-secondary" loading={processing} disabled={processing || value.trim().length < minimumLength}>
                            Search
                        </Button>
                    </TextField.Slot>
                </TextField.Root>
                {error && <Text as="p" color="red" role="alert" mt="2">{error}</Text>}
                {query.length > 0 && query.length < minimumLength && (
                    <Text as="p" color="amber" size="2" mt="2">Enter at least {minimumLength} characters.</Text>
                )}
            </form>

            {query.length >= minimumLength && resultCount === 0 && (
                <div className="dl-empty">
                    <Text weight="medium" as="div">No accessible results for “{query}”.</Text>
                    <Text size="2" color="gray">Try a record number, employee ID, name, location, or description.</Text>
                </div>
            )}

            {groups.map((group) => (
                <section key={group.key} className="dl-card dl-card--page" aria-labelledby={`search-group-${group.key}`}>
                    <header className="dl-card__header">
                        <h2 className="dl-card__title" id={`search-group-${group.key}`}>{group.label}</h2>
                        <span className="dl-hud-line" aria-hidden="true" />
                        <Badge variant="soft">{group.items.length}</Badge>
                    </header>
                    <ul className="dl-list dl-results">
                        {group.items.map((item) => (
                            <li key={`${group.key}-${item.id}`}>
                                <Link href={item.url} className="dl-list__item dl-result">
                                    <span className="dl-result__title">{item.title}</span>
                                    {item.subtitle && <span className="dl-result__text">{item.subtitle}</span>}
                                    {item.meta && <span className="dl-result__meta">{item.meta}</span>}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </App>
    );
}
