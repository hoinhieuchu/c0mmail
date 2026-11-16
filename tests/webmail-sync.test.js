const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/webmail-sync.js', 'utf8');

function harness(fetchImpl, visibilityState = 'visible') {
    const timers = [];
    const documentHandlers = {};
    const windowHandlers = {};
    const calls = [];
    const panel = { dataset: { mailboxId: '7', folder: 'INBOX', uidValidity: '123', latestUid: '88', query: '' } };
    const notice = {
        hidden: true,
        children: [],
        replaceChildren(...children) { this.children = children; }
    };
    const document = {
        visibilityState,
        querySelector(selector) {
            if (selector === '[data-webmail-sync]') return panel;
            if (selector === '[data-sync-notice]') return notice;
            return null;
        },
        addEventListener(name, callback) { documentHandlers[name] = callback; },
        createElement(tag) { return { tag, href: '', textContent: '' }; },
        createTextNode(text) { return { textContent: text }; }
    };
    const window = {
        location: { href: 'https://mail.example.test/mail?folder=INBOX' },
        addEventListener(name, callback) { windowHandlers[name] = callback; },
        setTimeout(callback, delay) {
            const timer = { callback, delay, cleared: false };
            timers.push(timer);
            return timer;
        },
        clearTimeout(timer) { if (timer) timer.cleared = true; }
    };
    const context = {
        document, window, navigator: { onLine: true },
        URLSearchParams,
        fetch: (...args) => { calls.push(args); return fetchImpl(...args); }
    };
    vm.runInNewContext(source, context);
    const flush = () => new Promise(resolve => setImmediate(resolve));
    const nextTimer = () => {
        const timer = [...timers].reverse().find(item => !item.cleared);
        assert.ok(timer, 'expected a scheduled poll');
        timer.cleared = true;
        timer.callback();
        return timer.delay;
    };
    return { timers, calls, panel, notice, document, window, documentHandlers, windowHandlers, flush, nextTimer };
}

test('polling shows one refresh link and replaces the notice on later updates', async () => {
    let count = 0;
    const app = harness(async () => ({
        ok: true, status: 200,
        json: async () => ({ new_count: ++count, has_more: false })
    }));
    await app.flush();
    assert.equal(app.notice.hidden, false);
    assert.equal(app.notice.children.length, 2);
    assert.match(app.notice.children[0].textContent, /1 new message is available/);
    assert.equal(app.notice.children[1].href, 'https://mail.example.test/mail?folder=INBOX');
    assert.equal(app.nextTimer(), 90000);
    await app.flush();
    assert.equal(app.notice.children.length, 2);
    assert.match(app.notice.children[0].textContent, /2 new messages are available/);
    assert.equal(app.calls.length, 2);
    assert.match(app.calls[0][0], /mailbox=7/);
});

test('transient failures use exponential backoff', async () => {
    const app = harness(async () => { throw new Error('temporary network failure'); });
    await app.flush();
    assert.equal(app.nextTimer(), 180000);
});

test('HTTP 429 honours Retry-After', async () => {
    const app = harness(async () => ({
        ok: false, status: 429,
        headers: { get: name => name === 'Retry-After' ? '120' : null }
    }));
    await app.flush();
    assert.equal(app.nextTimer(), 120000);
});

test('HTTP-date Retry-After is supported', async () => {
    const retryAt = new Date(Date.now() + 60000).toUTCString();
    const app = harness(async () => ({
        ok: false, status: 429,
        headers: { get: name => name === 'Retry-After' ? retryAt : null }
    }));
    await app.flush();
    const delay = app.nextTimer();
    assert.ok(delay >= 59000 && delay <= 60000);
});

test('hidden tabs do not poll until they become visible', async () => {
    const app = harness(async () => ({
        ok: true, status: 200, json: async () => ({ new_count: 0 })
    }), 'hidden');
    await app.flush();
    assert.equal(app.calls.length, 0);
    app.document.visibilityState = 'visible';
    app.documentHandlers.visibilitychange();
    assert.equal(app.nextTimer(), 0);
    await app.flush();
    assert.equal(app.calls.length, 1);
});
