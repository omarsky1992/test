<?php

namespace App\Support;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Activation;
use App\Models\Debt;
use App\Models\DebtTransfer;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «📜 شنو صار ويا هذا المشترك»: the subscriber's story in plain Arabic sentences, newest first, read
 * from what the system recorded (renewals a sync or WhatsApp found and what was decided on them, debts,
 * receipts, transfers, panel activations), plus where he stands now. It answers «ليش ما عليه دين؟».
 * Read only; money lines only for who may see them (`debts.view`, `payments.view`).
 */
class SubscriberTimeline
{
    /** @var array<int, string> */
    private array $users = [];

    /**
     * @return array{now: list<array{text: string, color: string}>, events: Collection<int, array{at: CarbonImmutable, icon: string, color: string, text: string}>}
     */
    public static function for(Subscriber $subscriber, int $limit = 80): array
    {
        return (new self)->build($subscriber, $limit);
    }

    private function build(Subscriber $subscriber, int $limit): array
    {
        $user = auth()->user();
        $debts = (bool) $user?->can('debts.view');
        $payments = (bool) $user?->can('payments.view');

        $accounts = Account::where('subscriber_id', $subscriber->id)->get();
        $renewals = AccountRenewal::with(['account', 'debt', 'decisionDebt'])->where('subscriber_id', $subscriber->id)->get();
        $activations = Activation::with('account')->where('subscriber_id', $subscriber->id)->get();
        $debtRows = $debts ? Debt::with('account')->where('subscriber_id', $subscriber->id)->get() : collect();
        $paymentRows = $payments ? Payment::with(['allocations.debt', 'moneyAccount', 'account'])->where('subscriber_id', $subscriber->id)->get() : collect();
        $transfers = $debts ? DebtTransfer::with('debt')->where('subscriber_id', $subscriber->id)->get() : collect();

        $this->users = User::whereIn('id', collect()
            ->merge($renewals->pluck('decided_by'))->merge($activations->pluck('created_by'))->merge($activations->pluck('voided_by'))
            ->merge($debtRows->pluck('created_by'))->merge($debtRows->pluck('voided_by'))
            ->merge($paymentRows->pluck('created_by'))->merge($paymentRows->pluck('voided_by'))->merge($transfers->pluck('performed_by'))
            ->filter()->unique()->all())->pluck('name', 'id')->all();

        $events = collect();
        foreach ($renewals as $r) {
            $this->renewal($events, $r, $debts);
        }
        foreach ($activations as $a) {
            $this->activation($events, $a, $debts);
        }
        // Debts a renewal, a decision or a panel activation already tell are not told twice.
        $told = $renewals->pluck('debt_id')->merge($renewals->pluck('decision_debt_id'))->filter()->flip();
        foreach ($debtRows as $d) {
            $this->debt($events, $d, isset($told[$d->id]));
        }
        foreach ($paymentRows as $p) {
            $this->payment($events, $p);
        }
        foreach ($transfers as $t) {
            $this->transfer($events, $t);
        }

        return [
            'now' => $this->now($accounts, $renewals, $debts),
            'events' => $events->sortByDesc(fn ($e) => $e['at']->getTimestamp())->take($limit)->values(),
        ];
    }

