(() => {
    const panel = document.querySelector('[data-webmail-sync]');
    const notice = document.querySelector('[data-sync-notice]');
    if (!panel || !notice) return;

    const NORMAL_INTERVAL = 90000;
    const MAX_BACKOFF = 15 * 60 * 1000;
    let checking = false;
    let timer = null;
    let failures = 0;
    let stopped = false;

    const schedule = (delay) => {
        if (stopped || document.visibilityState !== 'visible' || navigator.onLine === false) return;
        if (timer !== null) window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            timer = null;
            checkForUpdates();
        }, Math.max(0, delay));
    };

    const retryDelay = (response) => {
        if (response && response.status === 429) {
            const retryValue = response.headers.get('Retry-After') || '';
            const retryAfter = Number.parseInt(retryValue, 10);
            if (Number.isFinite(retryAfter) && retryAfter > 0) {
                return Math.min(MAX_BACKOFF, retryAfter * 1000);
            }
            const retryAt = Date.parse(retryValue);
            if (Number.isFinite(retryAt)) {
                return Math.min(MAX_BACKOFF, Math.max(1000, retryAt - Date.now()));
            }
        }
        failures = Math.min(failures + 1, 8);
        return Math.min(MAX_BACKOFF, NORMAL_INTERVAL * (2 ** failures));
    };

    const checkForUpdates = async () => {
        if (stopped || checking || document.visibilityState !== 'visible' || navigator.onLine === false) return;
        checking = true;
        const params = new URLSearchParams({
            mailbox: panel.dataset.mailboxId || '',
            folder: panel.dataset.folder || 'INBOX',
            uidvalidity: panel.dataset.uidValidity || '0',
            after: panel.dataset.latestUid || '0',
            q: panel.dataset.query || ''
        });

        let nextDelay = NORMAL_INTERVAL;
        try {
            const response = await fetch('/mail/updates?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (response.status === 429 || !response.ok) {
                nextDelay = retryDelay(response);
                return;
            }

            const result = await response.json();
            if (!result || typeof result !== 'object') throw new Error('Invalid update response.');
            failures = 0;

            let message = '';
            if (result.reset_required) {
                message = 'This folder changed on the mail server. Refresh to load the current folder state.';
            } else if (Number.isFinite(Number(result.new_count)) && Number(result.new_count) > 0) {
                const count = Math.max(0, Number(result.new_count));
                message = result.has_more
                    ? 'More than 20 new messages are available.'
                    : count + (count === 1 ? ' new message is' : ' new messages are') + ' available.';
            }

            if (message !== '') {
                const refresh = document.createElement('a');
                refresh.href = window.location.href;
                refresh.textContent = ' Refresh';
                notice.replaceChildren(document.createTextNode(message), refresh);
                notice.hidden = false;
            }
        } catch (_) {
            // Transient polling failures must never interrupt reading or composing mail.
            nextDelay = retryDelay(null);
        } finally {
            checking = false;
            schedule(nextDelay);
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            schedule(0);
        } else if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    });
    window.addEventListener('online', () => schedule(0));
    window.addEventListener('offline', () => {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
    });
    window.addEventListener('pagehide', () => {
        stopped = true;
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
    });

    checkForUpdates();
})();
