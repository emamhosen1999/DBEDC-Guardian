# Cardless Panel Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the boxed `@radix-ui/themes` `Card` look across DBEDC-Guardian with a flat/cardless surface, routed through a single `<Panel>` primitive, keeping Radix Themes.

**Architecture:** One new `Panel` primitive (built only from Radix `Box`/`Flex`/`Heading`/`Separator`) is the single source of truth for the cardless look. A tested pure-function codemod rewrites all 248 `<Card>` sites and their imports to `<Panel>`. The 7 local card components are reworked to render `<Panel>` internally so consumers inherit the look. Visual verification is via the Vite dev server; the codemod's string transform is unit-tested with vitest.

**Tech Stack:** React 18, `@radix-ui/themes` ^3.3.0, Vite, vitest ^3.2.3 (jsdom, logic-only — no `@testing-library`), Inertia.

## Global Constraints

- **Keep Radix.** Only `@radix-ui/themes` primitives — no new UI dependency (no HeroUI/NextUI/MUI).
- **Never run `npm run build` to verify.** It auto-commits the whole working tree and pushes to `origin/main`. Use `npm run dev` (Vite) for all visual checks.
- **Flat by default:** ex-card surfaces render with NO border and NO shadow. Emphasis blocks opt into a `--gray-2` tint band via the `tinted` prop only.
- **Overlays untouched:** `DropdownMenu`, `Dialog`, `Popover`, `Tooltip` keep their elevation — out of scope.
- **Commit author is Emam Hosen only** — never add a `Co-Authored-By: Claude` trailer. Use `--author="Emam Hosen <emamhsajeeb@gmail.com>"`.
- **Repo has no `@testing-library`.** Do not add it. Component-render assertions are done by screenshot; only pure functions get vitest tests.
- Confirm the `@/` import alias resolves to `resources/js` (it is used throughout the repo) before emitting `@/Components/ui/Panel` imports.

---

### Task 1: Build the `<Panel>` primitive

**Files:**
- Create: `resources/js/Components/ui/Panel.jsx`

**Interfaces:**
- Produces: `Panel` (default flat surface), `Panel.Header` (`{ title?, actions?, children? }`), `Panel.Body` (padded region, forwards props), `Panel.Section` (`{ title?, first?, children }`). Props on `Panel`: `tinted?: boolean`, `divided?: boolean`, plus Radix `Box` pass-through.

- [ ] **Step 1: Create the primitive**

```jsx
// resources/js/Components/ui/Panel.jsx
import React from 'react';
import { Box, Flex, Heading, Separator } from '@radix-ui/themes';

const TINT_STYLE = { background: 'var(--gray-2)', borderRadius: 'var(--radius-3)' };

function intersperseSeparators(children) {
  const arr = React.Children.toArray(children);
  return arr.flatMap((child, i) =>
    i === 0 ? [child] : [<Separator key={`panel-sep-${i}`} size="4" my="4" />, child]
  );
}

export function Panel({ tinted = false, divided = false, p, children, style, ...props }) {
  const mergedStyle = tinted ? { ...TINT_STYLE, ...style } : style;
  const kids = divided ? intersperseSeparators(children) : children;
  return (
    <Box p={tinted ? (p ?? '4') : p} style={mergedStyle} {...props}>
      {kids}
    </Box>
  );
}

function PanelHeader({ title, actions, children }) {
  return (
    <Box>
      <Flex align="center" justify="between" gap="3" mb="3">
        {title ? <Heading size="4" weight="medium">{title}</Heading> : children}
        {actions ? <Flex align="center" gap="2">{actions}</Flex> : null}
      </Flex>
      <Separator size="4" mb="4" />
    </Box>
  );
}

function PanelBody({ children, ...props }) {
  return <Box {...props}>{children}</Box>;
}

function PanelSection({ title, first = false, children }) {
  return (
    <Box>
      {!first ? <Separator size="4" my="4" /> : null}
      {title ? <Heading size="3" weight="medium" mb="2">{title}</Heading> : null}
      {children}
    </Box>
  );
}

Panel.Header = PanelHeader;
Panel.Body = PanelBody;
Panel.Section = PanelSection;

export default Panel;
```

