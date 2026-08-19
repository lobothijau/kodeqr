<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

# kodeqr.com — project constitution

The plan lives in `docs/`: TASKS.md (milestone index), tasks/M0–M5.md,
REVIEW.md (the per-task review loop), BACKLOG.md (noticed, not built).

Read before every session. This file exists so week-8 decisions don't re-litigate
week-3 decisions. If you disagree with a constraint, write it in docs/BACKLOG.md and move
on — do not "improve" it in a diff.

## Stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 13, PHP 8.4 | Boilerplate: auth (Fortify, passkeys, 2FA) + settings — DO NOT rebuild. NO billing/teams: see M0-T5 |
| Frontend | Inertia + Vue 3 + Tailwind | Pages in resources/js/pages |
| Hosting | Laravel Cloud, ap-southeast-1 | Hibernation OFF on production, always |
| DB | MySQL 8 (Cloud managed) | UTC storage; WIB rendering |
| Cache/queue | Valkey (Redis) + Laravel queues | Buffer list `scans:buffer` |
| Files | Cloudflare R2 via S3 driver | Zero-egress; never serve files from app disk |
| Edge | Cloudflare free, orange-cloud | Real IP = CF-Connecting-IP |
| QR render | bacon/qr-code (server), qrcode npm (preview only) | Server is canonical |
| Geo | GeoLite2-City local mmdb | Weekly geoip:update |
| Payments | Midtrans Snap, prepaid packages (3/6/12mo) | NO auto-renew, NO grace, NO float money |

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
8. Codes are never silently deleted or dead-ended by billing state. At expiry
   every existing code keeps redirecting behind the splash, forever; the owner
   loses editing, analytics and new-code creation until they renew. No grace
   period. A scanner always gets a branded page. See documentation/billing.md.
9. scan_events is append-only, idempotent on event_uuid. Replaying a batch twice
   produces the same row count.
10. All Blade/Vue user-facing strings in `lang/id` (+en). No hardcoded UI strings.
11. No new npm/composer deps without a docs/BACKLOG.md entry stating why. The stack above
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
- Statuses on qr_codes: `active|paused|blocked|over_quota` — add none without
  a docs/BACKLOG.md discussion; every status maps to exactly one scanner-facing page.
- Jobs: idempotent, chunked, requeue-on-failure for the scan pipeline.
- Tests: Pest; every task ships its acceptance criteria as tests where testable;
  gates cover what tests can't.
- Timezone: store UTC, group/display Asia/Jakarta; heatmaps/day-buckets must be WIB.
- Errors to scanners: branded Bahasa pages. Errors to owners: Bahasa with
  `{upgrade_to}` payload when plan-gated.
- Frontend: Vue 3 SFCs in TypeScript, shadcn-vue on reka-ui, Tailwind 4. Inertia v3:
  use `Inertia::optional()` (`lazy()` is removed); axios is removed — use the built-in
  XHR client or `useHttp` for JSON endpoints like M2-T5's stats route.
- Commits: `M1-T4: scan processor — batch insert + idempotency` (task ID prefix).

## Definition of done (per task)

- [ ] Diff < ~400 lines, single task scope, no drive-by refactors
- [ ] Acceptance criteria from the task file implemented as tests (or noted as
      gate-only with reason)
- [ ] pint + larastan + pest green locally
- [ ] `dual-review` run on the diff; every finding fixed or logged (see below)
- [ ] No violation of constraints 1–12 (self-check against the list)
- [ ] New strings in lang files; new config in plans.php not inline
- [ ] docs/BACKLOG.md updated with anything noticed but not built
- [ ] One-paragraph summary in the PR: what, how verified, which constraint numbers
      were most at risk

## Review loop (mandatory, agent-run — do not wait to be asked)

Before reporting ANY task complete, invoke the `dual-review` skill on that task's
diff. It runs Claude's multi-agent `code-review` and an OpenAI Codex CLI review in
parallel and merges them into one deduplicated report. Both halves are agent-
triggerable; nothing here needs the user to type a slash command.

A task is not done until every finding is either fixed or written to
docs/BACKLOG.md with a reason for deferring. Verify findings before acting —
reviewers are wrong often enough that accepting output uncritically is its own
defect. Where a finding is environment-dependent, prove it: MySQL and SQLite
disagree on collation and enum enforcement, and production is MySQL.

Codex is the second opinion precisely because it does not share this session's
assumptions. Worked example: M0-T2 shipped a mixed-case slug on a
`utf8mb4_unicode_ci` column, so `/x/ABC12X` would have resolved the row for slug
`aBc12X` behind a byte-distinct cache key and served a stale destination forever.
Self-review missed it; the second reviewer did not.

Note: `dual-review` currently lives in `~/.claude/skills/`, not this repo, so it is
per-machine. Move it into `.claude/skills/` if anyone else works on kodeqr.
