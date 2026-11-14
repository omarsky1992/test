{{-- «رجوع» on every page but the home pages: back to the page before (with its tab, filters and search), or home when the page was opened directly (a link from WhatsApp, a new tab). Back only to a page of this site visited in this tab (filament.interface.back-trail). --}}
@auth
@unless (request()->routeIs('filament.admin.pages.dashboard', 'filament.admin.pages.home'))
@php($home = auth()->user()->usesEmployeeUi() ? \App\Filament\Pages\EmployeeHome::getUrl() : \Filament\Facades\Filament::getUrl())
<div class="subs-back-row">
    <button type="button" class="subs-back" data-home="{{ $home }}" onclick="window.subsTrail ? subsTrail.back(this.dataset.home) : history.back()"" title="الرجوع للصفحة السابقة">
        <x-filament::icon icon="heroicon-o-arrow-right" style="width:18px;height:18px" />
        <span>رجوع</span>
    </button>
</div>
<style>
    .subs-back-row{display:flex;justify-content:flex-start;margin-bottom:8px}
    .subs-back{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;font-weight:700;font-size:14px;
        background:var(--subs-surface, #fff);border:1px solid var(--subs-border, #e7e5e4);color:var(--primary-700, #0f766e);cursor:pointer}
    .subs-back:hover{background:var(--primary-50, #f0fdfa)}
    @media (max-width: 1023px){ .subs-back{padding:9px 18px;font-size:15px} }
</style>
@endunless
@endauth
