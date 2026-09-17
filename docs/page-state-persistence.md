# Page state persistence

How DBEDC Guardian keeps a page in the state the user left it in.

A user filters `/employees`, opens an employee, then comes back. The list should
still be filtered. That is the whole goal. This document is the single
convention for achieving it — pages should not invent their own.

## The decision rule

Ask one question about every piece of state:

> Does this change which rows the server returns, or should it survive a
> refresh, a copied link, or a bookmark?

- **Yes** → the URL query string. Use `useQueryFilters()`.
- **No** → does returning to the page need to restore it?
  - **Yes** → Inertia remembered state. Use `usePersistentPageState()`.
  - **No** → plain `useState`. Most state is this.

```
search, status, location, severity, owner,     ->  URL
date_from, date_to, sort, direction,
page, per_page, a tab that changes the query

view mode, table density, column visibility,   ->  usePersistentPageState
expanded rows, collapsed panels, inspector
panel, a purely presentational tab

hover, drag, toast visibility, a delete         ->  useState
confirmation, in-flight flags, animation
```

Each value gets **one** authoritative home. A search term that lives in the URL
*and* in `useState` *and* in remembered state will drift, and the bug it causes
will be hard to find.

## URL state — `useQueryFilters()`

```js
import { useQueryFilters } from '@/Hooks/useQueryFilters';

const f = useQueryFilters({
    routeName: 'employees.index',
    mode: 'server',                     // or 'client' — see below
    defaults: { search: '', status: 'all', sort: 'name', direction: 'asc', page: 1, per_page: 15 },
});

<TextField value={f.draft.search} onChange={e => f.setDraft('search', e.target.value)} />
<Select value={f.values.status} onValueChange={v => f.set('status', v)} />
<Th onClick={() => f.setSort('name')} />
<Pagination page={f.values.page} onChange={f.setPage} />
{f.isFiltered && <Button onClick={f.reset}>Reset filters</Button>}
```

`defaults` does three jobs: it is the fallback, it declares the type (a numeric
default means `?page=4` comes back as the number `4`), and it defines what
"unfiltered" means — a value equal to its default is left out of the URL, so a
pristine page is a bare path and `reset()` produces a clean link.

### The two modes

The application fetches list data two ways, so the hook supports both.

| | `mode: 'server'` | `mode: 'client'` |
|---|---|---|
| Rows arrive as | Inertia props | react-query / axios |
| Committing a filter | `router.get()` — controller re-runs | `router.replace()` — client-side visit, **no server round trip** |
| Page feeds filters to | nothing; props update | its react-query key |

In `client` mode the URL still changes and still lands in browser history, so
refresh, copied links and Back/Forward behave identically. The only difference
is who fetches the rows.

```js
// client mode: react-query keys off the URL, so the URL drives the data
const f = useQueryFilters({ mode: 'client', defaults: {...} });
const { data } = useEmployeesList(f.values);
```

### Rules the hook already enforces

- Search is debounced (350 ms) and commits with `replace: true`, so typing
  "rahim" leaves **one** history entry, not five.
- Any filter change resets to page 1. Changing page does not.
- Pagination pushes a real history entry; filters replace. Back from page 4
  goes to page 3, not back through every keystroke.
- A commit that would not change the URL is skipped, so no redundant requests.
- Navigation only happens from a user action or the debounce timer. Nothing
  watches props and re-navigates, so there are no effect loops.

### Reopening a page from the sidebar

Application navigation lands on the bare route — `/attendance`, not
`/attendance?tab=roster&r_dept=5`. The hook remembers the last query each page
was left with (per signed-in user, in memory for the SPA session) and, when a
page is opened bare, re-applies it with a `replace`. So the sidebar, Back, a
refresh and a copied link all land on the same view. An explicit reset leaves
the page bare and is remembered as such, so reset stays reset.

### Panels that stay mounted together

Some pages keep every tab mounted (`display: none`), so several hooks read the
same URL at once. Each panel then owns a short prefix so their keys cannot
collide, and the page-level hook owns the unprefixed `tab`/`date`/`month`:

```
Attendance:  r_ roster · t_ timesheet · m_ monthly · a_ approvals
             s_ shifts · st_ settings · loc_ locations card · asg_ assignments
Biometric:   b_ section · bl_ att-logs · lg_ logs · ol_ operlog · dl_ downloads
             tp_ templates · hl_ health · rc_ reconciliation
Roles:       rp_ section · rl_ roles · pm_ permissions · ur_ users
Leaves:      al_ admin list · bal_ balances · an_ analytics · ls_ settings · sm_ summary
Petty cash:  tx_ transactions · au_ audit log
Holidays:    h_          Daily works: dw_ mobile tab · sum_ summary · ju_ jurisdictions
```

Pass `pageKey` when a panel has its own pager (`pageKey: 'r_page'`), so the
"return to page 1 on filter change" rule applies to the right key.

## Remembered state — `usePersistentPageState()`

```js
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';

const [ui, setUi, resetUi] = usePersistentPageState('Employees/Index', {
    viewMode: 'table',
    expandedRows: [],
});

setUi({ viewMode: 'grid' });
setUi(prev => ({ expandedRows: [...prev.expandedRows, id] }));
```

### Keys

`ui:v1:{Page}`, namespaced by user automatically:

```
ui:v1:user:42:Employees/Index
ui:v1:user:42:Cameras/Index
```

Entity pages must include the record id, or two records share one drawer state:

```js
usePersistentPageState(`Objections/Show:${objection.id}`, {...})
```

Bump `v1` → `v2` in the hook if the shape of remembered state changes in a way
that stale browser history cannot be merged into safely.

### What must never go in it

Remembered state is written into the browser history entry. It is size-limited
and readable on the device.

- No server payloads. Remember `expandedRows: [12, 19]`, never the row objects.
- No passwords, tokens, API keys, or anything else sensitive.
- No high-frequency values — pointer position, drag offsets, resize deltas.
  Debounce to a checkpoint, or do not persist at all.

Newly added keys merge over stale remembered state automatically, so adding a
field to `defaults` will not break a user whose history predates it.

## Forms

Inertia's `useForm` takes a remember key as its first argument:

```js
useForm(`CreateEmployee`, { name: '', email: '' });
useForm(`EditEmployee:${employee.id}`, {...});
```

Never give a remember key to a form containing a password, OTP, token or any
other credential. Those forms use `useForm({...})` with no key.

## Reset

"Reset filters" clears URL state only. View mode, density and column visibility
are the user's standing preferences and survive it — `resetUi()` is a separate,
deliberate action.

## Layout state

Sidebar and theme live in the zustand store (`@/store/uiStore`) because they
belong to the persistent layout, not to any page. Do not copy them into page
state.
