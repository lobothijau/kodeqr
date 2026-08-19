# kodeqr.com — Master Task Index

Solo build. Laravel 11 + Inertia + React on Laravel Cloud (ap-southeast-1). Boilerplate
covers auth, billing plumbing, teams, settings. Everything below is kodeqr-specific.

**Workflow per task:** Claude Code session → diff < ~400 lines → manual read → codex
review (prompt in `REVIEW.md`) → merge. No task starts before the previous one merges.
Anything noticed mid-task goes in `BACKLOG.md`, not into the diff.

**Milestone gates are hard stops.** Run the gate checklist by hand against real
infrastructure (deployed env, real phone camera, real Midtrans sandbox). A green test
suite does not pass a gate.

---

## Milestone map

| Milestone | File | Week | Theme | Tasks | Gate |
|---|---|---|---|---|---|
| M0 | tasks/M0.md | Day 0–1 | Foundations: env, DNS, CI, schema | 4 | G0 smoke |
| M1 | tasks/M1.md | Week 1a | **Redirect + scan pipeline (the product's spine)** | 8 | **G1 adversarial** |
| M2 | tasks/M2.md | Week 1b | QR builder, styling, per-code dashboard | 6 | G2 |
| M3 | tasks/M3.md | Week 2 | Plans, Midtrans, grace, file/vCard QR, Bahasa | 7 | G3 money |
| M4 | tasks/M4.md | Week 3 | B2B: linkpage, bulk, advanced analytics, abuse autonomy | 6 | G4 |
| M5 | tasks/M5.md | Week 4 | Launch: API, aggregates perf, pruning, SEO pages, invoices | 6 | G5 launch |

M1 is deliberately the most detailed file. It is the component where a bug destroys
trust permanently (wrong/dead redirects, poisoned domain reputation). M2/M5 contain
terse CRUD tasks on purpose — do not expand them.

---

## Deferred — DO NOT BUILD (written down so it stops occupying attention)

| Item | Revisit trigger |
|---|---|
| Custom domains (Plus incl. 1, "coming soon" on pricing) | First agency asks, or week 5 |
| Teams > seat counts already in boilerplate | Business-tier demand |
| PSE Komdigi registration | Loud marketing push or real payment volume |
| Cloudflare Workers edge redirect | Sustained > ~40 req/s peaks or Cloud bill > Rp 4jt/mo ×2 months |
| VPS migration | Same trigger as above; never before Workers evaluation |
| QRIS payment-QR generation | Never (PJP licensing) — /scan SEO page covers the intent |
| Auto-renewing card subscriptions | Prepaid periods first; revisit at churn data |
| Geofencing, retargeting pixels, GA4 integration, Zapier | Post-launch, pull not push |
| Native mobile app | No |
| Scan-notification push/webhooks per code | Post-launch |
| kodeqr browser scanner PWA offline mode | Post-launch; the web /scan page (M5-T4) is enough |

## Cut list — drop in this order when behind schedule

1. M5-T4 /scan + per-type SEO generator pages (launch with landing page only)
2. M4-T5 weekly email reports
3. M4-T2 multi-link landing page type
4. M4-T4 advanced-analytics heatmap + comparison (ship Plus with CSV export only)
5. M4-T3 bulk CSV creation
6. M5-T2 public API (+docs)
7. M3-T6 vCard hosted page (keep file/PDF QR — the F&B menu case stays)
8. Free-tier interstitial polish → plain 2.5s splash, no design pass

**Never cut, under any schedule pressure:**
- **A) M1 in full, including Safe Browsing on create AND edit, status pages, and the G1
  adversarial gate.** Domain reputation and printed-code reliability are unrecoverable.
- **B) M3-T1..T3 payment correctness + grace-not-guillotine.** Broken entitlements or
  silently dead paid codes are the two fastest ways to convert a customer into a critic.

## Standing config decisions (argue in BACKLOG, not in tasks)

- Redirect path: `kodeqr.com/x/{slug}`, slug = 6-char base62, alphabet excludes `0OlI1`.
- Free tier: 3 dynamic codes, 500 scans/code, 7-day analytics, splash interstitial,
  PNG export only. Paid: Regular 10 codes / Plus 100 / Business 500, unlimited scans,
  12-mo analytics (Plus adds depth + CSV; Business unlimited retention).
- Prices: 49k / 149k / 449k monthly; ×10 for annual (2 months free).
- Raw scan retention: free 7d, Regular/Plus 365d, Business ∞. Aggregates kept forever.
- IP is never stored raw. `ip_hash = sha256(date('Y-m-d') . config('app.key') . $ip)`.
- All timestamps UTC in DB; render Asia/Jakarta.
