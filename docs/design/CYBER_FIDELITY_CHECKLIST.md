# Cyber fidelity checklist

The target is that every Guardian screen is **pixel-identical to the live Cyber template** (https://seantheme.com/cyber/). The live site is the truth; the local kit (`scratchpad/cyber/kit`) is the implementation reference.

A page is **verified** only when every applicable row below passes, or the owner has approved an exception. Each row is checked by:
- measurement: Playwright `getComputedStyle` on both sides;
- a screenshot pair and fade overlay;
- for anything that moves, interaction frames.

Never judge by eye alone. Find mismatches before the owner does.

## Cyber-first rebuild (owner rule, 2026-10-08)
**Cyber is the only design authority.** The existing layouts, component structures, spacing, colour choices and visual patterns carry NO weight. Rebuild each page's UI from Cyber's own page composition, not by restyling what's there.
- **Template:** take the closest live Cyber page (see Method) as the structural template: grid, panel composition, page header, toolbars, filter bars, tables, forms, tabs, modals and drawers.
- **Component layer:** build a Guardian Cyber component library under `resources/js/Components/Cyber/` from the kit's React design system (`scratchpad/cyber/kit/src/design-system`): Accordion, Alert, Avatar, Badge, Breadcrumb, Button, Card (with corner arrows), Checkbox, DataTable, Dropdown, FormField, Icon, Input, InputGroup, Modal, Pagination, Progress, Radio, Range, Select, Spinner, Switch, Tabs, Textarea, ToastHost, Tooltip, plus its tokens and icon map. Pages use these. Radix Themes visual components and Heroicons are replaced; **Cyber's icon set (Bootstrap Icons) is used everywhere**.
- **Free to change:** you may reorganise sections, move filters and actions into Cyber toolbars, turn cards into tables (or the reverse), change list and table presentation, and merge or split panels, whatever Cyber's pattern for that kind of page is.
- **Must keep:** every function, data field, action, route, API call, validation rule, permission gate, empty and loading state, keyboard path and accessibility (WCAG 2.2 AA), plus mobile parity and no mock data. Removing a capability, or hiding one with no equivalent, is a defect.
- **Replace Radix state handling** (dialogs, popovers, selects) with the Cyber components' equivalents, keeping focus management and ARIA intact.

## Definition of done: owner acceptance
- **Reference standard (owner-accepted 2026-10-08):** the **Dashboard** and the **Auth pages** (login, forgot/reset password, register, verify email, change password). Every other page must reach the same level, page by page.
- **Reopened** (built in batch 1, not accepted): Notifications, Search, Errors/Forbidden, InstallApp. They are redone to the reference standard in the next batch.
- **Status flow per page:**
  1. `todo`
  2. `done`: migrated.
  3. `verified`: zero measured mismatches, frames captured.
  4. `owner-accepted`: the owner reviewed it live at http://127.0.0.1:5190 and accepted it.

  Only `owner-accepted` counts as complete. A batch is finished when its pages are accepted, not when the agent reports.
- **Page by page, at dashboard depth.** Every page gets:
  - its own live Cyber pair and a Cyber PageHeader;
  - gutter-less Cyber panels;
  - every table, form, card, tab, modal and dropdown on it converted to Cyber components and measured;
  - interaction frames for anything that moves;
  - a manifest entry the owner can review.

  A page whose look only comes from the inherited token bridge is NOT done.

## Method, per page
1. **Pair** the page with its closest Cyber page(s): dashboard ↔ `index`, lists ↔ `table_elements`/`table_plugins`, forms ↔ `form_elements`/`form_wizards`, profile ↔ `profile`, settings ↔ `settings`, calendar ↔ `calendar`, empty or error ↔ `page_404_error`, auth ↔ `page_login`/`page_register`.
2. **Screenshot** both sides at 1440×900 and 390×844 (also 1280 and 820 for layout pages), in Cyber dark and our dark. Light mode is checked for consistency with the derived palette.
3. **Measure** every component type present (sections below) and record each mismatch in `scratchpad/cyber/shots/manifest.json`.
4. **Capture interactions**: dropdowns, submenus, modals, tabs, accordions, tooltips, toasts and hover states. Take frames at 0, 100, 200 and 350 ms plus the end state, and record computed `transition`/`animation` values.
5. **Fix, re-measure, re-shoot.** Mark the ledger row `verified` only with no open major or minor mismatch.
6. **Read `scratchpad/cyber/suggestions.jsonl`** after each step and apply new owner feedback first.

## A. Layout and structure
| Check | Cyber rule (measure to confirm) |
|---|---|
| Regions | Header, sidebar and content meet edge to edge; thin border lines only. No outer margins between the navigation and the content. |
| Grid gutters | **None.** Panels butt against each other and against the navigation; separation comes from borders only. |
| Rows and heights (owner rule) | No gaps after widgets, vertically or horizontally. Cards in a row stretch to equal height, rows fill all 12 columns, and there's no trailing whitespace or empty cell. |
| Borders (owner rule) | ONE border system from tokens: Cyber's card border, header separator and inner dividers. Shared edges collapse into a single line (never doubled). No mix of solid and dashed, no varying widths, and no leftover Radix or Tailwind borders, outlines or shadows inside cards. |
| Border model (measured on Cyber index.html) | Rows: `.row.g-0.border-bottom`, so each row of cards closes with 1px solid #4d4d4d. Columns: `.border-lg-end` on every column except the last; the last column and the page have no right-edge outline. Cards: `.border-0`; the header has a bottom line of 1px rgba(255,255,255,.15), and so does the footer top. Inside an edge-to-edge card body, every section (tile grid, list, chart panels, map, table) closes with ONE 1px solid #4d4d4d line (`.row-grid.border-bottom`, `.card-body.border-bottom`); the last section has none, so no line floats inside a card. Tile and panel grids use the same solid token, with full-width row lines: an odd tile or a lone panel fills its row. No subtitle row under the header; scope and freshness go in the footer. Drill-down links are header icon tools, not outlined buttons. Check: `node scripts/design/review/border-check.cjs [data-dir] <path>` must report 0 issues. |
| Page header | On **every** page: two-tone uppercase title ("SYSTEM" strong + "ANALYTICS" light), optional subtitle or breadcrumb, a right-side slot for status chips and actions, separator line. Reusable `PageHeader`. |
| Sidebar sections | Top-level groups (Workforce, O&M, Admin…) are **section headers**: small uppercase muted labels, not clickable, like NAVIGATION / COMPONENTS / USER PORTAL. Items sit beneath with icons. Only nested groups get a caret and submenu. |
| Sidebar behaviour | Submenu slide-in `appSidebarSubMenuSlideInRight .3s cubic-bezier(.7,0,.3,1)`, caret rotation, active-route auto-expand, single vs multiple open groups, minified flyout, phone off-canvas: all as on the live site. |
| Header bar | Height, background, icon buttons, search field, notification and profile menus, separators. |
| Background | Body colour or image, overlays and textures, scrollbar styling. |
| Footer | Presence, height, typography. |

## B. Components
| Component | Check |
|---|---|
| Card / panel | Header bar (uppercase small title, letter-spacing, header tool icons), body padding, border colour, width and style, **corner decorations** (Cyber's card arrows), background, no shadow unless Cyber has one. |
| Card header tools (owner rule) | EVERY widget and panel card has Cyber's **maximize** (expand to a full-screen overlay; Esc or the same button restores) and **minimize** (collapse the body to the header) tools, with Cyber's icons, hover, transition and timing measured against the live site. **No close (×) tool, anywhere.** Built once in the shared Cyber Card: keyboard operable, `aria-expanded` / `aria-pressed` and labels, focus moves into the maximized card and returns afterwards. The minimized state is remembered per viewer in localStorage, wrapped in try/catch. |
| Buttons | Theme colours, outline variants, size scale, radius, font, uppercase usage, hover, active, focus, disabled. |
| Forms | Inputs, selects, textarea, checkbox, radio, switch, date and time pickers, file input: height, padding, background, border, radius, focus ring, placeholder colour, validation states, label and help text. |
| Tables | Header row style, row height, borders, striping, hover, selected, sort icons, pagination, empty state, density. |
| Navigation widgets | Tabs, pills, breadcrumbs, pagination, steppers. |
| Feedback | Badges, alerts, toasts, progress bars, spinners and skeletons, empty and error states. |
| Overlays | Modals (open and close animation, backdrop colour and blur, header and footer), dropdown menus (animation, item padding, dividers), tooltips, popovers. |
| Data viz | Chart palette, grid lines, axis fonts, legends, tooltips (match Cyber's ApexCharts/Chart.js theme); stat tiles and sparklines. |
| Icons | Cyber uses Bootstrap Icons. Match the set (preferred), or size, weight and colour exactly. |
| Avatars and media | Size, radius, border, placeholder. |

## C. Typography and colour tokens
Check font families, the size scale, weights, line heights, letter-spacing, uppercase rules, link colours, muted text, theme colour and its tints, border alphas, and the surface levels (page, panel, raised, overlay). All of them go through the token bridge; no hard-coded colours in components.

## D. States and motion
Check hover, focus-visible (keyboard), active, selected and disabled states on every interactive component, and transition durations and easing everywhere. Respect `prefers-reduced-motion` without changing the static look.

## E. Responsive
At 1440, 1280, 820 and 390 the layout follows Cyber's breakpoints: sidebar collapse points, header changes, panel stacking (still gutter-less), and table overflow behaviour.

## E2. No mock data (owner rule, 2026-10-08)
Copy Cyber's LOOK, never its demo CONTENT: no "John Doe", store or sales figures, or world-map metrics. Every number, name, chart, map element and list on a page comes from a real Guardian source. With no data, hide the element or show an honest empty state. Before a page is `verified`, grep it and its data sources for sample arrays, placeholder names, lorem ipsum and hardcoded KPIs.

## F. Not negotiable while matching
Behaviour, data, permission gating (`pages.jsx`, policies), accessibility (WCAG 2.2 AA contrast, labels, focus order) and performance stay intact. Where pixel identity would break one of these, record an owner-visible exception in the manifest instead of silently diverging.