    /** @return list<array{text: string, color: string}> */
    private function now(Collection $accounts, Collection $renewals, bool $debts): array
    {
        $lines = [];
        $tz = config('app.timezone');
        foreach ($accounts->where('status', '!=', 'closed') as $a) {
            if (blank($a->external_subscription_id) && $a->company_synced_at === null) {
                $lines[] = ['text' => "الجهاز {$a->username} ما مربوط بموقع الشركة بعد: المزامنة ما قرته، فما تكتشف تفعيلاته.", 'color' => 'warning'];
            } elseif ($a->company_synced_at !== null) {
                $ends = $a->external_ends_at ? ' (ينتهي '.$a->external_ends_at->setTimezone($tz)->format('Y/m/d H:i').')' : '';
                $days = $a->company_days_left;
                $lines[] = ['text' => "آخر مزامنة قرت الجهاز {$a->username} يوم ".$a->company_synced_at->setTimezone($tz)->format('Y/m/d H:i').': '
                    .($days === null ? 'بدون تاريخ انتهاء' : ($days > 0 ? "باقيله {$days} يوم" : 'منتهي')).$ends.'.', 'color' => 'gray'];
            }
        }
        foreach ($renewals as $r) {
            if ($r->status === 'pending_debt') {
                $lines[] = ['text' => "تفعيل {$r->new_days} أيام (".$this->day($r->detected_at).') ينتظر قرارك بـ«المكتشف 7 أيام»: ما ينسجل دين ثانوي إلا إذا ضغطت «نعم».', 'color' => 'warning'];
            } elseif ($r->status === 'activated' && $r->decision === null && $r->sync_run_id !== null) {
                $lines[] = ['text' => "تفعيل {$r->new_days} يوم (".$this->day($r->detected_at).') ينتظر قرارك بـ«المفعّل حديثاً»: ما ينسجل دين إلا إذا اخترت «دين».', 'color' => 'warning'];
            }
        }
        if ($debts) {
            $open = Debt::whereIn('account_id', $accounts->pluck('id'))->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->where('balance', '>', 0)
                ->selectRaw('bucket, sum(balance) as total')->groupBy('bucket')->pluck('total', 'bucket');
            $parts = collect([DebtBucket::Primary->value => 'دين أولي', DebtBucket::Secondary->value => 'دين ثانوي'])
                ->filter(fn ($label, $bucket) => (int) ($open[$bucket] ?? 0) > 0)
                ->map(fn ($label, $bucket) => $label.' '.Money::format((int) $open[$bucket]));
            $lines[] = $parts->isEmpty()
                ? ['text' => 'ما عليه أي دين مفتوح هسه.', 'color' => 'success']
                : ['text' => 'عليه هسه: '.$parts->implode(' و').'.', 'color' => 'danger'];
        }

        return $lines;
    }

    private function renewal(Collection $events, AccountRenewal $r, bool $debts): void
    {
        $device = $r->account?->username;
        $source = AccountRenewal::PAYMENT_SOURCES[$r->payment_source] ?? null;
        $how = $r->sync_run_id !== null ? 'المزامنة اكتشفت' : 'انسجل بالواتساب';
        $text = "{$how} تفعيل {$r->new_days} يوم للجهاز {$device} ({$r->previous_days} ← {$r->new_days})"
            .($r->new_ends_at ? '، ينتهي '.$this->day($r->new_ends_at) : '')
            .($r->is_new ? '، مشترك جديد' : '')
            .($source ? '، الدفع بموقع الشركة: '.$source : '').'. ';
        $text .= match (true) {
            $r->status === 'test' => 'أقل من 4 أيام، فالنظام اعتبره تيست من المشترك وما يسجّل دين.',
            $r->status === 'no_price' => 'الفئة ما إلها سعر بالنظام، فما انسجل دين.',
            $r->status === 'pending_debt' => 'تفعيل قصير: ينتظر «نعم/لا» بالمكتشف 7 أيام.',
            $r->status === 'no_debt' => 'تفعيل قصير: انقرر بدون دين (التفصيل تحت).',
            $r->status === 'debt_created' && $r->decided_at === null => 'انسجل عليه دين ثانوي'.($debts && $r->debt ? " {$r->debt->number} بـ".Money::format((int) $r->debt->original_amount) : '').' تلقائياً.',
            $r->status === 'debt_created' => 'تفعيل قصير: انسجل دين ثانوي بعد «نعم» (التفصيل تحت).',
            $r->decision === 'earlier' => 'تفعيل كامل: ما يسجّل دين تلقائي (قبل ما تنضاف قائمة المفعّل حديثاً).',
            default => 'تفعيل كامل: ما يسجّل دين تلقائي، والقرار بالمفعّل حديثاً.',
        };
        $events->push($this->event($r->detected_at, '🔄', 'info', $text));

        if ($r->decided_at === null) {
            return;
        }
        $who = $this->user($r->decided_by);
        $money = fn (?Debt $d) => $debts && $d ? " {$d->number} بـ".Money::format((int) $d->original_amount) : '';
        $decision = match (true) {
            $r->status === 'debt_created' => ["{$who} ضغط «نعم» بالمكتشف 7 أيام: انسجل دين ثانوي".$money($r->debt).'.', 'danger'],
            $r->status === 'no_debt' => ["{$who} ضغط «لا» بالمكتشف 7 أيام: ما انسجل دين ثانوي.", 'gray'],
            $r->decision === 'debt' => ["{$who} اختار «دين» بالمفعّل حديثاً: انسجل دين أولي".$money($r->decisionDebt).'.', 'danger'],
            $r->decision === 'cash' => ["{$who} اختار «نقد» بالمفعّل حديثاً: فعّال بدون دين.", 'success'],
            $r->decision === 'transfer' => ["{$who} اختار «مناقلة» بالمفعّل حديثاً: الدين الثانوي انتقل للديون الأولية.", 'info'],
            default => null,
        };
        if ($decision !== null) {
            $events->push($this->event($r->decided_at, '✋', $decision[1], $decision[0]));
        }
    }

