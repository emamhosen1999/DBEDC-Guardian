/**
 * The QCD register symbol key: the ONE place the front end gets attendance day symbols and
 * their wording from. PHP twin: App\Support\Attendance\AttendanceSymbol (a test keeps them equal).
 * Screens must import from here and never hard-code a glyph.
 */
export const SYMBOL = Object.freeze({
    PRESENT: '√',
    ABSENT: '▼',
    DAY_OFF: '/',
    NOT_YET: '·',
    PERSONAL: '§',
    SICK: '×',
    MARITAL: '□',
    FUNERAL: '■',
    MATERNITY: '◎',
    ANNUAL: '△',
    FESTIVAL: '#',
    /** Approved leave whose type has no symbol: never '/', which is the weekly off. */
    UNKNOWN_LEAVE: 'L',
});

/** Status wording shared by every screen (PHP twin: AttendanceSymbol::TERM_*). */
export const TERM = Object.freeze({
    PRESENT: 'Present',
    ABSENT: 'Absent',
    DAY_OFF: 'Weekly Off / Rest Day',
    NOT_YET: 'Not Yet Recorded',
    ON_LEAVE: 'On Leave',
    FESTIVAL: 'Festival Holiday',
});

/** Symbol -> key wording, in register order. */
export const SYMBOL_KEY = Object.freeze([
    [SYMBOL.PRESENT, TERM.PRESENT],
    [SYMBOL.ABSENT, TERM.ABSENT],
    [SYMBOL.DAY_OFF, TERM.DAY_OFF],
    [SYMBOL.NOT_YET, TERM.NOT_YET],
    [SYMBOL.PERSONAL, 'Personal Leave'],
    [SYMBOL.SICK, 'Sick Leave'],
    [SYMBOL.MARITAL, 'Marital Leave'],
    [SYMBOL.FUNERAL, 'Funeral Leave'],
    [SYMBOL.MATERNITY, 'Maternity Leave'],
    [SYMBOL.ANNUAL, 'Annual Holiday'],
    [SYMBOL.FESTIVAL, TERM.FESTIVAL],
]);

/** The eight "Reason for Leave (Days)" totals, in register order: [heading, symbol]. */
export const SYMBOL_TOTALS = Object.freeze([
    ['Abs', SYMBOL.ABSENT],
    ['Per', SYMBOL.PERSONAL],
    ['Sick', SYMBOL.SICK],
    ['Mar', SYMBOL.MARITAL],
    ['Fun', SYMBOL.FUNERAL],
    ['Mat', SYMBOL.MATERNITY],
    ['Ann', SYMBOL.ANNUAL],
    ['Fest', SYMBOL.FESTIVAL],
]);

/** The key row text, exactly as the register prints it. */
export const SYMBOL_KEY_TEXT = `Symbol Key :    ${SYMBOL_KEY.map(([s, l]) => `${s} = ${l}`).join('     ')}`;

const LABELS = Object.freeze(Object.fromEntries(SYMBOL_KEY));

export const symbolLabel = (symbol) => LABELS[symbol] ?? (symbol === SYMBOL.UNKNOWN_LEAVE ? TERM.ON_LEAVE : 'No data');

export const isKnownSymbol = (symbol) => Object.prototype.hasOwnProperty.call(LABELS, symbol) || symbol === SYMBOL.UNKNOWN_LEAVE;

/** Colour tone, a SECONDARY cue only (the symbol itself is always shown). */
export const symbolTone = (symbol) => {
    switch (symbol) {
        case SYMBOL.PRESENT: return 'success';
        case SYMBOL.ABSENT: return 'danger';
        case SYMBOL.FESTIVAL: return 'festival';
        case SYMBOL.DAY_OFF:
        case SYMBOL.NOT_YET: return 'muted';
        default: return isKnownSymbol(symbol) ? 'leave' : 'none';
    }
};

/**
 * The register's festival colouring for one cell: a festival date's holiday is pink,
 * working it is green. Returns 'taken' | 'worked' | null.
 */
export const festivalFill = (symbol, isFestivalDate) => {
    if (!isFestivalDate) return null;
    if (symbol === SYMBOL.FESTIVAL) return 'taken';
    if (symbol === SYMBOL.PRESENT) return 'worked';
    return null;
};

/**
 * Per-employee totals for the register's eight "Reason for Leave (Days)" columns, counted from the
 * day cells (the same COUNTIF the register uses), so every screen agrees with the export.
 * `days` is an iterable of day cells ({ symbol } | { status } | a bare symbol).
 */
export const symbolTotals = (days) => {
    const counts = Object.fromEntries(SYMBOL_TOTALS.map(([, s]) => [s, 0]));
    for (const cell of days) {
        const s = typeof cell === 'string' ? cell : (cell?.symbol ?? cell?.status);
        if (s in counts) counts[s] += 1;
    }
    return SYMBOL_TOTALS.map(([heading, s]) => ({ heading, symbol: s, label: symbolLabel(s), count: counts[s] }));
};

/** The mark legend wording, word for word as the Excel/PDF "Marks :" row prints it (PHP twin: AttendanceSymbol::MARK_LEGEND). */
export const MARK_LEGEND = Object.freeze([
    ['ot_approved', 'green √ = overtime approved'],
    ['ot_unapplied', 'yellow = worked on a rest day/holiday, not claimed'],
    ['ot_declined', 'light red = overtime declined'],
    ['rest_day_work', 'bold border = worked on rest day'],
    ['leave_bold', 'bold symbol = on leave'],
]);

export const MARKS_LEGEND_TEXT = `Marks :    ${MARK_LEGEND.map(([, t]) => t).join('     ')}`;

/**
 * DOM attributes for one day cell from the server's `marks` (the single rule set, AttendanceSymbol::marks):
 * fill (festival/overtime), green font, bold symbol, bold border. A cell without `marks` falls back to the
 * festival colouring only.
 */
export const markAttrs = (cell, symbol) => {
    const m = cell?.marks;
    if (!m) {
        const fill = festivalFill(symbol, Boolean(cell?.is_festival));
        return fill ? { 'data-festival': fill } : {};
    }
    const out = {};
    if (m.fill === 'festival_taken') out['data-festival'] = 'taken';
    else if (m.fill === 'festival_worked') out['data-festival'] = 'worked';
    else if (m.fill) out['data-mark-fill'] = m.fill;
    if (m.font) out['data-mark-font'] = m.font;
    if (m.bold) out['data-mark-bold'] = 'true';
    if (m.border) out['data-mark-border'] = 'true';
    return out;
};
