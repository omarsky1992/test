# Guide for developers and coding agents

Read this first. It is everything you need to change this system safely without the history of how it was built.

## What the system is

Subscriber, debt and cash management for an Iraqi FTTH internet agent (the agent resells EarthLink/ftth.iq subscriptions). Arabic, right-to-left, used on desktop and phones (installable PWA). Real money and real customer data: correctness beats speed.

- Business vocabulary and decisions: [`docs/architecture/README.md`](docs/architecture/README.md) (glossary, decisions, implementation status). Database: `02-database.md`, rules: `04-business-logic.md`, permissions: `05-permissions.md`.
- User-facing overview: [`README.md`](README.md).

## Stack

PHP 8.4 · Laravel 13 · PostgreSQL 17 · Filament 5 (the whole UI, Arabic RTL, panel at `/`) · Livewire · spatie/laravel-permission · OpenSpout (Excel/CSV).
Production: Docker Compose on a Hostinger VPS (`deploy/docker-compose.yml`: app, scheduler, db, caddy for HTTPS, backup).

## Run and test locally

```bash
composer install
cp .env.example .env && php artisan key:generate
# PostgreSQL databases: subs (dev) and subs_test (tests)
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # sample data, never on production
php artisan serve
php artisan test                          # must stay green before every push
```

The test suite (195+ tests) covers the money rules, the sync and renewal rules, WhatsApp commands and security, employees, statements, imports, reset and every screen. Add tests for anything you change.

## How a change reaches the live site

1. Commit and push to the branch the server tracks: `claude/technical-architecture-design-vzebrv` (repository `omarsky1992/test`).
2. The owner runs on the server: `bash ~/subs/deploy/update.sh` (git pull + rebuild). Migrations and the idempotent seeder run automatically on start; data is kept.

Never require manual SQL on the server. Schema changes go in new migrations (never edit an old one that has run). Data fixes that must happen once also go in a migration.

## Rules you must not break

**Money and the ledger**
- Amounts are whole Iraqi dinars in `bigint` columns. No floats, no decimals.
- Every money movement is a balanced double-entry posting through `App\Services\Ledger::post()`. `ledger_entries`, `financial_transactions` and `audit_logs` are append-only (database triggers refuse UPDATE/DELETE). Corrections are reversals (`Ledger::reverse()`), never edits or deletes.
- Document numbers come from `App\Services\Sequencer` (gapless per type and year).
- Each operation lives in a service (`app/Services`); Filament actions call services, never write money tables directly. Services log to `App\Services\Audit`.

**Debts**
- Secondary debts (الديون الثانوية) and primary debts (الديون الأولية) are separate ledger accounts. Debts are shown beside the total balance and are never added to it.
- Total balance on the dashboard = cash boxes + wallets + company balance + employees' custody. Employee advances are shown beside it, not in it.

**Company sync and renewals** (`app/Sync`, `App\Services\RenewalService`)
- The company panel (admin.ftth.iq, sign-in at sso.ftth.iq realm `Partners`) refuses connections from outside Iraq. The live source is the browser: a Chrome extension (built by `App\Sync\BrowserScripts`, downloaded from the sync page) reads the panel with the user's own session and posts to `/sync/browser/plan` and `/sync/browser/run`. Only GET requests to the panel, ever. Never store or ask for company passwords in code or chat.
- Matching: subscriber by company customer ID, account by subscription ID then device name (username). Never create duplicates.
- Current company data (plan, status, end date, device, FAT, port, zone, GPS) is overwritten from the site; name and phone are only filled when empty. Sync never touches financial history.
- Renewal rule: last known days left = 0 (aged by the saved end date) and now more than 0 on the site. Up to the short activation (setting `activation.partial_days`, 7) it creates ONE secondary debt at the plan price; more than that is a full activation recorded with no debt. Each renewal has a unique reference (account + new end date) so repeated syncs never duplicate a debt. An early renewal (before reaching 0) is not a renewal.

