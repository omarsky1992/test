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

The test suite (210+ tests) covers the money rules, the sync and renewal rules, WhatsApp commands and security, employees, statements, imports, reset and every screen. Add tests for anything you change.

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
- Current company data (plan, status, end date, device, FAT, port, zone, GPS) is overwritten from the site; name and phone are only filled when empty. Sync never touches financial history: an open secondary debt is never voided or moved by a sync, even when the site shows a full activation; only a payment or a manual مناقلة changes it.
- Renewal rule: last known days left = 0 (aged by the saved end date) and now more than 0 on the site. From `activation.min_partial_days` (4) up to the short activation (`activation.partial_days`, 7) it creates ONE secondary debt at the plan price; fewer days is a test the subscriber gave themselves, judged by the longest the activation can be (`RenewalService::longestDays`: new end date minus the later of the old end date and the last sync that saw 0), so a late sync that sees a short activation with few days left still records the debt (status `test`: recorded, no debt, no subscriber message, does not block a WhatsApp activation); more is a full activation recorded with no debt. A full activation found by a sync waits in «المفعّل حديثاً» (`RecentlyActivated` widget on both home pages, `RecentActivationService`, permission `activations.recent`, columns `account_renewals.decision`/`decided_*`/`decision_debt_id`): «دين» adds one primary debt (plan price by default, editable), «مناقلة» (shown only when the account has an unpaid secondary debt, needs `transfers.create` too) moves those secondary debts to the primary debts through `DebtService::transfer`, «نقد» keeps it active with no debt; each is decided once (row lock); money already received on the account (posted payments from 3 days before the old end date, at most 10 days before the detection, `RecentActivationService::receipts`) is shown beside it with who took it, and those rows come first; renewals found before the list existed are `earlier`. Cash records no money movement. The extension also reads the company wallet log (admin.ftth.iq/wallet/transactions; its API address is learned when the user opens that page, kept in the panel's `localStorage` `subs_wallet_api`, else known guesses, at most 3 pages, GET only) and `App\Sync\WalletTransactions` labels each renewal with «نوع الدفع» Card or Wallet (`account_renewals.payment_source`, `company_txn_id`, `company_amount`), matched by subscription ID (else device name) and new end date ±1 day, BASE_PLAN only (VAS skipped); a label only, never a debt or money. The self-test rule applies only to what a sync sees (`RenewalService::record(fromSite: true)`); an employee's WhatsApp activation states its days and keeps its debt. Each renewal has a unique reference (account + new end date) so repeated syncs never duplicate a debt. An early renewal (before reaching 0) is not a renewal.

**WhatsApp control panel** (`app/WhatsApp`, webhook `App\Http\Controllers\WhatsAppWebhookController`)
- Two connections, chosen in the WhatsApp settings (`whatsapp.driver`): a phone linked by QR code through the WAHA service (default; `WahaGateway`, docker service `waha`, internal only, keys `WAHA_API_KEY`/`WAHA_WEBHOOK_SECRET` generated by `update.sh`) or Meta's Cloud API (`CloudApiGateway`). `Gateway` resolves to the chosen one.
- POST `/whatsapp/qr-webhook` (WAHA) needs `X-Webhook-Hmac` = SHA-512 HMAC of the body with `WAHA_WEBHOOK_SECRET`; `WahaInbox` drops own/group/channel messages and any sender whose number is hidden (`@lid` without a number). POST `/whatsapp/webhook` (Meta) needs a valid `X-Hub-Signature-256`. Both run the work after the 200 (`defer`).
- Everything the system sends on its own goes through `App\WhatsApp\Outbox` (table `whatsapp_outbox`, unique reference per alert/reminder), sent by `whatsapp:dispatch` every minute: a few per minute with random pauses, a daily cap, subscribers only during the day. Never call the gateway directly for bulk messages.
- `App\WhatsApp\Notifier`: staff alerts to numbers with `receives_alerts` («يجب التفعيل» when a due opens, secondary debts ending within `alerts.hours_before`), and subscriber messages (every activation, 7 or 30 days, from sync, WhatsApp or the panel; expiring; expired; debt) only when `subscriber_messages.enabled` and each event's switch are on; texts come from `message_templates.auto_event`. Expiring/expired are never sent after the short activation (`Notifier::shortActivation`: latest activation start to end ≤ `activation.partial_days` + 1). `whatsapp:scan` runs every 15 minutes.
- Optional second phone for the subscribers (`whatsapp.notify_separate`, docker service `waha-notify`, `WahaGateway::line('notify')`, no webhook): subscriber kinds (`sub_*`, `reminder`) go only through it and wait while it is unlinked; the first phone keeps commands and staff alerts. Each line has its own batch and daily cap.
- `App\WhatsApp\Inbox` order must stay: store the message by its unique WhatsApp ID (a redelivery does nothing) → check the sender in `whatsapp_numbers` (active, user active) BEFORE reading, downloading or transcribing anything → understand → execute → audit → reply. An unauthorized number only ever gets the configured refusal text; its content is not stored.
- Understanding: the admin's phrasings (`CustomPatterns`, table `command_patterns`, words plus blanks {الاسم} {الأيام} {المبلغ} {المادة}, each blank takes as few words as give a valid value) first, then `RuleInterpreter` (fixed short commands, no network), then `ClaudeInterpreter` (Anthropic PHP SDK, structured JSON output, server-side fallbacks). `CommandExecutor` runs as the linked user (`Auth::setUser`), checks that user's permissions and calls the same services as the panel; each command is one DB transaction inside `Audit::withSource('whatsapp')`.
- A primary debt by WhatsApp is a 30-day activation on credit: when the subscription is not running (or the message says «تفعيل … دين اولي»), it also records a full activation (no secondary debt), so the subscriber is «فعّال وعليه دين». Without an amount the plan price is owed; ≤ 7 days with a primary debt is refused.
- A WhatsApp activation goes through `RenewalService::record` and updates `external_ends_at`/`company_days_left`, so the next sync does not count it again. The same account is not activated twice within `whatsapp.duplicate_hours` (12).
- Voice notes: `Transcriber` is the free `LocalWhisperTranscriber` (docker service `whisper`, internal) unless `OPENAI_API_KEY` is set (`HttpTranscriber`).
- Keys only in env: `WAHA_*`, `WHATSAPP_*`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`. Tests use `tests/Support/FakeWhatsApp` and `FakeClaude`; never call the real services from tests.

**Employees** (`App\Services\EmployeeFinance`)
- Custody (عهدة) is a money account of kind `custody` per employee; cash they collect lands there. Handover moves it to a company box. Advances (سلف) are a separate receivable with repayments; an advance is never deleted. Custody and advances never mix. A purchase paid from a custody is always recorded (a short custody goes below zero: the company owes the employee), lowers it and is counted in the purchases like any expense; the employee's summary (`purchases`), statement («مشتريات من العهدة») and the employee reports show it, and the expense form pays from the user's custody by default when they collect to custody.

**Employee interface** (`EmployeeHome`, `SubscriberCardResource`, `App\Support\SubscriberStatus`)
- Employees always get the employee interface (home, cards, «حسابي», bottom bar on phones); the admin switches with «واجهة المدير ⇄ واجهة الموظف» (`users.ui_mode`). Each user picks their own colour (`users.theme_color`, applied by `ApplyUserTheme`).
- Subscriber groups: a subscription ends at GREATEST(external_ends_at, service_ends_at); «ينتهي قريباً» uses the setting `subscribers.expiring_days`. «فعّال/منتهي وعليه دين» count primary debts only. The secondary debts open the same cards (tabs `secondary`, `active_secondary`, `expired_secondary`, with pay and مناقلة on each card) from the employee home and, in the employee interface, from the menu. Queries use the application clock, never SQL `now()`.
- «يجب التفعيل» (`activation_dues`, `ActivationDueService`): a secondary debt paid in full opens one (from `PaymentService::applyToDebt`); voiding the payment cancels it; it closes when the company end date moves more than 2 days past where it was (sync or WhatsApp activation) or when an employee confirms «تم التفعيل».
- Custody handover requests: the employee asks, nothing moves until someone with `custody.settle` approves (then `EmployeeFinance::handOver`).
- WhatsApp reminders open `wa.me` links from the admin's fixed `message_templates`; nothing is sent automatically.

**Notes, blacklist, payment messages, bulk messages**
- «وضع المشترك» (`subscriber_notes`, `SubscriberNoteService`): add/close/delete each check their permission (`notes.*`) in the service. Closing keeps the note (status `closed`); deleting is a soft delete with `deleted_by`. Names with active notes are marked, and their whole row or card shaded (`SubscriberFlags::rowClass` → CSS `subs-has-note` / `subs-note-box`, add `->recordClasses(...)` to every new table that lists subscribers), through `App\Support\SubscriberFlags` (one query per request, `forget()` after every change); the notes tab is `?relation=notes` on the subscriber page; the home widget is `SubscribersWithNotes`. A note can carry a WhatsApp reminder («ذكّرني»: after an hour/days or a chosen time, to the chosen authorized numbers (several allowed) or all of them when none is chosen, `subscriber_notes.remind_at`/`remind_number_ids` (jsonb)/`reminded_at`): `Notifier::noteReminders` (run every minute by `whatsapp:dispatch`) queues it once per number (kind `alert_note`, main phone, any hour, reference `note:remind:{note}:{number}`), never for a closed or deleted note.
- «القائمة السوداء» (`blacklist_entries`, `BlacklistService`, permission `blacklist.manage`): by subscriber id, one current entry per subscriber (partial unique index), removal keeps the row. It never blocks or changes anything. Shown on both home pages (`BlacklistedSubscribers` widget).
- Notes, the blacklist and messages never touch money: no debt is voided, zeroed, moved or marked paid by them.
- After a payment, `PaymentService::record` queues «تم استلام دفعتك» with `DB::afterCommit` (`Notifier::payment`, kind `sub_payment`, reference `sub:payment:{id}`, `whatsapp_outbox.payment_id`), only with a phone and a configured connection (`Notifier::canSend`). A failed send never touches the payment.
- Bulk messages (`WhatsappBroadcast` page, `App\WhatsApp\BroadcastService`, permission `whatsapp.broadcast`): only the chosen accounts, one outbox row per phone (kind `sub_broadcast`, `broadcast_id`, optional `media_path`). `Gateway::supportsImages()`: Meta yes, WAHA only with `WAHA_PLUS=true`; otherwise the outbox sends the text with a signed link (`whatsapp.broadcast.image`).
- Payment methods: Qi Card and Mastercard are separate methods; the transaction number is optional (`requires_reference` false unless the admin turns it on) and is shown on the receipt and in the payments list.

**Internal transfers, cards, periods, card stock, house locations**
- Transfers between employees (`InternalTransfers::betweenEmployees`, custody → custody) and between cards (`betweenCards`, electronic → electronic) are `fund_transfers` with `kind` custody/card and an `idempotency_key`; they lock both boxes, refuse an uncovered amount, are never income or expense, and are voided only by `Ledger::reverse()` (`TreasuryService::voidTransfer`).
- «تصفير رصيد البطاقة» (`TreasuryService::resetBalance`, table `money_account_resets`) posts one adjustment against the system account `CARD_ADJUSTMENTS`; old movements stay, the reset is reversible. «رصيد البطاقات» (`CardBalances` widget on both home pages, `cards.view`) shows the total on all cards with «تصفير رصيد البطاقات» (`InternalTransfers::resetAllCards`, one reset per non-zero card, idempotent per request) and «تصفير جزء من رصيد البطاقة» (`InternalTransfers::resetCardPart`: money spent from one card, a reset of just that amount, never more than the balance, idempotent, reversible).
- Accounting periods (`AccountingPeriods`, table `period_closings`): purchases = expenses, sales = device sales only (devices, materials, cards; never internet activations), «الربح والخسارة» = sales − purchases (signed), the same on the dashboard (`ReportService::dashboard` `profit`, `PeriodStats`). Company top-ups and activations are reported apart. Closing («تصفير المبيعات/المشتريات», on the periods page and the dashboard) stores a snapshot per scope (purchases/sales, separately or together) up to the exact moment (`starts_at`/`ends_at`, end excluded); it deletes nothing and moves no balance. The open period starts at the last closing's `ends_at` or at `periods.{scope}_start`. Dashboard filter «منذ آخر تصفير» counts from there. On the dashboard the sales, purchases and «الربح والخسارة» tiles (`PeriodStats`) are always running totals since the last «تصفير» of each scope (`AccountingPeriods::lastReset`, from the first record when never reset), whatever the period filter; only «المقبوضات» follows the filter.
- «المبيعات الثانوية» (`SecondarySaleService`, table `secondary_sales`, `SecondarySaleResource`, `SecondarySalesStat` on the dashboard, permissions `secondary_sales.*`): a log counted alone; it posts nothing to the ledger and never touches a box, debt, the sales, the purchases or the profit. Numbers `SS-…`, idempotency key per click, void (never delete), running total since «تصفير المبيعات الثانوية» (setting `secondary_sales.reset_at`, audit keeps the total).
- Card stock (`VoucherStock`, table `voucher_cards`): codes normalized and unique, available → activated once (conditional update), or void. Inventory only. «صرف الكروت» (`VoucherDispense` page, `VoucherStock::dispense`, permission `stock.dispense`): one button per existing type+denomination; takes one available card of exactly that category with `FOR UPDATE SKIP LOCKED` and marks it activated (shown as «مصروف»), never from another category; `dispense_key` (one uuid per click) makes a repeated click return the same card. Log: `DispensedVoucherResource` (`stock.history`).
- House location (`LocationService`, table `subscriber_locations`, page `SubscriberLocationPage`): coordinates from the phone's GPS or a point on the map, never guessed; replacing needs confirmation and `locations.update`; the photo is optional, on the private disk, served only by `subscribers.house-photo` to `photos.view`. Leaflet is self-hosted in `public/vendor/leaflet` (BSD-2), tiles from OpenStreetMap; the map component is `filament.interface.location-map`.

**Permissions and safety**
- Every screen and action checks a permission (`App\Support\Permissions`, grouped in `Permissions::GROUPS`); admins pass everything via `Gate::before`.
- Per employee: role permissions + direct grants − `users.denied_permissions` (`User::hasPermissionTo`), set from «إدارة صلاحيات الموظفين» (`PermissionService::apply`). Nobody edits their own permissions, only the admin assigns roles, and a non-admin manager cannot grant what they do not hold. Services re-check permissions (AuthorizationException), not only the buttons.
- `deploy/update.sh` dumps the database to `deploy/backups/pre-update/` before pulling (keeps the last 10) and stops if the dump fails.
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
