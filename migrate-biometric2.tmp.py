import io, re

p = 'resources/js/Components/AdminUnified/BiometricPanel.jsx'
s = io.open(p, encoding='utf-8').read()

# Patch a log tab in place. `head` identifies the tab; everything else is the
# same shape in both. The search is a client-side filter over the loaded page,
# so it goes through the draft layer purely for a nicer typing feel.
def patch_tab(func_name, prefix, route_name):
    global s
    start = s.index(f'function {func_name}(')
    end = s.index('\nfunction ', start + 10)
    body = s[start:end]

    state_old = """    const [search, setSearch]   = useState('');
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });""" if func_name == 'LogsTab' else """    const [search, setSearch] = useState('');
    const [pagination, setPagination] = useState({ currentPage: 1, perPage: 20, total: 0 });"""
    assert state_old in body, f'{func_name} state'
    state_new = f"""    /* Page, size and search live in the URL under a `{prefix}` prefix so they
       survive leaving the panel and coming back. */
    const f = useQueryFilters({{
        mode: 'client',
        pageKey: '{prefix}page',
        debounceKeys: ['{prefix}q'],
        defaults: {{ {prefix}q: '', {prefix}page: 1, {prefix}per: 20 }},
    }});
    const search = f.values.{prefix}q;
    const [total, setTotal] = useState(0);
    const pagination = {{ currentPage: f.values.{prefix}page, perPage: f.values.{prefix}per, total }};"""
    body = body.replace(state_old, state_new, 1)

    load_old = f"""    const load = useCallback(async (page = pagination.currentPage, pp = pagination.perPage) => {{
        setLoading(true);
        try {{
            const {{ data }} = await axios.get(route('{route_name}'), {{
                params: {{ page, per_page: pp }}
            }});
            setLogs(data.logs ?? []);
            setPagination(prev => ({{
                ...prev,
                currentPage: data.current_page || 1,
                total: data.total || 0,
            }}));
        }} catch {{ showToast.error('Failed to load logs.'); }}
        finally {{ setLoading(false); }}
    }}, [pagination.currentPage, pagination.perPage]);

    useEffect(() => {{ load(1); }}, [load]);"""
    assert load_old in body, f'{func_name} load'
    load_new = f"""    const load = useCallback(async () => {{
        setLoading(true);
        try {{
            const {{ data }} = await axios.get(route('{route_name}'), {{
                params: {{ page: pagination.currentPage, per_page: pagination.perPage }}
            }});
            setLogs(data.logs ?? []);
            setTotal(data.total || 0);
        }} catch {{ showToast.error('Failed to load logs.'); }}
        finally {{ setLoading(false); }}
    }}, [pagination.currentPage, pagination.perPage]);

    // Follows the URL. (This used to call load(1) on every change, so the
    // pager never actually left page 1.)
    useEffect(() => {{ load(); }}, [load]);"""
    body = body.replace(load_old, load_new, 1)

    handlers_old = """    const handlePageChange = (page) => {
        setPagination(prev => ({ ...prev, currentPage: page }));
    };

    const handleRowsPerPageChange = (newPerPage) => {
        setPagination(prev => ({ ...prev, perPage: newPerPage, currentPage: 1 }));
    };"""
    assert handlers_old in body, f'{func_name} handlers'
    body = body.replace(handlers_old, f"""    const handlePageChange = f.setPage;
    const handleRowsPerPageChange = (newPerPage) => f.set('{prefix}per', newPerPage);""", 1)

    n = body.count("onChange={e => setSearch(e.target.value)}")
    assert n == 1, f'{func_name} search onChange x{n}'
    body = body.replace("onChange={e => setSearch(e.target.value)}",
                        f"value={{f.draft.{prefix}q}}\n                    onChange={{e => f.setDraft('{prefix}q', e.target.value)}}", 1)
    body = body.replace("onClick={() => setSearch('')}", f"onClick={{() => f.setDraft('{prefix}q', '')}}", 1)

    # Any refresh button that passed the old positional args.
    body = re.sub(r"load\(\s*pagination\.currentPage[^)]*\)", "load()", body)
    body = re.sub(r"load\(1\)", "load()", body)

    assert 'setPagination' not in body and 'setSearch(' not in body, f'{func_name} leftovers'
    s = s[:start] + body + s[end:]
    print('patched', func_name)


patch_tab('LogsTab', 'lg_', 'biometric-devices.logs')
patch_tab('OperLogTab', 'ol_', 'biometric-devices.operlogs')
io.open(p, 'w', encoding='utf-8').write(s)
