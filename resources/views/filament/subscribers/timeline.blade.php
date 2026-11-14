@php
    $story = \App\Support\SubscriberTimeline::for($getRecord());
    $tones = ['danger' => 'rgb(220 38 38)', 'warning' => 'rgb(217 119 6)', 'success' => 'rgb(22 163 74)', 'info' => 'rgb(37 99 235)', 'gray' => 'rgb(120 113 108)'];
@endphp
<div style="display:flex;flex-direction:column;gap:14px">
    @if ($story['now'])
        <div style="border:1px solid var(--subs-border, #e7e5e4);border-radius:12px;padding:10px 12px">
            <div style="font-weight:800;margin-bottom:6px">📍 وضعه هسه</div>
            <ul style="margin:0;padding-inline-start:18px;display:flex;flex-direction:column;gap:4px">
                @foreach ($story['now'] as $line)
                    <li style="color:{{ $tones[$line['color']] ?? 'inherit' }}">{{ $line['text'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @forelse ($story['events'] as $e)
        <div style="display:flex;gap:10px;align-items:flex-start" class="subs-timeline-row">
            <div style="font-size:18px;line-height:1.4">{{ $e['icon'] }}</div>
            <div style="flex:1;min-width:0;border-inline-start:3px solid {{ $tones[$e['color']] ?? '#ccc' }};padding-inline-start:10px">
                <div style="font-size:12px;color:var(--subs-muted, #78716c)" dir="ltr" class="text-right">{{ $e['at']->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}</div>
                <div>{{ $e['text'] }}</div>
            </div>
        </div>
    @empty
        <div style="color:var(--subs-muted, #78716c)">ما مسجّل شي بعد على هذا المشترك.</div>
    @endforelse
</div>
