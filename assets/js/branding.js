/**
 * Per-gym branding, applied at runtime from admin-defined settings
 * (api/settings.php?action=public). This lets ONE codebase render each gym's
 * identity — name, logo, tab icon, footer — with no hardcoded per-gym files or
 * per-gym code branch. Anything not set falls back to whatever is baked into
 * the page, so it is always safe to load.
 */
(function () {
    function setText(sel, text) {
        var el = document.querySelector(sel);
        if (el) el.textContent = text;
    }
    function each(sel, fn) {
        var nodes = document.querySelectorAll(sel);
        for (var i = 0; i < nodes.length; i++) fn(nodes[i]);
    }

    function apply(s) {
        if (!s) return;
        var name = (s.gym_name || '').trim();
        var logo = (s.logo_url || '').trim();
        var location = (s.location || '').trim();

        if (name) {
            // Keep the "— Login / Front Desk / Member" suffix, swap the gym name.
            var t = document.title || '';
            var dash = t.indexOf('—'); // em dash
            var suffix = dash >= 0 ? t.slice(dash + 1).trim() : '';
            document.title = suffix ? (name + ' — ' + suffix) : name;

            setText('.brand-login-head h1', name);
            setText('.brand-sidebar-header h2', name);
            each('.brand-splash-logo, .brand-login-logo, .brand-sidebar-logo', function (el) {
                el.setAttribute('alt', name);
            });
        }

        if (logo) {
            each('.brand-splash-logo, .brand-login-logo, .brand-sidebar-logo', function (el) {
                el.src = logo;
            });
            each('link[rel="icon"], link[rel="apple-touch-icon"]', function (el) {
                el.href = logo;
            });
        }

        // Login-page copyright line: "© <name> · <location>".
        if (name || location) {
            setText('.brand-copy', '© ' + (name || 'Gym') + (location ? ' · ' + location : ''));
        }
    }

    function run() {
        fetch('api/settings.php?action=public')
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res && res.success) apply(res.data); })
            .catch(function () { /* keep baked-in branding on error/offline */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
