<?php

namespace App\WhatsApp;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Models\CustodyReminder;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Models\WhatsappNumber;
use App\Services\Audit;
use App\Services\EmployeeFinance;
use App\Services\Settings;
use App\Services\TreasuryService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «تذكير تنزيل العهدة»: every day from `alerts.custody_hour` (Baghdad) each admin's WhatsApp number
 * gets one message per employee holding custody. A reply «نعم» hands the whole custody over to the
 * main cash box; an amount hands over that much (never more than the custody). A reply to one
 * message (WhatsApp «رد») picks that employee by the code in it; otherwise the latest one still open.
 */
class CustodyReminders
{
    private const YES = '/^(?:1|نعم|اي|ايه|ايي|ايوه|اكيد|صح|تمام|اوك|نزلها|نزل|ok|yes)$/iu';

    private const CODE = '/عهد[ةه]\s+رقم\s*([0-9٠-٩]+)/u';

    public function __construct(
        private Settings $settings,
        private Outbox $outbox,
        private EmployeeFinance $finance,
        private TreasuryService $treasury,
        private Audit $audit,
    ) {}

    /** The company box the custody goes to: the first active cash box. */
    public static function box(): ?MoneyAccount
    {
        return MoneyAccount::where('kind', MoneyAccountKind::Cash)->where('is_active', true)->orderBy('id')->first();
    }

