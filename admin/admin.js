/**
 * AI Pilot admin connection flow.
 * Generates a one-time code, opens authentication, polls WordPress for
 * completion and closes the popup as soon as the code is consumed.
 */
(function () {
    'use strict';

    if (typeof AIPilotAdmin === 'undefined') {
        return;
    }

    var connectButton = document.getElementById('aipilot-connect-btn');
    var progress = document.getElementById('aipilot-connect-progress');
    var statusText = document.getElementById('aipilot-connect-status');
    var helpText = document.getElementById('aipilot-connect-help');
    var codeRow = document.getElementById('aipilot-code-row');
    var codeText = document.getElementById('aipilot-connect-code');
    var countdownText = document.getElementById('aipilot-connect-countdown');
    var reopenLink = document.getElementById('aipilot-reopen-popup');

    if (!connectButton) {
        return;
    }

    var popup = null;
    var authUrl = '';
    var currentCode = '';
    var pollTimer = null;
    var countdownTimer = null;
    var secondsLeft = 300;
    var finished = false;

    function request(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({
            'Accept': 'application/json',
            'X-WP-Nonce': AIPilotAdmin.restNonce
        }, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var message = body && body.message ? body.message : 'HTTP ' + response.status;
                    throw new Error(message);
                }
                return body;
            });
        });
    }

    function setBusy(busy) {
        connectButton.disabled = busy;
        connectButton.classList.toggle('is-loading', busy);
    }

    function showProgress() {
        if (progress) {
            progress.hidden = false;
            progress.classList.remove('is-error', 'is-success');
        }
    }

    function setStatus(title, description) {
        if (statusText) statusText.textContent = title;
        if (helpText) helpText.textContent = description || '';
    }

    function openAuthWindow() {
        if (!authUrl) return null;
        var win = window.open(authUrl, 'aipilot-auth', 'width=520,height=720,scrollbars=yes,resizable=yes');
        if (win && typeof win.focus === 'function') win.focus();
        return win;
    }

    function stopTimers() {
        if (pollTimer) window.clearInterval(pollTimer);
        if (countdownTimer) window.clearInterval(countdownTimer);
        pollTimer = null;
        countdownTimer = null;
    }

    function finishConnection(data) {
        if (finished) return;
        finished = true;
        stopTimers();
        if (popup && !popup.closed) {
            try { popup.close(); } catch (e) { /* cross-origin window opened by us */ }
        }
        if (progress) progress.classList.add('is-success');
        setStatus(AIPilotAdmin.strings.connected, data && data.site_url ? data.site_url : AIPilotAdmin.siteUrl);
        if (reopenLink) reopenLink.hidden = true;
        window.setTimeout(function () {
            window.location.href = AIPilotAdmin.returnUrl;
        }, 700);
    }

    function failConnection(message) {
        stopTimers();
        setBusy(false);
        if (progress) progress.classList.add('is-error');
        setStatus(message || AIPilotAdmin.strings.genericError, AIPilotAdmin.strings.expired);
        if (reopenLink) reopenLink.hidden = true;
    }

    function checkStatus() {
        if (!currentCode || finished) return;
        var url = AIPilotAdmin.statusUrl + '?code=' + encodeURIComponent(currentCode);
        request(url, { method: 'GET' })
            .then(function (data) {
                if (data && data.connected) {
                    finishConnection(data);
                    return;
                }
                if (data && data.expired) {
                    failConnection(AIPilotAdmin.strings.expired);
                }
            })
            .catch(function () {
                // A temporary polling failure must not abort the connection window.
            });
    }

    function startPolling() {
        secondsLeft = 300;
        if (countdownText) countdownText.textContent = String(secondsLeft);
        pollTimer = window.setInterval(checkStatus, 1500);
        countdownTimer = window.setInterval(function () {
            secondsLeft -= 1;
            if (countdownText) countdownText.textContent = String(Math.max(0, secondsLeft));
            if (secondsLeft <= 0) {
                failConnection(AIPilotAdmin.strings.expired);
            }
            if (popup && popup.closed && reopenLink && !finished) {
                reopenLink.hidden = false;
            }
        }, 1000);
    }

    connectButton.addEventListener('click', function (event) {
        event.preventDefault();
        finished = false;
        currentCode = '';
        authUrl = '';
        stopTimers();
        setBusy(true);
        showProgress();
        setStatus(AIPilotAdmin.strings.preparing, AIPilotAdmin.strings.waiting);
        if (codeRow) codeRow.hidden = true;
        if (reopenLink) reopenLink.hidden = true;

        // Open immediately to avoid popup blockers, then navigate after code creation.
        popup = window.open('about:blank', 'aipilot-auth', 'width=520,height=720,scrollbars=yes,resizable=yes');
        if (popup) {
            try {
                popup.document.write('<!doctype html><title>AI Pilot</title><style>body{font-family:system-ui;display:grid;place-items:center;min-height:90vh;color:#4f46e5}div{text-align:center}i{display:block;width:32px;height:32px;margin:0 auto 16px;border:3px solid #ddd;border-top-color:#4f46e5;border-radius:50%;animation:s 1s linear infinite}@keyframes s{to{transform:rotate(360deg)}}</style><div><i></i>Открываем AI Pilot…</div>');
            } catch (e) { /* Ignore */ }
        }

        request(AIPilotAdmin.connectCodeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{}'
        })
            .then(function (data) {
                if (!data || !data.code || !data.connect_url) {
                    throw new Error('Invalid connect response');
                }

                currentCode = data.code;
                authUrl = data.connect_url
                    + '&site=' + encodeURIComponent(AIPilotAdmin.siteUrl)
                    + '&return_url=' + encodeURIComponent(AIPilotAdmin.returnUrl);

                if (codeText) codeText.textContent = currentCode;
                if (codeRow) codeRow.hidden = false;
                setStatus(AIPilotAdmin.strings.waiting, 'Код будет подтверждён автоматически после входа.');

                if (popup && !popup.closed) {
                    popup.location.href = authUrl;
                } else {
                    popup = openAuthWindow();
                    if (!popup && reopenLink) {
                        reopenLink.hidden = false;
                        setStatus(AIPilotAdmin.strings.popupBlocked, AIPilotAdmin.strings.reopen);
                    }
                }

                startPolling();
                checkStatus();
            })
            .catch(function (error) {
                if (popup && !popup.closed) popup.close();
                console.error('AI Pilot connect failed:', error);
                failConnection(AIPilotAdmin.strings.genericError);
            });
    });

    if (reopenLink) {
        reopenLink.addEventListener('click', function (event) {
            event.preventDefault();
            popup = openAuthWindow();
            if (popup) reopenLink.hidden = true;
        });
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== 'https://chat.pilotsite.ru') return;
        if (event.data && (event.data.type === 'aipilot-connected' || event.data.type === 'aipilot:connected')) {
            checkStatus();
        }
    });
}());
