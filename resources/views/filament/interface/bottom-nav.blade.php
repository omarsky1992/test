@auth
@if (auth()->user()->usesEmployeeUi())
@php
    $items = [
        ['الرئيسية', 'heroicon-o-home', \App\Filament\Pages\EmployeeHome::getUrl(), 'filament.admin.pages.home'],
        ['المشتركون', 'heroicon-o-users', \App\Filament\Resources\SubscriberCards\SubscriberCardResource::getUrl(), 'filament.admin.resources.cards.*'],
        null,
        ['حسابي', 'heroicon-o-document-text', \App\Filament\Pages\EmployeeStatement::getUrl(), 'filament.admin.pages.employee-statement'],
    ];
@endphp
<style>
    .subs-bottom-nav{display:none}
    @media (max-width: 1023px){
        .subs-bottom-nav{display:flex;position:fixed;inset-inline:0;bottom:0;z-index:30;height:64px;background:var(--subs-surface);border-top:1px solid var(--subs-border);justify-content:space-around;align-items:center;padding-bottom:env(safe-area-inset-bottom)}
        .subs-bottom-nav a,.subs-bottom-nav button{display:flex;flex-direction:column;align-items:center;gap:2px;font-size:11px;font-weight:700;color:var(--subs-muted);background:none;border:0}
        .subs-bottom-nav .on{color:var(--primary-600)}
        .subs-bottom-nav .fab{width:54px;height:54px;border-radius:50%;background:var(--primary-600);color:white;margin-top:-26px;justify-content:center;box-shadow:0 6px 14px rgba(0,0,0,.2)}
        .fi-main{padding-bottom:84px}
    }
</style>
<nav class="subs-bottom-nav" aria-label="التنقل السريع">
    @foreach ($items as $item)
        @if ($item === null)
            <a class="fab" href="{{ \App\Filament\Pages\EmployeeHome::getUrl(['action' => 'pay']) }}">
                <x-filament::icon icon="heroicon-o-plus" style="width:22px;height:22px" />قبض
            </a>
        @else
            <a href="{{ $item[2] }}" class="{{ request()->routeIs($item[3]) ? 'on' : '' }}">
                <x-filament::icon :icon="$item[1]" style="width:22px;height:22px" />{{ $item[0] }}
            </a>
        @endif
    @endforeach
    <button type="button" x-data x-on:click="$store.sidebar.open()">
        <x-filament::icon icon="heroicon-o-bars-3" style="width:22px;height:22px" />المزيد
    </button>
</nav>
@endif
@endauth