    /** The admins' authorized numbers. */
    public static function adminNumbers()
    {
        return WhatsappNumber::with('user')->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (WhatsappNumber $n) => $n->user?->is_active && $n->user->hasRole('admin'))->values();
    }

    /** Queues today's reminders once the hour has come. Returns how many messages were queued. */
    public function queueDue(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(config('app.timezone'));
        if (! $this->settings->get('alerts.custody') || (int) $now->format('G') < (int) $this->settings->get('alerts.custody_hour')) {
            return 0;
        }
        $box = self::box();
        $numbers = self::adminNumbers();
        if ($box === null || $numbers->isEmpty()) {
            return 0;
        }
        $today = $now->toDateString();
        $queued = 0;
        $custodies = MoneyAccount::with('user')->where('kind', MoneyAccountKind::Custody)->whereNotNull('user_id')->orderBy('id')->get();
        foreach ($custodies as $custody) {
            $employee = $custody->user;
            if ($employee === null || ! $employee->is_active || ($balance = $this->treasury->balance($custody)) <= 0) {
                continue;
            }
            foreach ($numbers as $number) {
                $reminder = CustodyReminder::firstOrCreate(
                    ['user_id' => $employee->id, 'whatsapp_number_id' => $number->id, 'reminded_on' => $today, 'source' => 'daily'],
                    ['balance' => $balance],
                );
                if (! $reminder->wasRecentlyCreated) {
                    continue;
                }
                // Yesterday's reminder for the same employee is answered by today's.
                CustodyReminder::where('user_id', $employee->id)->where('whatsapp_number_id', $number->id)
                    ->where('status', 'sent')->whereKeyNot($reminder->id)->update(['status' => 'expired']);
                $body = "💼 تذكير تنزيل عهدة\nعهدة {$employee->name}: ".Money::format($balance)
                    ."\nرد على هذه الرسالة بـ«نعم» أو 1 تنزل كاملة إلى «{$box->name}»، أو اكتب مبلغ أقل ينزل المبلغ المكتوب."
                    ."\n(عهدة رقم {$reminder->id})";
                if ($this->outbox->queue($number->phone, $body, 'alert_custody', "custody:{$reminder->id}")) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /** «تنزيل عهدة فلان» / «نزل عهدة فلان». */
    private const REQUEST = '/^(?:تنزيل|نزل|تنزل|نزّل)\s+(?:ال)?عهد[ةه]\s+(.+)$/u';

    /**
     * «تنزيل عهدة احمد» from an authorized number: shows what the employee holds and opens a reply
     * («1» = all of it, an amount = that much), answered by answer() like the daily reminder.
     * Null when the message is not such a request.
     */
    public function request(WhatsappNumber $number, User $user, string $text): ?Result
    {
        if (! preg_match(self::REQUEST, RuleInterpreter::clean($text), $m)) {
            return null;
        }
        if (! $user->can('custody.settle')) {
            return Result::denied();
        }
        $box = self::box();
        if ($box === null) {
            return Result::failed('ما اكو صندوق نقدي مفعّل تنزل له العهدة.');
        }
        $query = trim($m[1]);
        $custodies = MoneyAccount::with('user')->where('kind', MoneyAccountKind::Custody)->whereNotNull('user_id')->orderBy('id')->get()
            ->filter(fn (MoneyAccount $c) => $c->user !== null);
        $exact = $custodies->filter(fn (MoneyAccount $c) => RuleInterpreter::clean($c->user->name) === $query);
        $matches = $exact->isNotEmpty() ? $exact : $custodies->filter(function (MoneyAccount $c) use ($query) {
            $name = RuleInterpreter::clean($c->user->name);
            foreach (preg_split('/\s+/u', $query) as $word) {
                if (! str_contains($name, $word)) {
                    return false;
                }
            }

            return true;
        });
        if ($matches->isEmpty()) {
            return Result::failed("ما لكيت موظف عنده عهدة باسم «{$query}».");
        }
        if ($matches->count() > 1) {
            return Result::clarify("اكو اكثر من موظف بهذا الاسم، اكتب الاسم كامل:\n".$matches->map(fn (MoneyAccount $c) => '• '.$c->user->name)->implode("\n"));
        }
        $custody = $matches->first();
        $employee = $custody->user;
        $balance = $this->treasury->balance($custody);
        if ($balance <= 0) {
            return Result::done("💼 عهدة {$employee->name}: ".Money::format($balance)."\nما اكو شي ينزل.");
        }

        return DB::transaction(function () use ($number, $employee, $balance, $box) {
            // The latest question for this employee on this number is the one a reply answers.
            CustodyReminder::where('user_id', $employee->id)->where('whatsapp_number_id', $number->id)->where('status', 'sent')
                ->update(['status' => 'expired']);
            $reminder = CustodyReminder::create([
                'user_id' => $employee->id, 'whatsapp_number_id' => $number->id, 'reminded_on' => now()->toDateString(),
                'balance' => $balance, 'source' => 'request',
            ]);

            return Result::done("💼 عهدة {$employee->name}: ".Money::format($balance)
                ."\nارسل 1 تنزل كاملة إلى «{$box->name}»، أو اكتب المبلغ اللي تريد تنزله."
                ."\n(عهدة رقم {$reminder->id})", ['custody' => $employee->name, 'balance' => $balance, 'reminder' => $reminder->id]);
        });
    }

    /**
     * A reply from an authorized number: «نعم» or an amount, to an open custody reminder. Null when the
     * message is not such a reply (it is then understood as any other command).
     */
    public function answer(WhatsappNumber $number, User $user, string $text, ?string $quoted = null): ?Result
    {
        $clean = RuleInterpreter::clean($text);
        $yes = (bool) preg_match(self::YES, $clean);
        $amount = $yes ? null : RuleInterpreter::amount($text);
        if (! $yes && $amount === null) {
            return null;
        }
        $code = $quoted !== null && preg_match(self::CODE, $quoted, $m) ? (int) RuleInterpreter::clean($m[1]) : null;
        $reminder = $code !== null
            ? CustodyReminder::with('user')->where('whatsapp_number_id', $number->id)->find($code)
            : CustodyReminder::with('user')->where('whatsapp_number_id', $number->id)->where('status', 'sent')
                ->where('created_at', '>=', now()->subDay())->latest('id')->first();
        if ($reminder === null) {
            return $code !== null ? Result::failed('ما لكيت تذكير العهدة هذا.') : null;
        }
        $name = $reminder->user->name;
        if ($reminder->status !== 'sent') {
            return Result::failed("تذكير عهدة {$name} هذا ".($reminder->status === 'done' ? 'انجاوب قبل.' : 'قديم، جاوب على تذكير اليوم.'));
        }
        if (! $user->can('custody.settle')) {
            return Result::denied();
        }
        $box = self::box();
        if ($box === null) {
            return Result::failed('ما اكو صندوق نقدي مفعّل تنزل له العهدة.');
        }
        if ($amount !== null && $amount < 250) {
            return Result::clarify('اكتب المبلغ كامل، مثل 50000 أو 50 الف.');
        }

        return $this->audit->withSource('whatsapp', fn () => DB::transaction(function () use ($reminder, $user, $box, $amount, $yes, $name) {
            $locked = CustodyReminder::whereKey($reminder->id)->lockForUpdate()->first();
            if ($locked->status !== 'sent') {
                return Result::failed("تذكير عهدة {$name} هذا انجاوب قبل.");
            }
            $balance = $this->finance->custodyBalance($reminder->user);
            if ($balance <= 0) {
                $locked->update(['status' => 'done', 'amount' => 0, 'answered_at' => now(), 'answered_by' => $user->id]);

                return Result::failed("ما بقت عهدة عند {$name}.");
            }
            $take = $yes ? $balance : $amount;
            if ($take > $balance) {
                return Result::failed('المبلغ أكبر من عهدة '.$name.' الحالية ('.Money::format($balance).'). اكتب مبلغ أقل أو «نعم» للكل.');
            }
            try {
                $transfer = $this->finance->handOver($reminder->user, $take, $box, "تنزيل عهدة {$name} من تذكير الواتساب");
            } catch (BusinessRuleException $e) {
                return Result::failed('❌ '.$e->getMessage());
            }
            // Answered for every admin number that got today's reminder of this employee.
            CustodyReminder::where('user_id', $reminder->user_id)->where('reminded_on', $reminder->reminded_on)->where('status', 'sent')
                ->update(['status' => 'done', 'amount' => $take, 'fund_transfer_id' => $transfer->id, 'answered_at' => now(), 'answered_by' => $user->id]);
            $left = $balance - $take;

            return Result::done("✅ نزلت من عهدة {$name} ".Money::format($take)." إلى «{$box->name}».\n"
                .($left > 0 ? 'باقي بعهدته: '.Money::format($left) : 'ما بقى شي بعهدته.'), [
                    'custody' => $name, 'amount' => $take, 'left' => $left, 'transfer' => $transfer->id,
                ]);
        }));
    }
}
