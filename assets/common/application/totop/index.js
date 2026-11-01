"use strict";

var ready = require('domready'),
    $     = require('../utils/dollar-extras');

ready(function() {
    var totop = $('#g-totop');
    if (!totop) { return; }

    totop.on('click', function(e) {
        e.preventDefault();
        var scroller = document.scrollingElement || document.documentElement,
            body = document.body,
            reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (body.scrollTop > 0 && body.scrollHeight > body.clientHeight) { scroller = body; }
        if (typeof scroller.scrollTo === 'function') {
            scroller.scrollTo({ top: 0, left: scroller.scrollLeft, behavior: reduced ? 'instant' : 'smooth' });
        } else {
            scroller.scrollTop = 0;
        }
    });
});

module.exports = {};
