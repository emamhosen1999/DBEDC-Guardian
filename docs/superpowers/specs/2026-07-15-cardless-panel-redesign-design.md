# Cardless UI Redesign via a Single `<Panel>` Primitive

**Date:** 2026-07-15
**Repo:** DBEDC-Guardian (erp.dhakabypass.com) — Laravel 11 / React 18 / Radix Themes
**Status:** Approved (design) — pending implementation plan

## Goal

Remove the boxed "card" look across the app (flat / cardless UI) **while keeping Radix
UI** (`@radix-ui/themes`). Structure should come from whitespace, hairline dividers, and
type scale — not from bordered/shadowed boxes.

## Current footprint (measured 2026-07-15)

- 270 `.jsx/.tsx` files; 123 reference "Card".
- **248** `<Card>` usages across the tree.
- **55** files import `Card` directly from `@radix-ui/themes`.
- 7 local card components fan out to many consumers:
  `StatsCards`, `LeaveBalanceCards`, `ProfileCard`, `PunchStatusCard`,
  `UserLocationsCard`, `StatisticCard`, `SwipeableCard`.

Because the card in use is `@radix-ui/themes` `Card`, "keeping Radix" is free — the swap
is to other primitives from the same library (`Box`, `Flex`, `Separator`, `Heading`,
`Text`). No dependency change.

## Chosen visual language — Hairline + Whitespace (hybrid)

The default ex-card surface is **flat and transparent on the page background**, with
structure carried by:

- a **1px Radix `<Separator>`** under section titles and between stacked sections,
- **spacing** (`Flex gap`, padding) and the **Radix type scale** (`Heading size`) for hierarchy.

Two opt-in exceptions for emphasis blocks (KPIs, callouts, alerts):

- **tint band** — a subtle `--gray-2` background + radius, no border/shadow.

Elevation (shadow) survives **only** on true overlays — `DropdownMenu`, `Dialog`,
`Popover`, `Tooltip`. These are out of scope and untouched.

## The `<Panel>` primitive (single source of truth)

New file: `resources/js/Components/ui/Panel.jsx`, composed only from Radix Themes.
All 248 ex-card sites route through this one component so the cardless look lives in one
tunable file.

API:

```jsx
<Panel>              // flat: transparent surface, NO shadow, NO border, padding only
  <Panel.Header>     // Heading + optional actions row, closed by a 1px <Separator>
  <Panel.Body>       // padded content region
  <Panel.Section>    // stacked sub-block; auto hairline <Separator> above when not first
```

Props:

| Prop | Effect |
|------|--------|
| `tinted` | surface sits on a `--gray-2` band + `--radius-3`; for KPI/alert emphasis |
| `divided` | inserts hairline `<Separator>` between child sections |
| pass-through | remaining props (`p`, `mt`, `style`, …) forwarded to the root `Box` |

Design intent for each sub-unit:

- **`Panel`** — root flat `Box`. What it does: renders a non-boxed surface. Depends on:
  Radix `Box`. Consumers cannot see internals; the flat vs. tinted decision is a prop.
- **`Panel.Header`** — `Flex justify-between` of a `Heading` + optional `actions`, followed
  by `<Separator size="4">`. Gives the "title then hairline" structure.
- **`Panel.Body`** — padded content region (`Box p=…`).
- **`Panel.Section`** — a labelled sub-block; renders a top `<Separator>` when it is not
  the first child, so multi-section panels read as divided zones without borders.

## Execution — bulk replacement (4 moves)

1. **Build `<Panel>`** once (this spec's primitive).
2. **Codemod the 248 sites:**
   - `<Card …>` → `<Panel …>`, `</Card>` → `</Panel>`
   - `<CardBody>` / `<Card.Body>` → `<Panel.Body>`
   - `<Card.Header>` → `<Panel.Header>`
   - Strip `Card` out of the shared `import { … } from '@radix-ui/themes'` lines (55
     files) and inject the `Panel` import.
   - **Manual review pass** — imports are mixed into shared lines, so the transform is
     verified per file rather than blind-applied.
3. **Rework the 7 local card components** to render `<Panel>` internally. Their consumers
   inherit the cardless look automatically (no per-consumer edits).
4. **Boot + screenshot sweep**, then tune the single `Panel` file until the look is right.

### Constraint — no `npm run build` for verification

`npm run build` in this repo auto-commits the whole working tree and pushes to
`origin/main`. It must NOT be used to verify frontend changes. Screenshots and visual
checks use the **dev server** (`npm run dev` / Vite) instead.

## Approval gate before the bulk sweep

Build `<Panel>` and apply it to the **Dashboard** only (highest card variety — best stress
test). Screenshot it live on the dev server, get sign-off on the look, **then** run the
codemod against the approved primitive. This is the "propose redesign first" gate; it
de-risks the other ~122 files by validating the primitive before mass application.

## Out of scope

- Overlay elevation (dropdowns/dialogs/popovers/tooltips) — unchanged.
- Any layout/data logic — this is a surface/visual migration only.
- The mobile app (`dbedc-mobile-app`) — separate codebase.

## Success criteria

- No `<Card>` from `@radix-ui/themes` remains in `resources/js` (verified by grep).
- Every ex-card surface renders flat (no border/shadow) except opt-in `tinted` blocks.
- App boots clean; Dashboard + a sampled set of pages screenshot correctly on dev server.
- The cardless look is adjustable from the single `Panel.jsx` file.
