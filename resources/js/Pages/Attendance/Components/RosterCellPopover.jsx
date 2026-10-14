import React, { useState, useEffect } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button, Checkbox, FormField, SelectInput } from '@/Components/Cyber';
import { toggleShiftId } from '../rosterShiftSelection';

const MAX_SHIFTS = 3;

/**
 * Cell editor for one roster day, in the shared Cyber dialog.
 * shifts: [{id,code,name,color}]; selectedShiftIds: number[] (the cell's
 * current assignment - derive with rosterShiftSelection.deriveSelectedShiftIds).
 * leave: {type,status,fraction,session}|null - the leave the grid overlay found for this cell's date.
 * onPick(shiftIds: number[], workLocationIdOrNull) - fires on Confirm.
 * violations: Violation[]|null - working-time compliance results for the last write
 *   attempt on this cell (either a 200-with-warnings or a 422-blocked response).
 * violationsBlocked: true when the write was rejected (422) and did not apply.
 * `anchor` is accepted for the old call sites and ignored: the dialog is centred.
 */
export default function RosterCellPopover({ open, onOpenChange, shifts = [], selectedShiftIds = [], notice = null, violations = null, violationsBlocked = false, workLocations = [], selectedLocationId = null, leave = null, cellDate = null, onPick, onReset = null, canReset = false, finalized = false }) {
    const [locId, setLocId] = useState(selectedLocationId ? String(selectedLocationId) : 'home');
    const [selectedIds, setSelectedIds] = useState(selectedShiftIds);

    useEffect(() => { setLocId(selectedLocationId ? String(selectedLocationId) : 'home'); }, [selectedLocationId, open]);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- resync only when the dialog (re)opens for a (possibly different) cell
    useEffect(() => { setSelectedIds(selectedShiftIds); }, [open, JSON.stringify(selectedShiftIds)]);

    if (!open) return null;

    const isOff = selectedIds.length === 0;
    const atCap = selectedIds.length >= MAX_SHIFTS;

    const toggleShift = (shiftId) => setSelectedIds(prev => toggleShiftId(prev, shiftId, MAX_SHIFTS));
    const clearToOff = () => setSelectedIds([]);

    const approvedLeave = leave && String(leave.status).toLowerCase() === 'approved' ? leave : null;
    const pendingLeave = leave && !approvedLeave ? leave : null;
    // A working shift on an approved-leave date never counts as absence: the leave wins. Say so BEFORE
    // someone locks a shift there that suggests otherwise.
    const warnsLeaveConflict = Boolean(approvedLeave) && selectedIds.length > 0;

    const confirm = () => {
        onPick(selectedIds, locId === 'home' ? null : Number(locId));
    };

    const close = () => onOpenChange?.(false);

    return (
        <Dialog title={cellDate ? `Roster - ${cellDate}` : 'Roster cell'} onClose={close} className="cy-modal cy-modal--narrow">
            <div className="cy-modal__section cy-stack">
                {notice && <Alert tone="warning">{notice}</Alert>}
                {approvedLeave && (
                    <span data-testid="roster-leave-warning">
                        <Alert tone={warnsLeaveConflict ? 'warning' : 'info'}>
                            Approved leave{approvedLeave.type ? ` (${approvedLeave.type})` : ''} on this date.
                            {warnsLeaveConflict ? ' The leave takes precedence over a working shift: this person will show On Leave, not Absent.' : ''}
                        </Alert>
                    </span>
                )}
                {pendingLeave && <Alert tone="info">A leave request is pending for this date.</Alert>}
                {violations && violations.length > 0 && (
                    <Alert tone={violationsBlocked ? 'danger' : 'warning'} title={violationsBlocked ? 'Blocked by working-time rules' : 'Compliance warning'}>
                        {violations.map((v, i) => <div key={i}>{v.date} - {v.message}</div>)}
                    </Alert>
                )}
                {workLocations.length > 0 && (
                    <FormField label="Post" htmlFor="roster-cell-post">
                        <SelectInput
                            id="roster-cell-post"
                            value={locId}
                            onChange={setLocId}
                            options={[{ value: 'home', label: 'Home location' }, ...workLocations.map(l => ({ value: String(l.id), label: l.name }))]}
                        />
                    </FormField>
                )}
                <fieldset className="cy-fieldset">
                    <legend className="cy-label cy-label--row"><span>Assign shift(s)</span><span className="cy-muted">{selectedIds.length}/{MAX_SHIFTS}</span></legend>
                    {shifts.map(s => {
                        const checked = selectedIds.includes(s.id);
                        const disabled = !checked && atCap;
                        return (
                            <Checkbox
                                key={s.id}
                                checked={checked}
                                disabled={disabled}
                                onCheckedChange={() => toggleShift(s.id)}
                                label={<><span className="cy-swatch" style={{ background: s.color || 'var(--gray-9)' }} aria-hidden="true" /> {s.name} ({s.code})</>}
                            />
                        );
                    })}
                    <Checkbox checked={isOff} onCheckedChange={(checked) => { if (checked) clearToOff(); }} label="Off day (clears the shift)" />
                </fieldset>
                {!leave && (
                    <a className="cy-linkbtn" data-testid="roster-request-leave" href={`/leaves-employee${cellDate ? `?date=${cellDate}` : ''}`}>On leave? Create a leave request</a>
                )}
                {finalized && <span className="cy-muted" data-testid="roster-finalized">Finalized by HR</span>}
                {onReset && canReset && (
                    <button type="button" className="cy-linkbtn" onClick={onReset} data-testid="roster-reset-to-pattern">Reset to pattern</button>
                )}
            </div>
            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={close}>Cancel</Button>
                <Button size="sm" onClick={confirm}>{warnsLeaveConflict ? 'Assign anyway' : 'Confirm'}</Button>
            </div>
        </Dialog>
    );
}
