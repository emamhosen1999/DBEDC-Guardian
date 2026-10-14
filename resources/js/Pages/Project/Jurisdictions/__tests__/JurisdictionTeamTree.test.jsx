// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

import { CorridorTree, JurisdictionTeamTree } from '../JurisdictionTeamTree.jsx';

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });
const mount = (ui) => { host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host); act(() => root.render(ui)); };

const person = (id, name, position, extra = {}) => ({
    employee_id: id, name, designation: 'Supervision Engineer', department: 'Quality Control', photo: null, position, assigned_role: null, works: 4,
    attendance: { status: 'present', label: 'Present today' }, contact: null, warnings: [], children: [], ...extra,
});
const j = {
    id: 1, code: 'PH-1', name: 'Phase One', colour_token: 'blue', start_m: 0, end_m: 27999, length_m: 28000,
    team: [person('127', 'Md. Habibur Rahman', 'in_charge', { children: [
        person('1', 'Direct Report', 'reporting', { attendance: { status: 'on_leave', label: 'On leave' }, children: [person('2', 'Nested Report', 'reporting')] }),
        person('3', 'Surveyor on loan', 'assigned', { assigned_role: 'member', department: 'Survey', contact: { phone: '+880', email: 'a@b.test' } }),
    ] })],
};

describe('team tree', () => {
    it('shows designation, department, position, works and attendance for every person, nested by reporting line', () => {
        mount(<JurisdictionTeamTree jurisdiction={j} periodLabel="Last 30 days" />);
        const items = host.querySelectorAll('[role="treeitem"]');
        expect(items).toHaveLength(4);
        expect(items[0].getAttribute('aria-level')).toBe('1');
        const text = host.textContent;
        expect(text).toContain('Supervision Engineer, Quality Control');
        expect(text).toContain('In-charge');
        expect(text).toContain('Reports to the in-charge');
        expect(text).toContain('Assigned to the jurisdiction');
        expect(text).toContain('On leave');
        expect(text).toContain('Present today');
        expect(text).toContain('4 works');
        expect(host.querySelector('[aria-level="3"]').textContent).toContain('Nested Report');
    });

    it('shows contact details only when the server sent them', () => {
        mount(<JurisdictionTeamTree jurisdiction={j} periodLabel="x" />);
        expect(host.textContent).toContain('a@b.test');
        expect(host.textContent.match(/@/g)).toHaveLength(1);
    });

    it('is honest when there is no team', () => {
        mount(<JurisdictionTeamTree jurisdiction={{ ...j, team: [] }} periodLabel="x" />);
        expect(host.textContent).toContain('No team is recorded');
    });

    it('lists the whole corridor as Corridor, jurisdictions, teams', () => {
        mount(<CorridorTree jurisdictions={[j]} coverage={{ coverage_pct: 100 }} periodLabel="x" onSelect={() => {}} />);
        expect(host.querySelector('[role="tree"]').getAttribute('aria-label')).toContain('Corridor');
        expect(host.textContent).toContain('PH-1 Phase One');
        expect(host.querySelectorAll('[role="treeitem"]').length).toBeGreaterThan(4);
    });
});
