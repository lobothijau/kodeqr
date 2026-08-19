# Billing model

How kodeqr sells access and what happens when access ends. This is product
behaviour, not build sequencing — the task breakdown lives in the (untracked)
`docs/` planning set.

Settled 2026-08-19. Constraint 8 in `CLAUDE.md` is the one-line summary; this file
is the reasoning behind it.

## Packages, not subscriptions

kodeqr sells **prepaid packages**. There is no auto-renewal, no stored card, and no
recurring mandate — Midtrans Snap takes a one-off payment and the package runs for a
fixed duration.

Durations sold: **3, 6 and 12 months**. Monthly was deliberately dropped: without
auto-renew it means twelve payment events a year and twelve chances to forget, and
churn-by-forgetting is the dominant failure mode for prepaid billing.

Tiers remain Regular, Plus and Business. Free is not purchasable — it is the state of
having never paid.

Prices live only in `config/plans.php`, as integer rupiah, one entry per
(tier × duration). The client never sends an amount; the server computes it from the
tier and duration identifiers the client submits. See constraints 6 and 7.

### Launch prices

Confirmed 2026-08-19. This table records the decision and its date; once M0-T3 lands,
`config/plans.php` is the runtime authority and this is a description of it, not a
second source. Change prices there, then update this table in the same commit.

| Tier | 3 months | 6 months | 12 months |
|---|---|---|---|
| Regular | Rp 149.000 | Rp 269.000 | Rp 490.000 |
| Plus | Rp 449.000 | Rp 799.000 | Rp 1.490.000 |
| Business | Rp 1.349.000 | Rp 2.449.000 | Rp 4.490.000 |

The curve is roughly ×3 / ×5.5 / ×10 against a notional monthly rate, so longer
commitment visibly pays: buying twelve months costs about two months less than four
consecutive three-month packages. Monthly is not sold — without auto-renewal it means
twelve payment events a year and twelve chances to forget, and churn-by-forgetting is
the dominant failure mode for prepaid billing.

## Buying again

Buying while a package is still running **extends** it: `ends_at = max(now, ends_at)
+ duration`. Top-ups stack, they never overwrite. `Subscription::extend()` is the only
place this formula exists.

Buying a **higher tier** mid-package upgrades immediately and extends by the new
duration. The remaining days of the old tier are upgraded for free rather than
prorated — this avoids fractional-rupiah maths entirely, which constraint 6 forbids,
and errs in the customer's favour.

There is no downgrade path mid-package. A cheaper tier purchased later simply takes
effect when bought, extending from the same `ends_at`.

## Expiry: no grace period

When `ends_at` passes, it passes. There is no grace window and no hidden buffer for
late payments — expiry is exact, and the system has no state that is not visible.

What expiry does **not** do is break anything a scanner touches. This is the promise
constraint 8 actually protects, and it survives intact:

| | Codes redirect | Edit destination | Analytics | Create new codes |
|---|---|---|---|---|
| **Paid** | yes, direct 302 | yes | full, per tier | up to tier limit |
| **Free** (never paid) | yes, with splash | yes | 7 days | 3 |
| **Lapsed** (paid before) | yes, with splash | **no** | **none** | **no** |

A lapsed account's codes keep redirecting **forever**. Every printed sticker, menu and
flyer keeps working. What stops is everything the customer was actually paying for:
changing where a code points, and measuring what it does.

### Why this shape

Dynamic QR *is* the edit — "change the destination, re-scan the same paper, land
somewhere new" is the entire product. Freezing edits is therefore the honest renewal
lever: customers keep what they printed, and pay to change it. A menu changes
seasonally and an event needs new codes, so the pressure is real and recurring.

Locking analytics alone would be too weak — many owners never open the dashboard and
would notice nothing.

Pausing codes outright would be worse than weak. A restaurant's menu QR dying
overnight is unrecoverable reputationally, and it is the fastest way to convert a
lapsed customer into a public critic.

### Consequences accepted

A lapsed account keeps more codes redirecting than a genuine free account (which is
capped at 3), and its codes have no 500-scan cap. This asymmetry is deliberate: the
two states sell different things, and free accounts retain editing and analytics,
which lapsed accounts do not. Capping or pausing lapsed codes would mean killing
printed paper, which is the one thing the model refuses to do.

Serving a lapsed redirect is close to free — a Valkey hit and a 302, with no scan
event written, no aggregate rows and no storage growth.

### Payment latency

Because expiry is exact, a payment settling minutes after `ends_at` briefly degrades
live codes. Under this model that means the splash appears and tracking pauses for a
few hours — not that anything breaks — so the cost was judged acceptable against
having no hidden state to reason about.

## Reminders are the renewal mechanism

With neither auto-renew nor grace, the expiry email is the entire safety net. Reminders
at T-14, T-7, T-1 and at expiry are load-bearing infrastructure, not a marketing
nice-to-have, and belong in M3 rather than with the weekly reports in M4.

The expiry email must say plainly that codes keep working, that editing and analytics
have stopped, and that data collection is paused — otherwise the first support ticket
is "did my QR codes die?"

## Plan resolution

`User::currentPlan()` is the single place the answer is decided:

- no subscription row → `Plan::Free`
- row, `now < ends_at` → the row's tier
- row, `now >= ends_at` → `Plan::Lapsed`

Derived from dates rather than the stored `status` column, so a lapse or a renewal is
exact the instant it happens rather than at the next cron run. The `status` column is
materialized for querying and reminder emails.

Absence of a row is the only representation of the free tier. A `plan = 'free'` row
would be a second representation and is rejected by the database enum.
