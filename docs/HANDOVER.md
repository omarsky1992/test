# تسليم المشروع لأي أداة ذكاء اصطناعي

هذا الملف يخليك تكمّل تطوير النظام بأي أداة ذكاء اصطناعي (ChatGPT/Codex، Gemini، Cursor، Copilot، أو غيرها) إذا انتهت باقة Claude.

## كيف تستخدمه (لصاحب النظام)

1. انطِ الأداة رابط المستودع `omarsky1992/test`، وخليها تشتغل على الفرع `claude/technical-architecture-design-vzebrv`. هذا الفرع هو اللي يسحبه السيرفر.
2. انسخ لها «الرسالة الأولى» اللي تحت.
3. بعد كل تعديل تدفعه الأداة، حدّث السيرفر بنفس الأمر:
   ```
   bash ~/subs/deploy/update.sh
   ```
   الأمر ياخذ نسخة احتياطية من القاعدة قبل التحديث.
4. إذا صار خلل بعد تحديث، اطلب منها ترجع للكومت اللي قبله (`git revert`). لا تسمح لها تمسح بيانات أو تعدّل القاعدة يدوياً.

**لا تنطي أي أداة:**
- ملف `deploy/.env` (فيه كل المفاتيح والباسوردات).
- باسورد موقع الشركة أو باسورد السيرفر.
- بيانات مشتركين حقيقية.

## الرسالة الأولى (انسخها كما هي)

```
أنت تكمل تطوير نظام إدارة مشتركين وديون وصناديق لوكيل إنترنت FTTH في العراق
(Laravel 13 + Filament 5 + PostgreSQL 17، واجهة عربية RTL، فلوس حقيقية وبيانات زبائن حقيقية).

قبل أي شي:
1. اقرأ AGENTS.md كامل، وبعده docs/HANDOVER.md (قسم For the AI). هذني المرجع الملزم.
2. اشتغل فقط على الفرع claude/technical-architecture-design-vzebrv في المستودع omarsky1992/test.
3. كل تعديل لازم يجي ويا اختبار، والاختبارات كلها لازم تنجح قبل أي push.
4. حدّث AGENTS.md بكل ميزة جديدة أو قاعدة تتغير.
5. لا تغيّر أي قاعدة من «Rules you must not break» بدون ما تسألني.
6. رد عليّ باللهجة العراقية، والكود والتعليقات ورسائل الكومت بالإنجليزي.

طلبي الأول: <اكتب طلبك هنا>
```

---

## For the AI

You are taking over a production system that is already live and used every day. **`AGENTS.md` is the source of truth**: rules, architecture and every feature, with class and table names. This section adds how the work has been done, so you can continue in the same way.

### Snapshot (2026-11)

- Repo `omarsky1992/test`, branch `claude/technical-architecture-design-vzebrv` (the server pulls it). Main migrations in `database/migrations` (latest `2026_11_03_000430_…`), platform migrations in `database/migrations/platform`.
- About 370 feature tests in `tests/Feature`, all green except `BackupTest` (5 tests) where `pg_dump` is missing.
- Rollback point before the multi-tenant platform work: commit `048084d`.
- Deeper design docs (Arabic): `docs/architecture/*.md` (glossary, decisions, database, business rules, permissions, screens, integration). Deployment: `docs/deployment.md`. WhatsApp setup: `docs/whatsapp.md`.

### Workflow the owner expects

1. Read the relevant part of `AGENTS.md` and the code before changing anything; inspect first, then change.
2. Make the smallest change that does the job, in the existing style (services in `app/Services`, Filament screens call services, Arabic UI text).
3. Add or extend a test in `tests/Feature` for every change.
4. Run `php artisan test` (the whole suite) and `vendor/bin/pint` on changed files. Push only when green (BackupTest excepted, and only for the missing `pg_dump` reason).
5. Update `AGENTS.md` (one precise bullet per feature: names of classes, tables, settings, permissions).
6. Commit with a clear English message and push to the branch above. Do not open pull requests unless asked.
7. Reply to the owner in Iraqi Arabic, short: what changed, how to use it, the update command, anything they must do once on the server (e.g. reinstall the Chrome extension, run a command).

### Running the tests

- Needs PHP 8.4 (with pgsql, pdo_pgsql, gd, intl, bcmath) and PostgreSQL 17 with the pg_trgm extension available.
- Databases: `subs_test` and `subs_test_platform` (see `phpunit.xml`); the platform tests build `subs_test_home` / `subs_test_second` themselves (`tests/Support/BuildsClientDatabases`).
- If the machine only has an older PHP, run inside a container, e.g. `docker run --rm --network host -v "$PWD":/usr/src/app -w /usr/src/app thecodingmachine/php:8.4-v5-cli php artisan test` with the pgsql/gd/intl/bcmath extensions enabled through its `PHP_EXTENSION_*` environment variables.
- GitHub Actions (`.github/workflows/tests.yml`, PHP 8.4 + PostgreSQL 17) runs the suite on every push: check it after pushing.
- Never call real outside services from tests: WhatsApp and Claude use `tests/Support/FakeWhatsApp` and `FakeClaude`.

### Hard limits (never cross them, even if asked casually; ask the owner first)

- **Money:** whole dinars in `bigint`; every movement through `Ledger::post()`; `ledger_entries`, `financial_transactions`, `audit_logs` are append-only; corrections are reversals, never edits or deletes; document numbers from `Sequencer`.
- **Schema:** changes only in new migrations. Never edit a migration that has run, never require manual SQL on the server. No destructive data migrations. Client 1's data (the original database) is never rewritten for the platform.
- **Company site (admin.ftth.iq):** only GET requests from the user's own browser session through the extension.
  - Never store, request or use the company password or any client secret.
  - Never log in automatically.
  - Never help get around the company's block on servers outside Iraq (no proxies, no disguised browsers).
- **Secrets:** only in `deploy/.env` on the server. Never in code, commits or chat. Never change `APP_KEY` (stored subscriber passwords would become unreadable).
- **WhatsApp:**
  - The sender is checked against the authorized numbers before anything in the message is read.
  - Everything sent automatically goes through `Outbox` (rate limits, daily cap, daytime only for subscribers).
- **Production:**
  - Never run `docker compose down -v` (it deletes the database volume).
  - Don't change the domain, hosting or server configuration without the owner's approval.
- **Data:** no real subscriber data in commits or tests (fake names and numbers only).

### How the owner talks

Requests come in Iraqi Arabic, often short or by voice-to-text. Restate what you understood when it is ambiguous, otherwise act. Business words are defined in the glossary in `docs/architecture/README.md`. For example:

| Arabic | Meaning |
|------|---------|
| دين ثانوي | Secondary debt: a 7-day self-activation, owed at the plan price |
| دين أولي | Primary debt |
| مناقلة | Moving secondary debts to primary |
| عهدة | An employee's custody box |
| سلفة | Employee advance |
| تصفير | Reset or period close; never a delete |
| المفعّل حديثاً | Full activations found by the sync, waiting for a decision |
| يجب التفعيل | A secondary debt paid, waiting for the full activation |

### Open items

- Platform subscription alerts are shown in the panels only. No WhatsApp or e-mail channel is connected (`SubscriptionAlerts::notify()` is the place).
- The multi-tenant platform is marked "in progress" in `AGENTS.md`. Client 1 must keep working unchanged.
- The Chrome extension (`App\Sync\BrowserScripts`, version in its manifest) cannot be tested against the real company site from a server outside Iraq. Changes to it are checked with `node --check` on the generated script plus the PHP tests, then tried by the owner.