- [ ] **Step 2: Verify it compiles under Vite**

Run: `cd /c/laragon/www/DBEDC-Guardian && npm run dev` (background), then confirm the dev server boots with no compile error mentioning `Panel.jsx`. Stop the ad-hoc check after confirming (leave dev running for Task 2).
Expected: dev server ready, no Panel-related error in output.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/ui/Panel.jsx
git commit --author="Emam Hosen <emamhsajeeb@gmail.com>" -m "feat(ui): add flat Panel primitive (cardless surface, Radix Themes)"
```

---

### Task 2: Apply `Panel` to the Dashboard — APPROVAL GATE

**Files:**
- Modify: the Dashboard page(s) under `resources/js/Pages/Dashboard` (identify exact file(s) with `grep -rl "<Card" resources/js/Pages/Dashboard`).

**Interfaces:**
- Consumes: `Panel` from Task 1.

- [ ] **Step 1: Locate dashboard card usages**

Run: `grep -rn "<Card\|CardBody\|CardHeader" resources/js/Pages/Dashboard`
Expected: a list of ex-card sites to convert.

- [ ] **Step 2: Convert the dashboard by hand**

For each `<Card>...</Card>` on the dashboard: import `Panel` (`import { Panel } from '@/Components/ui/Panel';`), replace `<Card>`→`<Panel>` (add `tinted` on KPI/emphasis blocks), `<CardBody>`→`<Panel.Body>`, and remove `Card` from the `@radix-ui/themes` import if now unused. Use a section title + `Panel.Header` where a card previously had a heading.

- [ ] **Step 3: Screenshot the live dashboard**

With `npm run dev` running, log in and open the dashboard on erp-local, capture a full-page screenshot (Playwright MCP or browser). Confirm: flat surfaces, hairline separators under section titles, tint only on KPI blocks, no leftover boxes.
Expected: a screenshot artifact to present to the Boss.

- [ ] **Step 4: STOP — present screenshot, get sign-off**

Do NOT proceed to the codemod until the Boss approves the dashboard look. If changes are requested, tune `Panel.jsx` (single file) and re-screenshot.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Dashboard
git commit --author="Emam Hosen <emamhsajeeb@gmail.com>" -m "feat(ui): cardless dashboard on Panel primitive"
```

---

### Task 3: Write the codemod (TDD pure transform)

**Files:**
- Create: `scripts/codemods/card-to-panel.mjs`
- Test: `scripts/codemods/card-to-panel.test.mjs`

**Interfaces:**
- Produces: `transformSource(source: string): string` — pure string→string transform. A separate CLI section applies it to files.

- [ ] **Step 1: Write the failing test**

```js
// scripts/codemods/card-to-panel.test.mjs
import { describe, it, expect } from 'vitest';
import { transformSource } from './card-to-panel.mjs';

describe('card-to-panel transform', () => {
  it('swaps Card tags for Panel', () => {
    const out = transformSource(`<Card><CardBody>x</CardBody></Card>`);
    expect(out).toContain('<Panel>');
    expect(out).toContain('<Panel.Body>');
    expect(out).not.toMatch(/<Card[ >]/);
  });

  it('strips Card from a shared radix import and adds Panel import', () => {
    const src = `import { Box, Card, Flex } from '@radix-ui/themes';\n<Card>a</Card>`;
    const out = transformSource(src);
    expect(out).toContain(`import { Box, Flex } from '@radix-ui/themes';`);
    expect(out).toContain(`import { Panel } from '@/Components/ui/Panel';`);
  });

  it('does not duplicate an existing Panel import', () => {
    const src = `import { Panel } from '@/Components/ui/Panel';\nimport { Card } from '@radix-ui/themes';\n<Card>a</Card>`;
    const out = transformSource(src);
    expect(out.match(/Components\/ui\/Panel/g)).toHaveLength(1);
  });

  it('removes the whole radix import line when Card was the only member', () => {
    const src = `import { Card } from '@radix-ui/themes';\n<Card>a</Card>`;
    const out = transformSource(src);
    expect(out).not.toContain('@radix-ui/themes');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/laragon/www/DBEDC-Guardian && npx vitest run scripts/codemods/card-to-panel.test.mjs`
