# Cyber fidelity checklist

The target is that every Guardian screen is **pixel-identical to the live Cyber template** (https://seantheme.com/cyber/). The live site is the truth; the local kit (`scratchpad/cyber/kit`) is the implementation reference.

A page is **verified** only when every applicable row below passes, or the owner has approved an exception. Each row is checked by:
- measurement: Playwright `getComputedStyle` on both sides;
- a screenshot pair and fade overlay;
- for anything that moves, interaction frames.

Never judge by eye alone. Find mismatches before the owner does.

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

## F. Not negotiable while matching
Behaviour, data, permission gating (`pages.jsx`, policies), accessibility (WCAG 2.2 AA contrast, labels, focus order) and performance stay intact. Where pixel identity would break one of these, record an owner-visible exception in the manifest instead of silently diverging.
