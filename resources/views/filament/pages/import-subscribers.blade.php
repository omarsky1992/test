@php
    $labels = ['create' => ['جديد', 'success'], 'update' => ['تحديث', 'info'], 'same' => ['موجود بلا تغيير', 'gray'], 'merged' => ['مدموج', 'gray'], 'error' => ['خطأ — لن يُستورد', 'danger']];
    $steps = ['رفع الملف', 'مطابقة الأعمدة والخيارات', 'المعاينة', 'النتيجة'];
@endphp
<x-filament-panels::page>
    <nav style="display:flex;gap:8px;flex-wrap:wrap">
        @foreach ($steps as $i => $label)
            <x-filament::badge :color="$step === $i + 1 ? 'primary' : ($step > $i + 1 ? 'success' : 'gray')" size="lg">{{ $i + 1 }}. {{ $label }}</x-filament::badge>
        @endforeach
    </nav>

    @if ($step <= 2)
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if ($step === 1)
                {{ $this->uploadAction }}
                {{ $this->templateAction }}
            @else
                {{ $this->previewAction }}
                {{ $this->backAction }}
                <span style="align-self:center;font-size:13px;color:rgb(120 113 108)">الملف: {{ $originalName }} · {{ number_format($rowCount) }} صف</span>
            @endif
        </div>
    @endif

    @if ($step === 3 && $stats)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
            @foreach ([
                ['صفوف الملف', $stats['rows_total'], 'gray'],
                ['مشتركون جدد', $stats['subscribers_create'], 'success'],
                ['حسابات جديدة', $stats['accounts_create'], 'success'],
                ['مشتركون/حسابات تُحدَّث', $stats['subscribers_update'] + $stats['accounts_update'], 'info'],
                ['موجودون بلا تغيير', $stats['subscribers_same'], 'gray'],
                ['صفوف مدموجة (اشتراك أقدم لنفس الخط)', $stats['rows_merged'], 'gray'],
                ['صفوف بتنبيهات', $stats['rows_with_warnings'], 'warning'],
                ['صفوف فيها أخطاء (لن تُستورد)', $stats['rows_error'], 'danger'],
            ] as [$label, $value, $color])
                <x-filament::section>
                    <div style="font-size:12.5px;color:rgb(120 113 108);font-weight:600">{{ $label }}</div>
                    <div style="font-size:26px;font-weight:700;margin-top:4px">
                        <x-filament::badge :color="$color" size="lg">{{ number_format($value) }}</x-filament::badge>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="تفاصيل الصفوف" :description="count($previewRows) < $stats['rows_total'] ? 'تُعرض أول '.count($previewRows).' صف، والأخطاء والتنبيهات أولاً.' : 'الأخطاء والتنبيهات أولاً.'">
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:720px">
                    <thead>
                        <tr style="text-align:right;color:rgb(120 113 108);border-bottom:1px solid rgb(231 229 228)">
                            <th style="padding:8px 4px">الصف</th><th style="padding:8px 10px">الاسم</th><th style="padding:8px 10px">الهاتف</th><th style="padding:8px 10px">اليوزر</th><th style="padding:8px 10px">النتيجة</th><th style="padding:8px 10px">ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $row)
                            <tr style="border-bottom:1px solid rgb(245 245 244);{{ $row['status'] === 'error' ? 'background:rgb(254 242 242)' : '' }}">
                                <td style="padding:7px 4px">{{ $row['line'] }}</td>
                                <td style="font-weight:600;padding:7px 10px">{{ $row['name'] ?: '—' }}</td>
                                <td dir="ltr" style="text-align:right;padding:7px 10px;white-space:nowrap">{{ $row['phone'] ?: '—' }}</td>
                                <td dir="ltr" style="text-align:right;padding:7px 10px;white-space:nowrap">{{ $row['username'] ?: '—' }}</td>
                                <td style="padding:7px 10px"><x-filament::badge :color="$labels[$row['status']][1]">{{ $labels[$row['status']][0] }}</x-filament::badge></td>
                                <td style="font-size:12.5px;color:{{ $row['status'] === 'error' ? 'rgb(185 28 28)' : 'rgb(87 83 78)' }}">{{ implode(' · ', $row['messages']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            {{ $this->importAction }}
            {{ $this->backAction }}
        </div>
    @endif

    @if ($step === 4 && $stats)
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success" heading="تم الاستيراد بنجاح">
            <p style="font-size:15px;line-height:2">
                أُضيف <b>{{ number_format($stats['subscribers_create']) }}</b> مشترك و<b>{{ number_format($stats['accounts_create']) }}</b> حساب،
                وحُدِّث <b>{{ number_format($stats['subscribers_update']) }}</b> مشترك و<b>{{ number_format($stats['accounts_update']) }}</b> حساب.
                تُركت <b>{{ number_format($stats['rows_error']) }}</b> صف فيها أخطاء. العملية مسجلة في سجل العمليات برقم #{{ $runId }}.
            </p>
        </x-filament::section>
        <div style="display:flex;gap:8px">
            <x-filament::button tag="a" :href="$this->subscribersUrl()">عرض المشتركين</x-filament::button>
            {{ $this->restartAction }}
        </div>
    @endif

    @if ($step === 1)
        <x-filament::section heading="آخر عمليات الاستيراد" collapsible collapsed>
            @forelse ($this->recentRuns() as $run)
                <div style="display:flex;gap:12px;font-size:13.5px;padding:6px 0;border-bottom:1px solid rgb(245 245 244)">
                    <span dir="ltr">{{ $run->created_at->format('Y/m/d H:i') }}</span>
                    <span>{{ $run->file_name }}</span>
                    <x-filament::badge :color="$run->status === 'success' ? 'success' : 'danger'">{{ $run->status === 'success' ? 'نجح' : 'فشل' }}</x-filament::badge>
                    <span style="color:rgb(120 113 108)">+{{ $run->stats['subscribers_create'] ?? 0 }} مشترك · +{{ $run->stats['accounts_create'] ?? 0 }} حساب</span>
                    <span style="margin-inline-start:auto">{{ $run->creator?->name }}</span>
                </div>
            @empty
                <p style="font-size:13px;color:rgb(120 113 108)">لا توجد عمليات بعد.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
