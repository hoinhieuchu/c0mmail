(() => {
    const panel = document.querySelector('[data-webmail-sync]');
    const notice = document.querySelector('[data-sync-notice]');
    if (!panel || !notice) return;

    let checking = false;
    const checkForUpdates = async () => {
        if (checking || document.visibilityState !== 'visible') return;
        checking = true;
        const params = new URLSearchParams({
            mailbox: panel.dataset.mailboxId || '',
            folder: panel.dataset.folder || 'INBOX',
            uidvalidity: panel.dataset.uidValidity || '0',
            after: panel.dataset.latestUid || '0',
            q: panel.dataset.query || ''
        });

        try {
            const response = await fetch('/mail/updates?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (response.status === 429) return;
            if (!response.ok) return;
            const result = await response.json();

            if (result.reset_required) {
                notice.textContent = 'This folder changed on the mail server. Refresh to load the current folder state.';
            } else if (result.new_count > 0) {
                notice.textContent = result.has_more
                    ? 'More than 20 new messages are available.'
                    : result.new_count + (result.new_count === 1 ? ' new message is' : ' new messages are') + ' available.';
            } else {
                return;
            }

            const refresh = document.createElement('a');
            refresh.href = window.location.href;
            refresh.textContent = ' Refresh';
            notice.appendChild(refresh);
            notice.hidden = false;
        } catch (_) {
            // A transient poll failure must not interrupt reading or composing mail.
        } finally {
            checking = false;
        }
    };

    window.setInterval(checkForUpdates, 90000);
    document.addEventListener('visibilitychange', checkForUpdates);
    checkForUpdates();
})();
