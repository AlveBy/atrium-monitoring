/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */
/*
 * Web Vitals of real visitors, one beacon per page view. No cookies, no
 * storage, nothing about the person: the page address without its query,
 * the kind of device by screen width and the connection type. Inlined into
 * the page by RumScript, which strips these comments; it must stay under
 * 2 KB (RumScriptTest checks).
 */
(function (w, d, n) {
    var s = d.currentScript, e = s && s.dataset.endpoint, r = +(s && s.dataset.rate);
    if (!e || !w.PerformanceObserver || !n.sendBeacon || n.doNotTrack == '1' || w.doNotTrack == '1' || n.globalPrivacyControl || !(Math.random() < r)) return;
    var v = {}, sent = 0, win = 0, first = 0, last = 0, inp = {};
    function watch(type, cb, extra) {
        try {
            new PerformanceObserver(function (l) { l.getEntries().forEach(cb) }).observe(Object.assign({ type: type, buffered: true }, extra));
        } catch (x) {}
    }
    watch('paint', function (x) { if (x.name == 'first-contentful-paint') v.fcp = x.startTime });
    watch('largest-contentful-paint', function (x) { v.lcp = x.startTime });
    // CLS: the largest burst of shifts (gaps under 1 s, at most 5 s long).
    watch('layout-shift', function (x) {
        if (x.hadRecentInput) return;
        if (x.startTime - last > 1000 || x.startTime - first > 5000) { win = 0; first = x.startTime }
        win += x.value; last = x.startTime;
        if (!(v.cls >= win)) v.cls = win;
    });
    // INP: the slowest interaction, one in fifty forgiven on busy pages.
    watch('event', function (x) { if (x.interactionId) inp[x.interactionId] = Math.max(inp[x.interactionId] || 0, x.duration) }, { durationThreshold: 40 });
    var nav = performance.getEntriesByType('navigation')[0];
    if (nav) v.ttfb = Math.max(0, nav.responseStart - (nav.activationStart || 0));
    function send() {
        if (sent) return;
        sent = 1;
        var a = Object.keys(inp).map(function (k) { return inp[k] }).sort(function (x, y) { return y - x });
        if (a.length) v.inp = a[Math.min(a.length - 1, Math.floor(a.length / 50))];
        if (!Object.keys(v).length) return;
        for (var k in v) v[k] = k == 'cls' ? Math.round(v[k] * 1e4) / 1e4 : Math.round(v[k] * 10) / 10;
        var c = n.connection && n.connection.effectiveType, x = w.innerWidth, t = w.matchMedia && w.matchMedia('(pointer:coarse)').matches;
        n.sendBeacon(e, JSON.stringify({ p: location.pathname, d: x < 768 ? 'mobile' : t && x < 1280 ? 'tablet' : 'desktop', c: ['slow-2g', '2g', '3g', '4g'].indexOf(c) < 0 ? 'unknown' : c, v: v }));
    }
    d.addEventListener('visibilitychange', function () { if (d.visibilityState == 'hidden') send() });
    w.addEventListener('pagehide', send);
})(window, document, navigator);
