import React, { useEffect, useRef, useState } from 'react';

import Icon from '../Icon.jsx';
import LayerList from './LayerList.jsx';

/** Header popover: "LAYERS 12/16" opens the grouped on/off list. Escape and outside click close it. */
export default function LayerMenu({ layers, groups, hidden, onToggle, onSetAll }) {
    const [open, setOpen] = useState(false);
    const root = useRef(null);
    useEffect(() => {
        if (!open) return undefined;
        const onDoc = (e) => { if (root.current && !root.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
    }, [open]);
    const on = layers.filter((l) => !hidden.has(l.key)).length;
    return (
        <div className="cy-layermenu" ref={root}>
            <button type="button" className="cy-layermenu__btn" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen((o) => !o)}>
                <Icon name="layers" /> Layers {on}/{layers.length}
            </button>
            {open && (
                <div className="cy-layermenu__panel" role="group" aria-label="Map layers">
                    <LayerList layers={layers} groups={groups} hidden={hidden} onToggle={onToggle} onSetAll={onSetAll} />
                </div>
            )}
        </div>
    );
}
