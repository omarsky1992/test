@auth
@php($user = auth()->user())
<div style="display:flex;align-items:center;gap:8px">
    @if ($user->isAdmin())
        <form method="POST" action="{{ route('ui.mode') }}">
            @csrf
            <button type="submit" title="تبديل الواجهة"
                style="display:inline-flex;align-items:center;gap:6px;border:1px solid var(--subs-border);border-radius:999px;padding:5px 12px;font-size:13px;font-weight:700;background:var(--subs-surface);color:var(--subs-text-2);white-space:nowrap">
                ⇄ {{ $user->ui_mode === 'employee' ? 'واجهة المدير' : 'واجهة الموظف' }}
            </button>
        </form>
    @endif
    <div x-data="{ open: false }" style="position:relative">
        <button type="button" x-on:click="open = ! open" title="لون الواجهة" aria-label="لون الواجهة"
            style="width:34px;height:34px;border-radius:50%;border:1px solid var(--subs-border);background:var(--subs-surface);font-size:16px">🎨</button>
        <div x-show="open" x-cloak x-on:click.outside="open = false"
            style="position:absolute;inset-inline-end:0;top:42px;z-index:50;background:var(--subs-surface);border:1px solid var(--subs-border);border-radius:14px;box-shadow:0 10px 25px rgba(0,0,0,.12);padding:12px;width:230px">
            <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--subs-text-2)">لون الواجهة</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">
                @foreach (\App\Support\Themes::ALL as $key => [$name, $hex])
                    <form method="POST" action="{{ route('ui.theme') }}" style="text-align:center">
                        @csrf
                        <input type="hidden" name="color" value="{{ $key }}">
                        <button type="submit" title="{{ $name }}"
                            style="width:36px;height:36px;border-radius:50%;background:{{ $hex }};border:3px solid white;box-shadow:0 0 0 {{ ($user->theme_color ?? 'teal') === $key ? '3px var(--subs-text)' : '1px var(--subs-border)' }}"></button>
                        <div style="font-size:11px;color:var(--subs-text-2)">{{ $name }}</div>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endauth
