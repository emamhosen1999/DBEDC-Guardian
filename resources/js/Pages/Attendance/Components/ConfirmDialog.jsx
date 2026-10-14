import React from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button } from '@/Components/Cyber';

/**
 * Confirmation in the shared Cyber dialog (replaces the browser's confirm()). Mount it while a confirmation is pending;
 * Escape and Cancel close it without acting.
 */
export default function ConfirmDialog({ title, message, confirmLabel = 'Delete', danger = true, onConfirm, onClose }) {
    return (
        <Dialog title={title} onClose={onClose} className="cy-modal cy-modal--narrow">
            <div className="cy-modal__section"><p className="cy-modal__text">{message}</p></div>
            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                <Button color={danger ? 'danger' : 'theme'} size="sm" onClick={onConfirm}>{confirmLabel}</Button>
            </div>
        </Dialog>
    );
}
