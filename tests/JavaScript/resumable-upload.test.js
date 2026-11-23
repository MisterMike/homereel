import assert from 'node:assert/strict';
import test from 'node:test';
import resumableUpload from '../../resources/js/components/resumable-upload.js';

const options = { account: 1, csrf: 'test-token', createUrl: '/uploads', maxBytes: 1024 };
const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), { status, headers });

test('a throttled chunk waits and retries at the same confirmed offset before finalizing', async (t) => {
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    const upload = resumableUpload(options);
    upload.file = new File(['abc'], 'clip.mp4');
    upload.session = { id: 'session', offset: 0, size: 3, chunk_bytes: 3, expires_at: '2099-01-01T00:00:00Z', status: 'active' };
    const delays = [];
    upload.waitForRetry = async (milliseconds) => { delays.push(milliseconds); };
    let patches = 0;
    globalThis.fetch = async (url, request) => {
        if (request.method === 'GET') return json(upload.session);
        if (request.method === 'PATCH') {
            assert.equal(request.headers['Upload-Offset'], '0');
            assert.equal(await request.body.text(), 'abc');
            if (++patches === 1) return json({ message: 'Too Many Attempts.' }, 429, { 'Retry-After': '7' });
            return json({ ...upload.session, offset: 3 });
        }
        assert.equal(url, '/uploads/session/finish');
        return json({ video_url: '/videos/clip', status: 'processing' });
    };

    await upload.start();

    assert.equal(patches, 2);
    assert.deepEqual(delays, [7000]);
    assert.equal(upload.session.offset, 3);
    assert.equal(upload.completedUrl, '/videos/clip');
    assert.equal(upload.error, '');
    assert.equal(upload.busy, false);
});

test('retry delay supports HTTP dates and defaults when Retry-After is absent', async (t) => {
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    for (const header of [null, new Date(Date.now() + 30000).toUTCString()]) {
        const upload = resumableUpload(options);
        let attempts = 0;
        let delay;
        upload.waitForRetry = async (milliseconds) => { delay = milliseconds; };
        globalThis.fetch = async () => ++attempts === 1
            ? json({}, 429, header ? { 'Retry-After': header } : {})
            : json({ offset: 8 });

        assert.deepEqual(await upload.request('/uploads/session'), { offset: 8 });
        if (header) assert.ok(delay >= 28000 && delay <= 30000);
        else assert.equal(delay, 60000);
    }
});

test('pause cancels a pending throttle wait without sending another request', async (t) => {
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    const upload = resumableUpload(options);
    let attempts = 0;
    globalThis.fetch = async () => {
        attempts++;
        return json({}, 429, { 'Retry-After': '60' });
    };
    const pending = upload.request('/uploads/session');
    await new Promise(resolve => setImmediate(resolve));
    assert.match(upload.message, /Retrying automatically/);

    upload.pause();

    await assert.rejects(pending, { name: 'AbortError' });
    assert.equal(attempts, 1);
});

test('validation errors remain visible and are not retried', async (t) => {
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    const upload = resumableUpload(options);
    let attempts = 0;
    globalThis.fetch = async () => {
        attempts++;
        return json({ message: 'Checksum mismatch.' }, 422);
    };

    await assert.rejects(upload.request('/uploads/session'), /Checksum mismatch/);
    assert.equal(attempts, 1);
});

test('uploading another video clears the completed upload and returns focus to file selection', () => {
    const upload = resumableUpload(options);
    upload.completedUrl = '/videos/clip';
    upload.session = { id: 'completed-session', offset: 3, size: 3 };
    upload.file = new File(['abc'], 'clip.mp4');
    upload.title = 'First video';
    let focused = false;
    upload.$refs = { file: { value: 'clip.mp4', focus() { focused = true; } } };
    upload.$nextTick = callback => callback();

    upload.reset();

    assert.equal(upload.completedUrl, '');
    assert.equal(upload.session, null);
    assert.equal(upload.file, null);
    assert.equal(upload.title, '');
    assert.equal(upload.$refs.file.value, '');
    assert.equal(upload.message, 'Select another video to upload.');
    assert.equal(focused, true);
});
