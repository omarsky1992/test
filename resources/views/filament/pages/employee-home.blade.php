@php
    use App\Support\Money;
    $tile = 'display:block;background:white;border:1px solid rgb(231 229 228);border-radius:16px;padding:12px 14px;text-decoration:none;color:inherit';
    $solid = 'display:block;background:var(--primary-600);border:1px solid var(--primary-600);border-radius:16px;padding:12px 14px;color:white;text-decoration:none';
    $label = 'font-size:13px;font-weight:600;color:rgb(120 113 108)';
    $value = 'font-size:24px;font-weight:800;line-height:1.3';
    $small = 'font-size:12px;color:rgb(120 113 108)';
    $sec = 'display:flex;justify-content:space-between;align-items:center;font-size:15px;font-weight:800;color:rgb(87 83 78);margin:4px 2px -4px';
@endphp
<x-filament-panels::page>
    {{-- حسابي --}}
    <div style="{{ $sec }}">
        <span>حسابي</span>
        <a href="{{ \App\Filament\Pages\EmployeeStatement::getUrl() }}" style="font-size:13px;color:var(--primary-600)">كشف حسابي ←</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
        <a href="{{ \App\Filament\Pages\EmployeeStatement::getUrl() }}" style="{{ $solid }}">
            <div style="font-size:13px;font-weight:600;opacity:.85">عهدتي الآن</div>
            <div style="{{ $value }}">{{ number_format($me['custody']) }}</div>
            <div style="font-size:12px;opacity:.85">{{ $me['pending_handover'] ? 'طلب تسليم بانتظار المدير' : 'لم تُسلَّم' }}</div>
        </a>
        <div style="{{ $tile }}">
            <div style="{{ $label }}">تحصيلي اليوم</div>
            <div style="{{ $value }}">{{ number_format($me['collected']) }}</div>
            <div style="{{ $small }}">{{ $me['receipts'] }} سند</div>
        </div>
        <div style="{{ $tile }}">
            <div style="{{ $label }}">سلفي المتبقية</div>
            <div style="{{ $value }};color:rgb(185 28 28)">{{ number_format($me['advances']) }}</div>
            <div style="{{ $small }}">{{ $me['advances_count'] }} سلفة</div>
        </div>
    </div>

    @if ($handoverRequests > 0)
        <a href="{{ \App\Filament\Resources\HandoverRequests\HandoverRequestResource::getUrl() }}"
            style="{{ $tile }};border-color:rgb(253 186 116);background:rgb(255 247 237);font-weight:700">
            📥 {{ $handoverRequests }} طلب تسليم عهدة بانتظار تأكيدك ←
        </a>
    @endif

    @if ($canSee)
        <div style="{{ $sec }}">
            <span>المشتركون</span>
            <a href="{{ $cardsUrl('all') }}" style="font-size:13px;color:var(--primary-600)">عرض الكل ←</a>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">
            <a href="{{ $cardsUrl('active') }}" style="{{ $solid }};grid-row:span 2;display:flex;flex-direction:column;justify-content:center;align-items:center">
                <div style="font-size:14px;font-weight:600;opacity:.9">الفعّالون</div>
                <div style="font-size:40px;font-weight:800">{{ number_format($counts['active']) }}</div>
            </a>
            <a href="{{ $cardsUrl('expiring') }}" style="{{ $tile }}">
                <div style="{{ $label }}">ينتهي قريباً ({{ app(\App\Services\Settings::class)->expiringDays() }} أيام أو أقل)</div>
                <div style="{{ $value }};color:rgb(234 88 12)">{{ number_format($counts['expiring']) }}</div>
            </a>
            <a href="{{ $cardsUrl('expired') }}" style="{{ $tile }}">
                <div style="{{ $label }}">منتهي</div>
                <div style="{{ $value }};color:rgb(220 38 38)">{{ number_format($counts['expired']) }}</div>
            </a>
            <a href="{{ $cardsUrl('must_activate') }}" style="{{ $tile }};background:rgb(245 243 255);border-color:rgb(221 214 254);grid-column:span 2">
                <div style="{{ $label }};color:rgb(109 40 217)">يجب التفعيل (سدّد دينه الثانوي)</div>
                <div style="{{ $value }};color:rgb(109 40 217)">{{ number_format($counts['must_activate']) }}</div>
            </a>
        </div>
    @endif

    @if ($canDebts)
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:start">
            <a href="{{ $cardsUrl('secondary') }}" style="{{ $tile }};border-color:rgb(254 215 170)">
                <div style="{{ $label }}">الديون الثانوية</div>
                <div style="{{ $value }};color:rgb(180 83 9)">{{ number_format($debts['secondary']) }}</div>
                <div style="{{ $small }}">{{ $debts['secondary_accounts'] }} حساب</div>
            </a>
            <div style="{{ $tile }};border-color:rgb(254 202 202)">
                <a href="{{ $cardsUrl('primary') }}" style="display:flex;justify-content:space-between;align-items:baseline;text-decoration:none;color:inherit">
                    <span style="{{ $label }}">الديون الأولية</span>
                    <span style="font-size:20px;font-weight:800;color:rgb(185 28 28)">{{ number_format($debts['primary']) }}</span>
                </a>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                    <a href="{{ $cardsUrl('active_primary') }}" style="border:1px solid rgb(231 229 228);border-radius:10px;padding:5px 8px;text-decoration:none;color:inherit">
                        <div style="font-size:11.5px;color:rgb(120 113 108)">فعّال وعليه دين</div>
                        <div style="font-size:17px;font-weight:800">{{ $counts['active_primary'] ?? 0 }}</div>
                    </a>
                    <a href="{{ $cardsUrl('expired_primary') }}" style="border:1px solid rgb(231 229 228);border-radius:10px;padding:5px 8px;text-decoration:none;color:inherit">
                        <div style="font-size:11.5px;color:rgb(120 113 108)">منتهي وعليه دين</div>
                        <div style="font-size:17px;font-weight:800">{{ $counts['expired_primary'] ?? 0 }}</div>
                    </a>
                </div>
            </div>
        </div>
    @endif

    <div style="display:flex;flex-wrap:wrap;gap:8px">
        @can('payments.create') {{ $this->payAction }} @endcan
        @can('activations.create') {{ $this->activateAction }} @endcan
        @can('follow_ups.create')
            <x-filament::button tag="a" color="gray" icon="heroicon-o-phone" :href="\App\Filament\Resources\FollowUps\FollowUpResource::getUrl()">المتابعة</x-filament::button>
        @endcan
        @can('whatsapp.remind')
            <x-filament::button tag="a" color="gray" icon="heroicon-o-chat-bubble-left-ellipsis" :href="\App\Filament\Pages\WhatsappReminders::getUrl()">تذكير واتساب</x-filament::button>
        @endcan
    </div>

    @if ($canSee)
        <x-filament::section>
            <x-slot name="heading">يجب التفعيل ({{ $counts['must_activate'] }})</x-slot>
            @if ($dues->isEmpty())
                <p style="font-size:14px;color:rgb(120 113 108)">لا يوجد. كل من سدّد دينه الثانوي فُعّل.</p>
            @else
                <div style="display:flex;flex-direction:column;gap:8px">
                    @foreach ($dues as $due)
                        <div style="display:flex;align-items:center;gap:10px;background:rgb(245 243 255);border:1px solid rgb(221 214 254);border-radius:14px;padding:9px 12px">
                            <div style="width:34px;height:34px;border-radius:50%;background:rgb(124 58 237);color:white;display:flex;align-items:center;justify-content:center;font-weight:800">✔</div>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:800">{{ $due->subscriber?->full_name }}</div>
                                <div style="font-size:12px;color:rgb(120 113 108)"><span dir="ltr">{{ $due->account?->username }}</span> · سدّد {{ Money::format($due->amount) }} · {{ $due->paid_at->diffForHumans() }}</div>
                            </div>
                            @if ($canMarkDone)
                                <x-filament::button size="sm" color="primary" wire:click="markDone({{ $due->account_id }})" wire:confirm="تأكيد: تم تفعيل {{ $due->subscriber?->full_name }} على موقع الشركة؟">تم التفعيل</x-filament::button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif

</x-filament-panels::page>
