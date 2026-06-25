---
name: engineering-standards
description: Use when writing or changing any code, designing a feature, making an architecture/security/data/API/UI decision, or reviewing work — to apply current, evolving industry-standard practices instead of outdated, templated, or training-cutoff defaults.
---

# Engineering Standards (Industry-Standard & Evolving)

## Overview

Standards are a moving target. What was "best practice" at the model's training cutoff may now be deprecated, insecure, or replaced. **Default to the current industry consensus, and when a decision is non-trivial, verify the current consensus rather than relying on memory.**

Core principle: **Write code as a senior engineer would ship it *today* — not the generic, templated, or stale version.** When unsure whether a practice is still current, treat that uncertainty as a signal to check (see [[current-tech-stack]]).

This is a flexible pattern skill. Apply judgment; do not turn it into ritual.

## When to Use

- Writing or modifying any non-trivial code
- Choosing a library, pattern, or architecture
- Anything touching **security, auth, money, PII, or data integrity**
- Designing an API, DB schema, or UI component
- Reviewing your own or others' work before claiming it's done

When NOT to use: trivial edits (typo, rename, copy tweak) where no standard is in play.

## The Standards Checklist

Run the relevant rows for the change you're making. Each row is a question to ask, not a box to blindly tick.

| Area | Ask yourself | Current bar |
|------|--------------|-------------|
| **Security** | Could this be injected, leaked, or abused? | OWASP Top 10 mindset: parameterized queries, authz on every endpoint, no secrets in code/logs, validate+sanitize all input, least privilege. |
| **Authn/Authz** | Is every action checked against *who* may do it? | Policy/Gate per action (this app uses spatie/laravel-permission). Never trust the client. |
| **Validation** | Is input validated server-side, with the right types? | Form Requests / `zod` schemas. Client validation is UX, not security. |
| **Tenancy/Data** | Could data cross a tenant or user boundary? | Respect stancl/tenancy scoping; always scope queries to the owner. |
| **Errors** | Do failures fail safe and surface usefully? | No silent catches; structured errors; no stack traces to users. |
| **Tests** | What proves this works and stays working? | Test behavior, not implementation. Cover the bug/edge you just found. |
| **Accessibility** | Can a keyboard/screen-reader user do this? | WCAG 2.2 AA: labels, focus order, contrast, semantic HTML, ARIA only when needed. |
| **Performance** | What's the cost at real scale? | Avoid N+1 (eager load), index hot columns, paginate, mind Core Web Vitals (LCP/INP/CLS). |
| **Accessibility of code** | Will the next dev understand this? | Match surrounding style; clear names; comments explain *why*, not *what*. |
| **Deprecation** | Is this API/pattern still recommended? | Prefer the framework's current idiom; avoid patterns marked legacy/deprecated. |

## The "Evolving" Discipline

Best practices drift. Guard against shipping a stale pattern:

- **Anchor to the installed version, not memory.** Before using a framework/library idiom, confirm it's current for the installed version → use [[current-tech-stack]].
- **Prefer the framework's blessed path.** Laravel/Inertia/React each have a current idiomatic way; reach for it before a hand-rolled or older approach.
- **When two patterns compete, pick the one the ecosystem is moving *toward*,** not away from (e.g. typed/validated over loose, declarative over imperative, built-in over bespoke).
- **If a "rule" you remember feels absolute, sanity-check it** — absolutes ("never use X") often have current, well-known exceptions.

## Common Mistakes

- Shipping a generic/templated solution when the codebase already has a richer established pattern — **match the codebase first.**
- Treating client-side validation as security.
- Adding authz to the UI but not the controller/endpoint.
- Copying a pattern from training memory that's been deprecated in the installed version.
- Optimizing prematurely instead of removing N+1s and adding indexes.
- Claiming "done" without running the relevant checklist rows.

## Pairing

Always pair with [[current-tech-stack]]: *this* skill says "meet the current bar"; that one says "verify what current actually is for our installed versions."