Expected: FAIL — `transformSource` not defined / module missing.

- [ ] **Step 3: Write the transform**

```js
// scripts/codemods/card-to-panel.mjs
const PANEL_IMPORT = `import { Panel } from '@/Components/ui/Panel';`;

export function transformSource(source) {
  let src = source;
  const hadCard = /<Card[\s/>]|<Card\.|<CardBody|<CardHeader/.test(src);

  // 1) Tag swaps (open + close, self-closing, dotted, and Body/Header variants)
  src = src
    .replace(/<Card\.Body/g, '<Panel.Body').replace(/<\/Card\.Body>/g, '</Panel.Body>')
    .replace(/<Card\.Header/g, '<Panel.Header').replace(/<\/Card\.Header>/g, '</Panel.Header>')
    .replace(/<CardBody/g, '<Panel.Body').replace(/<\/CardBody>/g, '</Panel.Body>')
    .replace(/<CardHeader/g, '<Panel.Header').replace(/<\/CardHeader>/g, '</Panel.Header>')
    .replace(/<Card(\s|>|\/)/g, '<Panel$1').replace(/<\/Card>/g, '</Panel>');

  // 2) Strip `Card` from any `@radix-ui/themes` named import
  src = src.replace(
    /import\s*\{([^}]*)\}\s*from\s*(['"])@radix-ui\/themes\2;?/g,
    (full, names, q) => {
      const kept = names.split(',').map((s) => s.trim()).filter((n) => n && n !== 'Card');
      if (kept.length === 0) return ''; // drop the whole line
      return `import { ${kept.join(', ')} } from ${q}@radix-ui/themes${q};`;
    }
  );
  // collapse a blank line left by a dropped import
  src = src.replace(/^\s*\n/gm, (m, off) => (off === 0 ? m : m)); // keep simple; no-op guard

  // 3) Inject Panel import once if needed
  if (hadCard && !src.includes(`Components/ui/Panel`)) {
    src = `${PANEL_IMPORT}\n${src}`;
  }
  return src;
}

// CLI: node scripts/codemods/card-to-panel.mjs <file...>
if (process.argv[1] && process.argv[1].endsWith('card-to-panel.mjs')) {
  const fs = await import('node:fs');
  const files = process.argv.slice(2);
  for (const f of files) {
    const before = fs.readFileSync(f, 'utf8');
    const after = transformSource(before);
    if (after !== before) { fs.writeFileSync(f, after); console.log('rewrote', f); }
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run scripts/codemods/card-to-panel.test.mjs`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add scripts/codemods/card-to-panel.mjs scripts/codemods/card-to-panel.test.mjs
git commit --author="Emam Hosen <emamhsajeeb@gmail.com>" -m "test(ui): card-to-panel codemod with unit tests"
```

---

### Task 4: Run the codemod across the tree + manual review

**Files:**
- Modify: all `resources/js` files that import/use Radix `Card` (excluding the Dashboard done in Task 2 and the 7 local card components handled in Task 5).

- [ ] **Step 1: Build the target file list**

Run: `grep -rl "@radix-ui/themes" resources/js --include=*.jsx --include=*.tsx | xargs grep -l "<Card\|Card," > /tmp/card-files.txt; wc -l /tmp/card-files.txt`
Expected: ~50 files (55 minus the dashboard already converted).

- [ ] **Step 2: Apply the codemod**

Run: `node scripts/codemods/card-to-panel.mjs $(cat /tmp/card-files.txt)`
Expected: `rewrote <path>` lines for each changed file.

- [ ] **Step 3: Manual review of the diff**

Run: `git diff --stat && git diff resources/js | head -200`
Check per file: no `Card` left in a radix import; `Panel` import added exactly once; no orphaned import punctuation (`{ , Flex }`); tags balanced. Fix any file by hand.

- [ ] **Step 4: Grep assertion — no radix Card remains**

Run: `grep -rn "<Card[ />]\|Card,\|{ Card \|, Card }\|, Card," resources/js --include=*.jsx --include=*.tsx`
Expected: no matches (empty output) outside the 7 local card components (Task 5) and overlay-safe code.

- [ ] **Step 5: Boot check**

Run: `npm run dev` — confirm no compile errors; open 4–5 converted pages, confirm they render.
Expected: clean boot, pages render flat.

- [ ] **Step 6: Commit**

```bash
git add resources/js
git commit --author="Emam Hosen <emamhsajeeb@gmail.com>" -m "refactor(ui): codemod Radix Card sites to cardless Panel"
```

---

### Task 5: Rework the 7 local card components + final sweep

**Files:**
- Modify: `resources/js/Components/StatsCards.jsx`, `resources/js/Components/Leaves/LeaveBalanceCards.jsx`, `resources/js/Components/Profile/ProfileCard.jsx`, `resources/js/Components/PunchStatusCard.jsx`, `resources/js/Components/UserLocationsCard.jsx`, `resources/js/Components/*/StatisticCard.jsx`, `resources/js/Components/Common/SwipeableCard.jsx` (confirm exact paths with `grep -rl`).

- [ ] **Step 1: Convert each local card component to render `<Panel>`**

For each: replace its internal Radix `<Card>` wrapper with `<Panel>` (use `tinted` for stat/KPI ones like `StatsCards`/`StatisticCard`/`LeaveBalanceCards`; plain flat for the rest). Keep each component's public props unchanged so consumers need no edits. `SwipeableCard` keeps its swipe wrapper; only the visible surface goes flat.

- [ ] **Step 2: Full grep verification**

Run: `grep -rn "from '@radix-ui/themes'" resources/js --include=*.jsx --include=*.tsx | xargs -I{} true; grep -rn "\bCard\b" resources/js --include=*.jsx --include=*.tsx | grep -v "Panel\|SwipeableCard\|// " | head -40`
Expected: no remaining Radix `Card` references (only intended local names).

- [ ] **Step 3: Screenshot sweep**

With `npm run dev`, capture the dashboard + attendance list + leaves + profile pages. Confirm consistent flat look everywhere; tune `resources/js/Components/ui/Panel.jsx` once if spacing/tint needs adjustment.
Expected: consistent cardless UI across sampled pages.

- [ ] **Step 4: Run the full unit suite**

Run: `npx vitest run`
Expected: existing suite green (no regressions), plus the codemod tests.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components
git commit --author="Emam Hosen <emamhsajeeb@gmail.com>" -m "refactor(ui): local card components render cardless Panel"
```

---

## Self-Review

- **Spec coverage:** Panel primitive (Task 1) ✓; hairline+whitespace hybrid + tint exception (Task 1 props, applied Tasks 2/5) ✓; codemod of 248 sites / 55 imports (Tasks 3–4) ✓; 7 local components (Task 5) ✓; dashboard approval gate (Task 2) ✓; no-`npm run build` constraint (Global) ✓; overlays out of scope (Global) ✓; success-criteria grep + boot + screenshot (Tasks 4–5) ✓.
- **Placeholder scan:** no TBD/TODO; all code shown in full.
- **Type consistency:** `transformSource` used identically in Task 3 test + impl + Task 4 CLI; `Panel`/`Panel.Header`/`Panel.Body`/`Panel.Section` names consistent across Tasks 1, 2, 5.
- **Note for executor:** the `src.replace(/^\s*\n/gm, …)` blank-line guard in the codemod is intentionally a no-op placeholder for readability; leftover blank lines are cosmetic and caught in the Task 4 manual review — do not rely on it to delete lines.
