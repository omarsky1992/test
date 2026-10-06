{{-- Colours of the system's own screens, light and dark (Filament puts .dark on <html>). --}}
<style>
    :root {
        --subs-surface: #ffffff; --subs-subtle: rgb(245 245 244); --subs-border: rgb(231 229 228);
        --subs-text: rgb(28 25 23); --subs-text-2: rgb(68 64 60); --subs-muted: rgb(120 113 108);
        --subs-red: rgb(185 28 28); --subs-amber: rgb(180 83 9); --subs-orange: rgb(234 88 12);
        --subs-green: rgb(21 128 61); --subs-violet: rgb(109 40 217); --subs-bubble: rgb(220 248 198);
        --tv-bg: rgb(245 243 255); --tv-bd: rgb(221 214 254);
        --to-bg: rgb(255 247 237); --to-bd: rgb(254 215 170);
        --tr-bg: rgb(254 242 242); --tr-bd: rgb(254 202 202);
        --tg-bg: rgb(240 253 244); --tg-bd: rgb(187 247 208);
        --tt-bg: rgb(240 253 250); --tt-bd: rgb(153 246 228);
    }
    .dark {
        --subs-surface: rgb(28 25 23); --subs-subtle: rgb(41 37 36); --subs-border: rgb(68 64 60);
        --subs-text: rgb(245 245 244); --subs-text-2: rgb(214 211 209); --subs-muted: rgb(168 162 158);
        --subs-red: rgb(248 113 113); --subs-amber: rgb(251 191 36); --subs-orange: rgb(251 146 60);
        --subs-green: rgb(74 222 128); --subs-violet: rgb(196 181 253); --subs-bubble: rgb(20 83 45);
        --tv-bg: rgb(124 58 237 / .16); --tv-bd: rgb(167 139 250 / .45);
        --to-bg: rgb(234 88 12 / .14); --to-bd: rgb(251 146 60 / .45);
        --tr-bg: rgb(220 38 38 / .14); --tr-bd: rgb(248 113 113 / .45);
        --tg-bg: rgb(22 163 74 / .14); --tg-bd: rgb(74 222 128 / .4);
        --tt-bg: rgb(13 148 136 / .16); --tt-bd: rgb(45 212 191 / .4);
    }
    .subs-card { color: var(--subs-text); }
    .subs-tone-violet { background: var(--tv-bg); border-color: var(--tv-bd) !important; }
    .subs-tone-orange { background: var(--to-bg); border-color: var(--to-bd) !important; }
    .subs-tone-red { background: var(--tr-bg); border-color: var(--tr-bd) !important; }
    .subs-tone-green { background: var(--tg-bg); border-color: var(--tg-bd) !important; }
    .subs-tone-gray { background: var(--subs-surface); border-color: var(--subs-border) !important; }
</style>
