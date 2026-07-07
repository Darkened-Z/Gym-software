/**
 * Per-gym branding, applied at runtime from admin-defined settings
 * (api/settings.php?action=public). This lets ONE codebase render each gym's
 * identity — name, logo, tab icon, accent colour, font — with no hardcoded
 * per-gym files or per-gym code branch. Anything not set falls back to whatever
 * is baked into the page/CSS, so it is always safe to load.
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
    function injectStyle(id, css) {
        var el = document.getElementById(id);
        if (!el) { el = document.createElement('style'); el.id = id; document.head.appendChild(el); }
        el.textContent = css;
    }
    function normHex(v) {
        v = (v || '').trim();
        if (/^#?[0-9a-fA-F]{6}$/.test(v)) return v.charAt(0) === '#' ? v : '#' + v;
        return '';
    }
    function shade(hex, pct) {
        hex = hex.replace('#', '');
        var f = pct / 100, out = '#';
        for (var i = 0; i < 3; i++) {
            var c = parseInt(hex.substr(i * 2, 2), 16);
            c = Math.round(f >= 0 ? c + (255 - c) * f : c + c * f);
            c = Math.max(0, Math.min(255, c));
            out += ('0' + c.toString(16)).slice(-2);
        }
        return out;
    }
    function rgbList(hex) {
        hex = hex.replace('#', '');
        return parseInt(hex.substr(0, 2), 16) + ', ' + parseInt(hex.substr(2, 2), 16) + ', ' + parseInt(hex.substr(4, 2), 16);
    }

    // Curated font pairings (display / body) — bright, legible, dark-theme-safe.
    var FONT_PRESETS = {
        inter: { d: "'Inter'", b: "'Inter'", url: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap' },
        poppins: { d: "'Poppins'", b: "'Poppins'", url: 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap' },
        montserrat: { d: "'Montserrat'", b: "'Montserrat'", url: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap' },
        oswald: { d: "'Oswald'", b: "'Roboto'", url: 'https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Roboto:wght@400;500;700&display=swap' },
        playfair: { d: "'Playfair Display'", b: "'Lato'", url: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Lato:wght@400;700&display=swap' }
    };

    function apply(s) {
        if (!s) return;
        var name = (s.gym_name || '').trim();
        var logo = (s.logo_url || '').trim();
        var location = (s.location || '').trim();

        if (name) {
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

        if (name || location) {
            setText('.brand-copy', '© ' + (name || 'Gym') + (location ? ' · ' + location : ''));
        }

        // Accent colour — overrides the accent tokens brand.css already uses,
        // incl. a computed gold-gradient replacement. Default (unset) => gold.
        var accent = normHex(s.theme_accent);
        // Default gold is a no-op so the hand-tuned gradient/tints are untouched.
        if (accent && accent.toLowerCase() !== '#f5c518') {
            var grad = 'linear-gradient(180deg,' + shade(accent, 18) + ' 0%,' + shade(accent, -8) + ' 100%)';
            injectStyle('brandAccentVars',
                ':root{--brand-accent:' + accent + ';--brand-accent-rgb:' + rgbList(accent) + ';--gold-grad:' + grad + ';}');
        }

        // Font pairing — loads the chosen Google Fonts and repoints the type vars.
        var font = (s.font_family || '').trim().toLowerCase();
        if (font && FONT_PRESETS[font]) {
            var F = FONT_PRESETS[font];
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = F.url;
            document.head.appendChild(link);
            injectStyle('brandFontVars',
                ":root{--brand-font-display:" + F.d + ",'Arial Narrow',sans-serif;" +
                "--brand-font-body:" + F.b + ",-apple-system,'Segoe UI',Roboto,sans-serif;}");
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
