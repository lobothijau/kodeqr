# CLAUDE.md — kodeqr.com

Read before every session. This file exists so week-8 decisions don't re-litigate
week-3 decisions. If you disagree with a constraint, write it in BACKLOG.md and move
on — do not "improve" it in a diff.

## Stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 11, PHP 8.3 | Boilerplate: auth, billing tables, teams, settings — DO NOT rebuild these |
| Frontend | Inertia + React 18 + Tailwind | Pages in resources/js/Pages |
| Hosting | Laravel Cloud, ap-southeast-1 | Hibernation OFF on production, always |
| DB | MySQL 8 (Cloud managed) | UTC storage; WIB rendering |
| Cache/queue | Valkey (Redis) + Laravel queues | Buffer list `scans:buffer` |
| Files | Cloudflare R2 via S3 driver | Zero-egress; never serve files from app disk |
| Edge | Cloudflare free, orange-cloud | Real IP = CF-Connecting-IP |
| QR render | bacon/qr-code (server), qrcode npm (preview only) | Server is canonical |
| Geo | GeoLite2-City local mmdb | Weekly geoip:update |
| Payments | Midtrans Snap, prepaid periods | NO auto-renew, NO float money |

## Hard constraints (numbered — cite by number in reviews)

1. The `/x/{slug}` route: ≤1 Redis round-trip on warm hit, zero SQL warm, no
   session/CSRF/Inertia middleware. Never returns 5xx to a scanner.
2. Scan recording is fire-and-forget (Redis rpush) — nothing in the record path may
   delay or fail the redirect.
3. Raw IP addresses never persist. Only `sha256(date . app_key . ip)`.
4. QR images always encode `https://kodeqr.com/x/{slug}` — never a destination URL.
5. Every destination URL passes Safe Browsing on create AND edit. No exceptions,
   no admin bypass without an abuse_flags row.
6. Money is integers (rupiah). Prices live only in config/plans.php. Client never
   sends an amount.
7. Plan logic goes through the Entitlements service. Hardcoding a plan name outside
   config/plans.php fails review.
8. Codes are never silently deleted or dead-ended by billing state. Lapse →
   grace(14d) → free-tier rules. A scanner always gets a branded page.
9. scan_events is append-only, idempotent on event_uuid. Replaying a batch twice
   produces the same row count.
10. All Blade/React user-facing strings in `lang/id` (+en). No hardcoded UI strings.
11. No new npm/composer deps without a BACKLOG entry stating why. The stack above
    is complete for the planned scope.
12. Diffs stay under ~400 lines. If a task is growing past that, stop and split.

## Architecture in one paragraph

kodeqr is two applications sharing a codebase: (1) a latency-critical redirect
micro-path — Cloudflare → `/x/{slug}` → Valkey lookup → 302 or a status page —
which pushes scan payloads onto a Redis list and must survive every downstream
failure; and (2) a conventional Inertia CRUD app (builder, dashboards, billing)
that consumes that list via a per-minute batch processor (UA parse, GeoLite2,
bot-flag, uniqueness, bulk insert), rolls events into daily aggregate tables
nightly, and enforces plan entitlements from a single config. The two halves meet
only at the qr_codes row and its cache entry; invalidation is observer-driven
(`Cache::forget("qr:$slug")` on any save), which is what makes "edit destination,
re-scan printed paper, land somewhere new in seconds" — the entire product
promise — true.

## Conventions

- IDs: ULIDs for user-facing models; bigint for scan_events/link_clicks.
- Slugs: 6 chars, alphabet `23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz`.
- Statuses on qr_codes: `active|paused|grace|blocked|over_quota` — add none without
  a BACKLOG discussion; every status maps to exactly one scanner-facing page.
- Jobs: idempotent, chunked, requeue-on-failure for the scan pipeline.
- Tests: Pest; every task ships its acceptance criteria as tests where testable;
  gates cover what tests can't.
- Timezone: store UTC, group/display Asia/Jakarta; heatmaps/day-buckets must be WIB.
- Errors to scanners: branded Bahasa pages. Errors to owners: Bahasa with
  `{upgrade_to}` payload when plan-gated.
- Commits: `M1-T4: scan processor — batch insert + idempotency` (task ID prefix).

## Definition of done (per task)

- [ ] Diff < ~400 lines, single task scope, no drive-by refactors
- [ ] Acceptance criteria from the task file implemented as tests (or noted as
      gate-only with reason)
- [ ] pint + larastan + pest green locally
- [ ] No violation of constraints 1–12 (self-check against the list)
- [ ] New strings in lang files; new config in plans.php not inline
- [ ] BACKLOG.md updated with anything noticed but not built
- [ ] One-paragraph summary in the PR: what, how verified, which constraint numbers
      were most at risk
