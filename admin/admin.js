/**
 * AI Pilot – Admin UI
 *
 * Connect button handler with one-time code flow.
 * Opens a popup to chat.pilotsite.ru/connect for site authorization.
 * After connecting, the popup shows success and stays on chat.pilotsite.ru.
 */
(function () {
    'use strict';

    var btn = document.getElementById('aipilot-connect-btn');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
        e.preventDefault();

        fetch(AIPilotAdmin.restUrl, {
            method: 'POST',
            headers: { 'X-AI-Pilot-Token': AIPilotAdmin.token }
        })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                if (data && data.code && data.code.length === 8 && data.connect_url) {
                    // Connect URL from plugin (chat.pilotsite.ru/connect?code=XXX)
                    var url = data.connect_url +
                        '&site=' + encodeURIComponent(AIPilotAdmin.siteUrl);
                    window.open(url, 'aipilot-auth',
                        'width=480,height=640,scrollbars=yes');
                } else {
                    throw new Error('Invalid code response');
                }
            })
            .catch(function () {
                // Fallback: direct connect page (no auto-redirect)
                var url = AIPilotAdmin.connectUrl +
                    '?site=' + encodeURIComponent(AIPilotAdmin.siteUrl);
                window.open(url, 'aipilot-auth',
                    'width=480,height=640,scrollbars=yes');
            });
    });
})();
