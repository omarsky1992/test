{{-- The pages visited in this tab (sessionStorage), so «رجوع» goes back only to a page of this site and, on a page opened directly, home. --}}
<script>
    (function () {
        if (window.subsTrail) return;
        var key = 'subs_trail';
        var read = function () { try { return JSON.parse(sessionStorage.getItem(key) || '[]'); } catch (e) { return []; } };
        var write = function (t) { try { sessionStorage.setItem(key, JSON.stringify(t.slice(-50))); } catch (e) {} };
        var visit = function () {
            var t = read(), here = location.href;
            if (t.length >= 2 && t[t.length - 2] === here) { t.pop(); }      // came back to the page before
            else if (t[t.length - 1] !== here) { t.push(here); }             // a new page
            write(t);
        };
        window.subsTrail = { visit: visit, back: function (home) {
            var t = read();
            if (t.length >= 2 && history.length > 1) { history.back(); return; }
            write([]);
            if (window.Livewire && Livewire.navigate) { Livewire.navigate(home); } else { location.href = home; }
        } };
        document.addEventListener('livewire:navigated', visit);
        document.addEventListener('DOMContentLoaded', visit);
    })();
</script>
