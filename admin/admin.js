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
            .then(function (r) {
                // Check HTTP status — 4xx/5xx should go to catch
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                // Only use response if code is a valid 8-char connect code
                if (data && data.code && data.code.length === 8 && data.connect_url) {
                    var url = data.connect_url +
                        '&site=' + encodeURIComponent(AIPilotAdmin.siteUrl) +
                        '&redirect=' + encodeURIComponent(window.location.href + '&connected=1');
                    window.open(url, 'aipilot-auth',
                        'width=480,height=640,scrollbars=yes');
                } else {
                    throw new Error('Invalid code response');
                }
            })
            .catch(function () {
                // Fallback: direct connect (user must log in manually)
                var url = AIPilotAdmin.connectUrl +
                    '?site=' + encodeURIComponent(AIPilotAdmin.siteUrl) +
                    '&redirect=' + encodeURIComponent(window.location.href + '&connected=1');
                window.open(url, 'aipilot-auth',
                    'width=480,height=640,scrollbars=yes');
            });
    });
})();
