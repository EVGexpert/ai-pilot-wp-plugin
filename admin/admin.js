/**
 * AI Pilot – Admin UI
 *
 * Connect button handler with one-time code flow.
 */
(function () {
    'use strict';

    var btn = document.getElementById('aipilot-connect-btn');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
        e.preventDefault();

        // Generate one-time code via REST API
        fetch(AIPilotAdmin.restUrl, {
            method: 'POST',
            headers: { 'X-AI-Pilot-Token': AIPilotAdmin.token }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code) {
                    var url = data.connect_url +
                        '&site=' + encodeURIComponent(AIPilotAdmin.siteUrl);
                    window.open(url, 'aipilot-auth',
                        'width=480,height=640,scrollbars=yes');
                }
            })
            .catch(function () {
                // Fallback: direct connect
                var url = 'https://chat.pilotsite.ru/connect?site=' +
                    encodeURIComponent(AIPilotAdmin.siteUrl);
                window.open(url, 'aipilot-auth',
                    'width=480,height=640,scrollbars=yes');
            });
    });
})();
