/**
 * wp2static.js — keeps the static export behaving like the live site.
 *
 * Loaded only on exported pages (a data-wp2static-origin attribute pins the
 * script to the origin host). On the live site itself it is a no-op.
 *
 * Duties:
 *   1. Rewrite any absolute links that still point at the origin to local
 *      .html files.
 *   2. Point formless-action / relative-action forms at the live PHP
 *      endpoints so submissions keep working.
 */
(function () {
    'use strict';

    var origin = '';
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
        var host = scripts[i].getAttribute('data-wp2static-origin');
        if (host) { origin = host; break; }
    }
    if (!origin) { return; }
    origin = origin.replace(/\/+$/, '');

    var liveHost = '';
    try { liveHost = new URL(origin).hostname.toLowerCase(); } catch (e) { return; }
    if (window.location.hostname.toLowerCase() === liveHost) { return; }

    function localPath(abs) {
        var u = new URL(abs);
        var p = u.pathname.replace(/\/+$/, '');
        if (p === '' || u.pathname === '/') { return 'index.html'; }
        return p + '/index.html';
    }

    function absUrl(href) {
        try { return new URL(href, window.location.href); } catch (e) { return null; }
    }

    var links = document.querySelectorAll('a[href]');
    for (var j = 0; j < links.length; j++) {
        var u = absUrl(links[j].getAttribute('href'));
        if (u && u.hostname.toLowerCase() === liveHost) {
            links[j].setAttribute('href', localPath(u.href));
        }
    }

    var forms = document.querySelectorAll('form');
    for (var k = 0; k < forms.length; k++) {
        var f = forms[k];
        var act = (f.getAttribute('action') || '').trim();
        if (act === '') {
            var here = absUrl(window.location.href);
            var pth = here ? here.pathname.split('?')[0] : '/';
            f.setAttribute('action', origin + pth);
        } else if (act.charAt(0) === '/') {
            f.setAttribute('action', origin + act);
        } else if (!/^(https?:)?\/\//i.test(act)) {
            f.setAttribute('action', origin + '/' + act);
        }
    }
})();