**WhatsApp control panel** (`app/WhatsApp`, webhook `App\Http\Controllers\WhatsAppWebhookController`)
- POST `/whatsapp/webhook` is accepted only with a valid `X-Hub-Signature-256` (HMAC of the raw body with `WHATSAPP_APP_SECRET`). The work runs after the 200 (`defer`).
- `App\WhatsApp\Inbox` order must stay: store the message by its unique WhatsApp ID (a redelivery does nothing) → check the sender in `whatsapp_numbers` (active, user active) BEFORE reading, downloading or transcribing anything → understand → execute → audit → reply. An unauthorized number only ever gets the configured refusal text; its content is not stored.
- Understanding: `RuleInterpreter` (fixed short commands, no network) first, then `ClaudeInterpreter` (Anthropic PHP SDK, structured JSON output, server-side fallbacks). `CommandExecutor` runs as the linked user (`Auth::setUser`), checks that user's permissions and calls the same services as the panel; each command is one DB transaction inside `Audit::withSource('whatsapp')`.
- A WhatsApp activation goes through `RenewalService::record` and updates `external_ends_at`/`company_days_left`, so the next sync does not count it again. The same account is not activated twice within `whatsapp.duplicate_hours` (12).
- Keys only in env: `WHATSAPP_*`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`. Tests use `tests/Support/FakeWhatsApp` and `FakeClaude`; never call the real services from tests.

**Employees** (`App\Services\EmployeeFinance`)
- Custody (عهدة) is a money account of kind `custody` per employee; cash they collect lands there. Handover moves it to a company box. Advances (سلف) are a separate receivable with repayments; an advance is never deleted. Custody and advances never mix.

**Employee interface** (`EmployeeHome`, `SubscriberCardResource`, `App\Support\SubscriberStatus`)
- Employees always get the employee interface (home, cards, «حسابي», bottom bar on phones); the admin switches with «واجهة المدير ⇄ واجهة الموظف» (`users.ui_mode`). Each user picks their own colour (`users.theme_color`, applied by `ApplyUserTheme`).
- Subscriber groups: a subscription ends at GREATEST(external_ends_at, service_ends_at); «ينتهي قريباً» uses the setting `subscribers.expiring_days`. «فعّال/منتهي وعليه دين» count primary debts only. Queries use the application clock, never SQL `now()`.
- «يجب التفعيل» (`activation_dues`, `ActivationDueService`): a secondary debt paid in full opens one (from `PaymentService::applyToDebt`); voiding the payment cancels it; it closes when the company end date moves more than 2 days past where it was (sync or WhatsApp activation) or when an employee confirms «تم التفعيل».
- Custody handover requests: the employee asks, nothing moves until someone with `custody.settle` approves (then `EmployeeFinance::handOver`).
- WhatsApp reminders open `wa.me` links from the admin's fixed `message_templates`; nothing is sent automatically.

**Permissions and safety**
- Every screen and action checks a permission (`App\Support\Permissions`); admins pass everything via `Gate::before`.
- System reset (Settings) is admin-only, needs the admin password and typing «تصفير»; it keeps users, settings, plans, payment methods and the audit log.

## Code map

| Path | What |
|------|------|
| `app/Services` | Business logic: activation, payments, debts, treasury, renewals, employee finance, reports, backup, reset |
| `app/WhatsApp` | WhatsApp control panel: webhook inbox, number registry, interpreters (rules + Claude), command executor, Cloud API gateway, transcriber |
| `app/Sync` | Company sync: mapper of the panel's JSON, browser payload, extension/bookmarklet scripts, direct client (works only from inside Iraq) |
| `app/Imports` | Excel/CSV subscriber import (mapping, preview, safe modes) |
| `app/Filament` | Screens: resources, pages, dashboard widgets, shared actions (`Actions/Operations.php`, `Actions/EmployeeActions.php`) |
| `database/migrations` | Schema, constraints, triggers |
| `deploy/` | Docker Compose, Caddy, `setup.sh` (first install), `update.sh` (updates) |
| `tests/Feature` | Tests per area |

## Conventions

- UI text in Arabic (Iraqi users); code, comments and commit messages in English.
- Match the surrounding code style; keep changes minimal and covered by tests.
- Secrets live only in `deploy/.env` on the server (never committed): APP_KEY, DB password, Google OAuth keys, WhatsApp/Anthropic/OpenAI keys. Changing APP_KEY makes stored subscriber passwords unreadable: never change it.
