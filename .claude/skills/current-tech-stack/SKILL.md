---
name: current-tech-stack
description: Use when adding or upgrading a dependency, calling a library/framework API, scaffolding code, or unsure whether an approach matches the installed version — to write code against the project's ACTUAL installed versions and the latest official docs, never from training-cutoff memory.
---

# Current Tech Stack (Versions & Docs)

## Overview

Library APIs and framework idioms change between versions, and the model's memory reflects its training cutoff — which may be behind or ahead of what's installed here. **Never code an API from memory. Confirm the installed version, then confirm how that version's official docs say to do it.**

Core principle: **The lockfile is the source of truth for *what's installed*; the official docs for that version are the source of truth for *how to use it*.**

This is a reference/technique skill. Use it whenever versioned behavior is in play.

## The Loop

```
1. WHICH version is installed?   → read composer.json / composer.lock / package.json
2. HOW does THIS version work?    → official docs for that major version
3. Is there something NEWER?      → check latest stable + migration/upgrade notes
4. Write code to the installed version (or propose an upgrade explicitly)
```

Never skip step 1. Never assume step 2 from memory for anything non-trivial.

## Step 1 — Find the installed version

- PHP/Laravel: [composer.json](../../../composer.json), pinned exact in `composer.lock`
- JS/React: [package.json](../../../package.json), pinned exact in `package-lock.json`
- Confirm exact resolved versions:

```bash
php artisan --version
composer show <vendor/pkg> | grep versions
npm ls <package>
```

## Step 2/3 — Get the *current* docs (don't trust memory)

Use **WebFetch/WebSearch** to read the official docs/changelog for the installed major version, and to check whether a newer stable exists:

- Search: `"<package> <major-version> docs"` and `"<package> upgrade guide"`
- Fetch the official site/changelog, not blog posts when avoidable
- For releases: `npm view <pkg> version` (latest) and `composer show -a <vendor/pkg>` (available)

If installed ≠ latest, surface the gap and the migration cost; **don't silently write to the newer API.**

## This Project's Version Gotchas

These are the traps where "what I remember" ≠ "what's installed here". Verify before using.

| Package | Installed | Watch out |
|---------|-----------|-----------|
| `laravel/framework` | ^11 | Use Laravel 11 structure (no Kernel.php, `bootstrap/app.php` config, `routes/console.php`). Not 10.x conventions. |
| `inertiajs/inertia-laravel` | **2.x-dev** | Inertia v2 (deferred props, polling, prefetch). Dev channel — verify behavior against v2 docs, not v1. |
| `@inertiajs/react` | ^2.0.0-beta | Pair with Inertia v2 server side; APIs differ from v1. |
| `react` / `react-dom` | **^18** | React 18, NOT 19. No `use()` / new APIs assumed-stable in 19. |
| `zod` | **^4** | v4 has breaking changes vs the widely-remembered v3 API. |
| `tailwind-merge` / Tailwind | v3.x merge | Confirm Tailwind major before using v4-only syntax. |
| `@tanstack/react-query` | ^5 | v5 API (object-form hooks); not v4 signatures. |
| `stancl/tenancy` | ^3 | v3 APIs and config. |
| `laravel/cashier` | ^15 | Cashier 15 + `stripe/stripe-php` ^16 — match Stripe API versions. |
| `php` | ^8.2 | 8.2+ features OK (readonly, enums, etc.); avoid 8.3/8.4-only syntax unless confirmed. |

> Mobile companion app ([[mobile-app-cross-repo]]) has its own stack (Expo/RN/Tamagui) — check ITS package.json, don't assume web versions.

## Quick Reference

| Need | Command |
|------|---------|
| Laravel version | `php artisan --version` |
| PHP package version | `composer show <vendor/pkg>` |
| Latest available (composer) | `composer show -a <vendor/pkg>` |
| JS installed version | `npm ls <pkg>` |
| Latest published (npm) | `npm view <pkg> version` |
| Read current docs | WebSearch + WebFetch official site/changelog |

## Common Mistakes

- Writing React 19 / Tailwind 4 / zod 3 / Inertia 1 patterns because they're "what I know" — when the repo has different majors installed.
- Assuming the latest docs apply when the installed version is older (or vice-versa).
- Upgrading a dep mid-task without flagging the breaking-change surface.
- Trusting blog/StackOverflow snippets over the official version-specific docs.
- Forgetting the dev migration step after a Laravel dep adds tables ([[run-migrations-on-mysql-dev-db]]).

## Pairing

Pairs with [[engineering-standards]]: that skill defines the quality bar; this one ensures the bar is met against the *real* installed versions and *current* docs.
