import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(
    new URL('../../resources/views/app.blade.php', import.meta.url),
    'utf8',
);
const script = template.match(
    /<script id="picksports-tag-manager"[^\n]*>\n([\s\S]*?)<\/script>/,
)[1];

function simulateTracking({ waitForBoard = true, idleSupported = true } = {}) {
    const window = new EventTarget();
    const idle = [];
    const timers = new Map();
    const scripts = [];
    let timerId = 0;
    window.setTimeout = (callback, delay) => {
        timers.set(++timerId, { callback, delay });
        return timerId;
    };
    window.clearTimeout = (id) => timers.delete(id);
    if (idleSupported)
        window.requestIdleCallback = (callback) => idle.push(callback);

    const consent = ['consent', 'default', { analytics_storage: 'denied' }];
    const queue = [consent];
    window.dataLayer = queue;
    runInNewContext(script, {
        window,
        document: {
            currentScript: { dataset: { waitForBoard: String(waitForBoard) } },
            readyState: 'loading',
            createElement: () => ({}),
            head: { appendChild: (element) => scripts.push(element) },
        },
    });
    return {
        queue,
        consent,
        idle,
        timers,
        scripts,
        dispatch: (type) => window.dispatchEvent(new Event(type)),
        assertLoaded() {
            assert.equal(window.dataLayer, queue);
            assert.equal(scripts.length, 1);
            assert.equal(scripts[0].async, true);
            assert.equal(
                scripts[0].src,
                'https://www.googletagmanager.com/gtm.js?id=GTM-P5NFGS3P',
            );
            assert.equal(
                queue.filter((item) => item.event === 'gtm.js').length,
                1,
            );
        },
    };
}

test('signed-in board queues consent and page events until the board has settled', () => {
    const tracking = simulateTracking();
    const pageView = { event: 'page_view', page_path: '/cfb/predictions' };
    const update = ['consent', 'update', { analytics_storage: 'granted' }];
    tracking.queue.push(pageView, update);
    tracking.dispatch('load');
    assert.equal(tracking.scripts.length, 0);
    assert.equal(tracking.idle.length, 0);
    const deadline = [...tracking.timers.values()][0];
    assert.equal(deadline.delay, 1500);

    tracking.dispatch('picksports:board-settled');
    assert.equal(tracking.idle.length, 1);
    tracking.idle[0]();
    tracking.dispatch('pointerdown');
    deadline.callback();
    tracking.assertLoaded();
    assert.equal(tracking.timers.size, 0);
    assert.deepEqual(tracking.queue.slice(0, 3), [
        tracking.consent,
        pageView,
        update,
    ]);
});

test('the deadline and first interaction release the container when the board is slow', () => {
    for (const trigger of ['deadline', 'pointerdown', 'inertia:before']) {
        const tracking = simulateTracking({ idleSupported: false });
        tracking.dispatch('load');
        assert.equal(tracking.scripts.length, 0);
        if (trigger === 'deadline') [...tracking.timers.values()][0].callback();
        else tracking.dispatch(trigger);
        tracking.dispatch('picksports:board-settled');
        tracking.assertLoaded();
        assert.equal(tracking.timers.size, 0);
    }
});

test('early interaction and other routes retain the post-load scheduling boundary', () => {
    for (const waitForBoard of [true, false]) {
        const tracking = simulateTracking({ waitForBoard });
        tracking.dispatch('keydown');
        assert.equal(tracking.scripts.length, 0);
        assert.equal(tracking.idle.length, 0);
        tracking.dispatch('load');
        assert.equal(tracking.idle.length, 1);
        tracking.idle[0]();
        tracking.assertLoaded();
    }
});