    private function activation(Collection $events, Activation $a, bool $debts): void
    {
        $text = "تفعيل {$a->kind?->getLabel()} من لوحة التحكم ({$a->number}) للجهاز {$a->account?->username} من "
            .$this->day($a->starts_at).' إلى '.$this->day($a->ends_at)
            .($debts ? '، السعر '.Money::format((int) $a->final_price).'، '.($a->settlement?->getLabel() ?? '') : '')
            .'، سجّله '.$this->user($a->created_by).'.';
        $events->push($this->event($a->created_at, '⚡', 'info', $text));
        if ($a->voided_at !== null) {
            $events->push($this->event($a->voided_at, '🚫', 'gray', "انلغى التفعيل {$a->number} من ".$this->user($a->voided_by).($a->void_reason ? "، السبب: «{$a->void_reason}»" : '').'.'));
        }
    }

    private function debt(Collection $events, Debt $d, bool $told): void
    {
        $bucket = $d->bucket === DebtBucket::Primary ? 'أولي' : 'ثانوي';
        if (! $told && $d->source !== DebtSource::Renewal && $d->source !== DebtSource::Activation) {
            $events->push($this->event($d->created_at ?? $d->debt_date, '🧾', 'danger',
                "انسجل دين {$bucket} {$d->number} بـ".Money::format((int) $d->original_amount)." ({$d->source?->getLabel()})"
                .($d->created_by ? ' من '.$this->user($d->created_by) : '').($d->notes ? ": {$d->notes}" : '').'.'));
        }
        if ($d->voided_at !== null) {
            $events->push($this->event($d->voided_at, '🚫', 'gray',
                "انلغى الدين {$bucket} {$d->number} (".Money::format((int) $d->original_amount).') من '.$this->user($d->voided_by)
                .($d->void_reason ? "، السبب: «{$d->void_reason}»" : '').'.'));
        }
    }

    private function payment(Collection $events, Payment $p): void
    {
        $paid = $p->allocations->where('is_reversed', false)->map(fn ($a) => ($a->debt?->number ?? '?').' ('.Money::format((int) $a->amount).')')->implode('، ');
        $text = "سند قبض {$p->receipt_number} بـ".Money::format((int) $p->amount).' استلمه '.$this->user($p->created_by)
            .($p->moneyAccount ? " ({$p->moneyAccount->name})" : '')
            .($paid !== '' ? '، سدّد: '.$paid : '')
            .((int) $p->activation_amount > 0 ? '، تفعيل نقدي '.Money::format((int) $p->activation_amount) : '').'.';
        $events->push($this->event($p->received_at, '💵', 'success', $text));
        if ($p->voided_at !== null) {
            $events->push($this->event($p->voided_at, '🚫', 'gray', "انلغى السند {$p->receipt_number} من ".$this->user($p->voided_by)
                .($p->void_reason ? "، السبب: «{$p->void_reason}»" : '').': رجعت الديون اللي سدّدها.'));
        }
    }

    private function transfer(Collection $events, DebtTransfer $t): void
    {
        $events->push($this->event($t->performed_at, '↔️', 'info', "مناقلة {$t->number}: ".Money::format((int) $t->amount)
            .' من الديون الثانوية إلى الأولية، سوّاها '.$this->user($t->performed_by).($t->reason ? "، السبب: «{$t->reason}»" : '').'.'));
    }

    private function event(?\DateTimeInterface $at, string $icon, string $color, string $text): array
    {
        return ['at' => CarbonImmutable::instance($at ?? now()), 'icon' => $icon, 'color' => $color, 'text' => $text];
    }

    private function user(?int $id): string
    {
        return $id ? ($this->users[$id] ?? 'مستخدم #'.$id) : 'النظام';
    }

    private function day(?\DateTimeInterface $at): string
    {
        return $at ? CarbonImmutable::instance($at)->setTimezone(config('app.timezone'))->format('Y/m/d') : '—';
    }
}
