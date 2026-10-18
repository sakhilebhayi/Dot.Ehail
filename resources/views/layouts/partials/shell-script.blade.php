{{-- Off-canvas drawer for the app shell below 860px. Plain JavaScript, no dependencies.
     Contract: #app-sidebar is the drawer, [data-shell-open] the top-bar button, [data-shell-close]
     the in-drawer close button and the backdrop, [data-shell-behind] what is made inert while open. --}}
<script>
    (function () {
        var root = document.documentElement;
        var drawer = document.getElementById('app-sidebar');
        var opener = document.querySelector('[data-shell-open]');
        if (!drawer || !opener) { return; }

        var closer = drawer.querySelector('[data-shell-close]');
        var backdrops = document.querySelectorAll('.shell-backdrop[data-shell-close]');
        var behind = document.querySelectorAll('[data-shell-behind]');
        var narrow = window.matchMedia('(max-width: 859.98px)');
        var focusable = 'a[href], button:not([disabled]), summary, input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

        // Links inside a closed <details> are skipped by the browser, so they must not count as the last stop.
        function canFocus(el) {
            var closed = el.closest('details:not([open])');
            if (closed && !(el.tagName === 'SUMMARY' && el.parentNode === closed)) { return false; }
            return el.offsetParent !== null;
        }

        function isOpen() { return root.classList.contains('shell-open'); }

        function sync() {
            if (!narrow.matches) { root.classList.remove('shell-open'); }
            var open = isOpen();
            opener.setAttribute('aria-expanded', open ? 'true' : 'false');
            drawer.inert = narrow.matches && !open;
            if (narrow.matches && open) {
                drawer.setAttribute('role', 'dialog');
                drawer.setAttribute('aria-modal', 'true');
            } else {
                drawer.removeAttribute('role');
                drawer.removeAttribute('aria-modal');
            }
            for (var i = 0; i < behind.length; i++) { behind[i].inert = open; }
        }

        function open() {
            if (!narrow.matches || isOpen()) { return; }
            root.classList.add('shell-open');
            sync();
            (closer || drawer).focus();
        }

        function close(returnFocus) {
            if (!isOpen()) { return; }
            root.classList.remove('shell-open');
            sync();
            if (returnFocus !== false) { opener.focus(); }
        }

        opener.addEventListener('click', function () { if (isOpen()) { close(); } else { open(); } });
        if (closer) { closer.addEventListener('click', function () { close(); }); }
        for (var b = 0; b < backdrops.length; b++) { backdrops[b].addEventListener('click', function () { close(); }); }

        document.addEventListener('keydown', function (event) {
            if (!isOpen()) { return; }
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key !== 'Tab') { return; }
            var items = Array.prototype.filter.call(drawer.querySelectorAll(focusable), canFocus);
            if (!items.length) { event.preventDefault(); return; }
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
        });

        if (narrow.addEventListener) { narrow.addEventListener('change', sync); } else { narrow.addListener(sync); }
        window.addEventListener('pageshow', function (event) { if (event.persisted) { close(false); } });
        sync();
    })();
</script>
