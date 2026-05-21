/**
 * AI Pilot – Admin UI
 *
 * Connect button: generates one-time code → opens connect popup.
 * Вся магия на сервере (auth-api), плагин только передаёт код.
 */
(function () {
    'use strict';

    var btn = document.getElementById('aipilot-connect-btn');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
        e.preventDefault();

        fetch(AIPilotAdmin.restUrl, { method: 'POST' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data && data.code && data.code.length === 8 && data.connect_url) {
                    var url = data.connect_url +
                        '&site=' + encodeURIComponent(AIPilotAdmin.siteUrl);
                    window.open(url, 'aipilot-auth',
                        'width=480,height=640,scrollbars=yes');
                } else {
                    throw new Error('Invalid response');
                }
            })
            .catch(function (err) {
                console.error('AI Pilot connect failed:', err);
                alert('Ошибка подключения к AI Pilot. Попробуйте позже.');
            });
    });
})();
