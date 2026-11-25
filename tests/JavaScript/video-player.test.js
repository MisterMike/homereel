import test from 'node:test';
import assert from 'node:assert/strict';
import videoPlayer from '../../resources/js/components/video-player.js';

function player({ paused = false, rejectPlay = false } = {}) {
    const video = { currentTime: 137, duration: 300, playbackRate: 1.5, paused, ended: false, loads: 0, plays: 0,
        load() { this.loads++; this.currentTime = 0; },
        async play() { this.plays++; if (rejectPlay) throw new Error('Autoplay blocked'); },
    };
    const instance = videoPlayer({ sources: { standard: '/private?quality=standard', hd: '/private?quality=hd' }, initialQuality: 'standard' });
    instance.$refs = { video };
    return { instance, video };
}

test('initial loading ends when metadata is cached without fetching a playable frame', () => {
    const { instance, video } = player({ paused: true });
    video.readyState = 0;
    instance.init();
    assert.equal(instance.loading, true);

    video.readyState = 1;
    instance.init();
    assert.equal(instance.loading, false);
    assert.equal(video.plays, 0);
});

test('metadata arriving before play clears the loading indicator', async () => {
    const { instance, video } = player({ paused: true });
    instance.loading = true;

    await instance.restorePlayback();

    assert.equal(instance.loading, false);
    assert.equal(video.plays, 0);
    assert.equal(video.loads, 0);
});

test('switching keeps position, playback speed and playing state', async () => {
    const { instance, video } = player();
    instance.switchQuality('hd');
    assert.equal(video.src, '/private?quality=hd');
    await instance.restorePlayback();
    assert.equal(video.currentTime, 137);
    assert.equal(video.playbackRate, 1.5);
    assert.equal(video.plays, 1);
});

test('a paused video remains paused and rapid switches keep the original position', async () => {
    const { instance, video } = player({ paused: true });
    instance.switchQuality('hd');
    instance.switchQuality('standard');
    await instance.restorePlayback();
    assert.equal(video.currentTime, 137);
    assert.equal(video.plays, 0);
    assert.equal(instance.quality, 'standard');
    assert.equal(instance.loading, false);
});

test('missing and current variants do not reload; end position is clamped', async () => {
    const { instance, video } = player();
    instance.switchQuality('missing');
    instance.switchQuality('standard');
    assert.equal(video.loads, 0);
    instance.switchQuality('hd');
    video.duration = 130;
    await instance.restorePlayback();
    assert.equal(video.currentTime, 129.9);
});

test('autoplay denial gives a recovery message without marking the stream as failed', async () => {
    const { instance } = player({ rejectPlay: true });
    instance.switchQuality('hd');
    await instance.restorePlayback();
    assert.match(instance.playbackMessage, /Drücke auf Wiedergabe/);
    assert.equal(instance.failed, false);
});

test('playing records one view and updates the displayed count across repeated events and quality switches', async (t) => {
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    const instance = videoPlayer({ viewUrl: '/videos/clip/views', videoId: 'clip', csrf: 'test-token', sources: { standard: '/standard', hd: '/hd' }, initialQuality: 'standard' });
    instance.$refs = { video: { currentTime: 0, paused: false, ended: false, playbackRate: 1, load() {} } };
    const events = [];
    instance.$dispatch = (name, detail) => events.push({ name, detail });
    let requests = 0;
    globalThis.fetch = async (url, options) => {
        requests++;
        assert.equal(url, '/videos/clip/views');
        assert.equal(options.method, 'POST');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'test-token');
        return Response.json({ view_count: 42 });
    };

    assert.equal(requests, 0);
    await Promise.all([instance.recordView(), instance.recordView()]);
    instance.switchQuality('hd');
    await instance.recordView();

    assert.equal(requests, 1);
    assert.deepEqual(events, [{ name: 'video-view-recorded', detail: { videoId: 'clip', count: 42 } }]);
});

test('failed view requests leave playback alone and can retry on a later playing event', async (t) => {
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    const instance = videoPlayer({ viewUrl: '/videos/clip/views' });
    instance.failed = false;
    instance.loading = false;
    instance.$dispatch = () => {};
    let requests = 0;
    globalThis.fetch = async () => {
        requests++;
        if (requests === 1) throw new Error('Network interrupted');
        if (requests === 2) return new Response('', { status: 503 });
        return Response.json({ view_count: 1 });
    };

    await instance.recordView();
    assert.equal(instance.viewRecorded, false);
    await instance.recordView();
    assert.equal(instance.viewRecorded, false);
    await instance.recordView();
    assert.equal(instance.viewRecorded, true);
    assert.equal(instance.failed, false);
    assert.equal(instance.loading, false);
});
