import React from 'react';
import { describe, it, expect } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import { Theme } from '@radix-ui/themes';
import LockedFieldHint, { lockReason } from '../LockedFieldHint';

describe('lockReason', () => {
  it('explains a self-locked field', () => {
    expect(lockReason({ can: { is_self: true } }, 'department')).toBe("Managed by HR — you can't change your own department");
  });

  it('explains an out-of-rank target', () => {
    expect(lockReason({ can: { is_self: false } }, 'department')).toBe('Managed by a higher-level admin');
  });

  it('roles are always assigned by the Super Admin', () => {
    expect(lockReason({ can: { is_self: true } }, 'role')).toBe('Assigned by Super Admin');
    expect(lockReason({ can: { is_self: false } }, 'role')).toBe('Assigned by Super Admin');
  });
});

describe('LockedFieldHint', () => {
  const render = (row, field) => renderToStaticMarkup(
    <Theme><LockedFieldHint row={row} field={field}><span>Inspection</span></LockedFieldHint></Theme>,
  );

  it('keeps the read-only value and adds an accessible lock for the self row', () => {
    const html = render({ can: { is_self: true } }, 'work location');
    expect(html).toContain('Inspection');
    expect(html).toContain('data-testid="locked-field"');
    expect(html).toContain("aria-label=\"Managed by HR — you can&#x27;t change your own work location\"");
  });
});
