import test from 'node:test';
import assert from 'node:assert/strict';
import videoPlayer from '../../resources/js/components/video-player.js';

function mobilePlayer(t, { landscape = false, paused = false, safari = false, rejected = false } = {}) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    const orientation = Object.assign(new EventTarget(), { matches: landscape });
    const mobile = Object.assign(new EventTarget(), { matches: true });
    const page = Object.assign(new EventTarget(), { fullscreenElement: null, fullscreenEnabled: !safari, visibilityState: 'visible' });
    const video = Object.assign(new EventTarget(), { paused, ended: false, readyState: 2, error: null, webkitDisplayingFullscreen: false });
    const state = { requests: 0, exits: 0, rejected };
    globalThis.window = { matchMedia: query => query.includes('orientation') ? orientation : mobile };
    globalThis.document = page;
    if (safari) {
        video.webkitEnterFullscreen = () => {
            state.requests++;
            if (state.rejected) throw new Error('User gesture required');
            video.webkitDisplayingFullscreen = true;
            video.dispatchEvent(new Event('webkitbeginfullscreen'));
        };
        video.webkitExitFullscreen = () => {
            state.exits++;
            video.webkitDisplayingFullscreen = false;
            video.dispatchEvent(new Event('webkitendfullscreen'));
        };
    } else {
        video.requestFullscreen = async () => {
            state.requests++;
            if (state.rejected) throw new Error('User gesture required');
            page.fullscreenElement = video;
            page.dispatchEvent(new Event('fullscreenchange'));
        };
        page.exitFullscreen = async () => {
            state.exits++;
            page.fullscreenElement = null;
            page.dispatchEvent(new Event('fullscreenchange'));
        };
    }
    const instance = videoPlayer();
    instance.$refs = { video };
    instance.init();
    t.after(() => {
        instance.destroy();
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
    });
    const rotate = async (value) => {
        orientation.matches = value;
        orientation.dispatchEvent(new Event('change'));
        await new Promise(resolve => setImmediate(resolve));
    };
    return { instance, video, page, state, mobile, rotate };
}

test('a playing phone video enters fullscreen on landscape and returns inline on portrait', async (t) => {
    const { instance, state, rotate } = mobilePlayer(t);
    await rotate(true);
    assert.equal(state.requests, 1);
    assert.equal(instance.fullscreen, true);
    assert.equal(instance.automaticFullscreen, true);
    await rotate(false);
    assert.equal(state.exits, 1);
    assert.equal(instance.fullscreen, false);
});

test('paused, ended, hidden and non-mobile players do not automatically enter fullscreen', async (t) => {
    const { instance, video, page, mobile, state, rotate } = mobilePlayer(t, { paused: true });
    await rotate(true);
    video.paused = false;
    page.visibilityState = 'hidden';
    await instance.handleOrientationChange();
    page.visibilityState = 'visible';
    mobile.matches = false;
    await instance.handleOrientationChange();
    mobile.matches = true;
    video.ended = true;
    await instance.handleOrientationChange();
    assert.equal(state.requests, 0);
    video.ended = false;
    await instance.handleOrientationChange();
    assert.equal(state.requests, 1);
});

test('a rejected request offers a tap fallback without interrupting playback or retrying on buffering', async (t) => {
    const { instance, video, state, rotate } = mobilePlayer(t, { rejected: true });
    await rotate(true);
    assert.equal(instance.fullscreenNeedsTap, true);
    assert.equal(instance.offerFullscreen, true);
    assert.equal(video.paused, false);
    assert.equal(instance.failed, false);
    await instance.handleOrientationChange();
    assert.equal(state.requests, 1);
    state.rejected = false;
    await instance.enterFullscreen();
    assert.equal(instance.fullscreen, true);
    assert.equal(instance.automaticFullscreen, false);
    await rotate(false);
    assert.equal(state.exits, 0);
});

test('manually leaving fullscreen is respected until the next landscape rotation', async (t) => {
    const { instance, page, state, rotate } = mobilePlayer(t);
    await rotate(true);
    await page.exitFullscreen();
    await instance.handleOrientationChange();
    assert.equal(state.requests, 1);
    assert.equal(instance.fullscreen, false);
    await rotate(false);
    await rotate(true);
    assert.equal(state.requests, 2);
});

test('Safari native video fullscreen uses its supported entry and exit methods', async (t) => {
    const { instance, state, rotate } = mobilePlayer(t, { safari: true });
    await rotate(true);
    assert.equal(state.requests, 1);
    assert.equal(instance.fullscreen, true);
    await rotate(false);
    assert.equal(state.exits, 1);
    assert.equal(instance.fullscreen, false);
});

test('opening a page in landscape waits for playback before requesting fullscreen', async (t) => {
    const { instance, state } = mobilePlayer(t, { landscape: true });
    assert.equal(state.requests, 0);
    await instance.handleOrientationChange();
    assert.equal(state.requests, 1);
});

test('rotation and fullscreen listeners are removed when the player is destroyed', async (t) => {
    const { instance, page, video, state, rotate } = mobilePlayer(t);
    instance.destroy();
    await rotate(true);
    page.fullscreenElement = video;
    page.dispatchEvent(new Event('fullscreenchange'));
    assert.equal(state.requests, 0);
    assert.equal(instance.fullscreen, false);
});

test('another fullscreen element is never replaced by automatic video fullscreen', async (t) => {
    const { page, state, rotate } = mobilePlayer(t);
    page.fullscreenElement = {};
    await rotate(true);
    assert.equal(state.requests, 0);
});

test('rotating back before a fullscreen request completes still returns the player inline', async (t) => {
    const { instance, video, state, rotate } = mobilePlayer(t);
    const enter = video.requestFullscreen;
    let allow;
    video.requestFullscreen = () => new Promise(resolve => { allow = resolve; }).then(enter);
    await rotate(true);
    assert.equal(instance.fullscreenPending, true);
    await rotate(false);
    allow();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.exits, 1);
    assert.equal(instance.fullscreen, false);
});
