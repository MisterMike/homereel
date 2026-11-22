import test from 'node:test';
import assert from 'node:assert/strict';
import videoPlayer from '../../resources/js/components/video-player.js';

function player({ paused = false, rejectPlay = false } = {}) {
    const video = { currentTime: 137, duration: 300, playbackRate: 1.5, paused, ended: false, loads: 0, plays: 0,
        load() { this.loads++; this.currentTime = 0; },
        async play() { this.plays++; if (rejectPlay) throw new Error('Autoplay blocked'); },
    };
    const instance = videoPlayer({ sources: { optimized: '/private?quality=optimized', original: '/private?quality=original' }, initialQuality: 'optimized' });
    instance.$refs = { video };
    return { instance, video };
}

test('switching keeps position, playback speed and playing state', async () => {
    const { instance, video } = player();
    instance.switchQuality('original');
    assert.equal(video.src, '/private?quality=original');
    await instance.restorePlayback();
    assert.equal(video.currentTime, 137);
    assert.equal(video.playbackRate, 1.5);
    assert.equal(video.plays, 1);
});

test('a paused video remains paused and rapid switches keep the original position', async () => {
    const { instance, video } = player({ paused: true });
    instance.switchQuality('original');
    instance.switchQuality('optimized');
    await instance.restorePlayback();
    assert.equal(video.currentTime, 137);
    assert.equal(video.plays, 0);
    assert.equal(instance.quality, 'optimized');
});

test('missing and current variants do not reload; end position is clamped', async () => {
    const { instance, video } = player();
    instance.switchQuality('missing');
    instance.switchQuality('optimized');
    assert.equal(video.loads, 0);
    instance.switchQuality('original');
    video.duration = 130;
    await instance.restorePlayback();
    assert.equal(video.currentTime, 129.9);
});

test('autoplay denial gives a recovery message without marking the stream as failed', async () => {
    const { instance } = player({ rejectPlay: true });
    instance.switchQuality('original');
    await instance.restorePlayback();
    assert.match(instance.playbackMessage, /Press play/);
    assert.equal(instance.failed, false);
});
