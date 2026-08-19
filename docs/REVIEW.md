# REVIEW.md — the loop between tasks

## The loop (every task, no exceptions)

1. Claude Code session completes task → PR opened, task ID in title.
2. **Manual read first, codex second.** Read the diff top to bottom once without
   tooling. You are looking for exactly one thing: the task file's "Review focus"
   line. Everything else is secondary.
3. Run the task's acceptance criteria yourself — at least the top two — even though
   they're "covered by tests." Tests verify what they test.
4. Codex review with the prompt below. Triage its output: fix real defects, log
   style opinions to BACKLOG or discard, ignore rewrites (the prompt tells it not
   to, it will try anyway).
5. Merge. Next task may start. Never two tasks in flight.
6. At milestone end: run the GATE by hand on production infra. Gate fails → fix
   tasks re-enter the loop; no next-milestone work while a gate is red.

## Time-boxing

Review ≤ 30 min for S, ≤ 45 for M, ≤ 60 for L. Over the box → the diff was too
big; split the task retroactively rather than rubber-stamping.

## Codex review prompt (paste with the diff)

```
Review this diff for kodeqr.com, a Laravel 11 + Inertia/React dynamic-QR SaaS.
Task being implemented: [paste task ID + Scope + Review focus from the task file]

Hard constraints — flag ANY violation, cite the constraint number:
1. /x/{slug} redirect: ≤1 Redis hit warm, zero SQL warm, no web middleware, never 5xx.
2. Scan recording is fire-and-forget; nothing may delay/fail the redirect.
3. No raw IP persisted anywhere — only sha256(date.app_key.ip).
4. QR images encode kodeqr.com/x/{slug}, never destinations.
5. Safe Browsing on every destination create AND edit.
6. Money = integer rupiah; prices only in config/plans.php; client never sends amounts.
7. Plan checks only via Entitlements service; no hardcoded plan names.
8. Billing lapse: grace → free-tier rules; scanners never hit an unbranded dead end.
9. scan_events append-only + idempotent on event_uuid (batch replay = same rows).
10. UI strings in lang/id.
11. No new dependencies.

Ruled out — do NOT suggest: switching frameworks/hosting/DB; auto-renewing
subscriptions; event-sourcing/CQRS; microservices; moving redirects to
queue-consumed streams (Kafka etc.); GraphQL; replacing Inertia; TypeScript
migration; rewriting the boilerplate's auth/billing; abstraction layers for
hypothetical future providers.

Report, in order:
A. Constraint violations (number + line).
B. Correctness bugs: idempotency, race conditions, timezone (UTC vs WIB),
   cache-invalidation misses, N+1 on listed routes, authorization/IDOR.
C. The task's stated Review focus: did the diff get it right? Argue why.
D. Security: injection, mass assignment, unsigned webhook trust, IDOR.
Skip style, naming, and formatting entirely — pint owns those.
Max 10 findings, ranked by severity. If the diff is fine, say so in one line.
```

## When Claude Code and codex disagree

The task file's acceptance criteria are the tiebreak. If neither settles it,
write a 3-line BACKLOG entry and pick the option that best serves constraint 1,
8, or 9 (in that order). Do not re-open settled architecture in a PR thread.
