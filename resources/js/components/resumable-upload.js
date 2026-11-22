export default function resumableUpload(options) {
    return {
        file: null, title: '', session: null, fingerprint: null, busy: false, paused: true,
        error: '', message: '', completedUrl: '', controller: null,
        get storageKey() { return `homereel.upload.${options.account}`; },
        get percentage() { return this.session ? Math.floor(this.session.offset / this.session.size * 100) : 0; },
        get fileLabel() { return this.file ? `${this.file.name} · ${this.formatSize(this.file.size)}` : (this.session ? `${this.session.filename} · ${this.formatSize(this.session.size)}` : 'No file selected'); },
        formatSize(bytes) { if (bytes < 1048576) return `${(bytes / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 })} KiB`;
            return `${(bytes / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 })} MiB`; },
        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey));
                if (saved?.session?.id) {
                    this.session = saved.session; this.fingerprint = saved.fingerprint; this.title = saved.title;
                    this.message = 'Reselect the same file to resume. Your confirmed progress is stored on the server.';
                }
            } catch { this.message = 'Browser resume storage is unavailable. Keep this page open while uploading.'; }
        },
        remember() {
            try { localStorage.setItem(this.storageKey, JSON.stringify({ session: this.session, fingerprint: this.fingerprint, title: this.title })); }
            catch { this.message = 'Upload can continue, but resume after closing this page is unavailable.'; }
        },
        async selectFile(event) {
            this.error = ''; const file = event.target.files[0]; if (!file) return;
            this.busy = true;
            try {
                if (file.size > options.maxBytes || !file.size) throw new Error('Select a non-empty video within the upload size limit.');
                const sample = new Blob([file.slice(0, 65536), file.slice(Math.max(0, file.size - 65536)), `${file.name}:${file.size}:${file.lastModified}`]);
                const fingerprint = await this.digest(sample);
                if (this.session && fingerprint !== this.fingerprint) throw new Error('This file does not match the saved upload. Select the original file, or choose “Start a different upload”.');
                this.file = file; this.fingerprint = fingerprint;
                if (!this.session) this.title = file.name.replace(/\.[^.]+$/, '');
                this.message = this.session ? 'File matched. Resume when ready.' : 'Ready to upload.';
            } catch (error) { this.error = error.message; this.file = null; event.target.value = ''; }
            finally { this.busy = false; }
        },
        async digest(blob) {
            const hash = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
            return [...new Uint8Array(hash)].map(byte => byte.toString(16).padStart(2, '0')).join('');
        },
        async request(url, method = 'GET', body = null, extraHeaders = {}) {
            this.controller = new AbortController();
            const signal = this.controller.signal;
            let throttled = false;
            while (true) {
                const response = await fetch(url, {
                    method, body, credentials: 'same-origin', signal,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': options.csrf, ...extraHeaders },
                });
                const result = await response.json().catch(() => ({}));
                if (response.status === 429) {
                    const retryAfter = response.headers.get('Retry-After');
                    const seconds = retryAfter && /^\d+$/.test(retryAfter)
                        ? Number(retryAfter)
                        : Math.ceil((Date.parse(retryAfter) - Date.now()) / 1000);
                    const delay = Number.isFinite(seconds) ? Math.max(1, seconds) : 60;
                    this.message = `Upload rate limit reached. Retrying automatically in ${delay} seconds. You can still pause.`;
                    throttled = true;
                    await this.waitForRetry(delay * 1000, signal);
                    continue;
                }
                if (!response.ok) throw new Error(response.status === 419 ? 'Your session expired. Sign in again, then reselect this file to resume.' : result.message || `Request failed (${response.status}). Pause and retry.`);
                if (throttled) this.message = 'Upload resumed.';
                return result;
            }
        },
        waitForRetry(milliseconds, signal) {
            return new Promise((resolve, reject) => {
                const abort = () => {
                    clearTimeout(timer);
                    signal.removeEventListener('abort', abort);
                    reject(new DOMException('Upload paused.', 'AbortError'));
                };
                const timer = setTimeout(() => {
                    signal.removeEventListener('abort', abort);
                    resolve();
                }, milliseconds);
                signal.addEventListener('abort', abort, { once: true });
                if (signal.aborted) abort();
            });
        },
        async start() {
            if (!this.file || this.busy) return;
            this.busy = true; this.paused = false; this.error = '';
            try {
                if (!this.session) {
                    const types = { mp4: 'video/mp4', m4v: 'video/mp4', mov: 'video/quicktime', webm: 'video/webm', mkv: 'video/x-matroska' };
                    this.session = await this.request(options.createUrl, 'POST', JSON.stringify({
                        filename: this.file.name, title: this.title, size: this.file.size,
                        mime_type: types[this.file.name.split('.').pop().toLowerCase()] || this.file.type,
                    }), { 'Content-Type': 'application/json' });
                    this.remember();
                }
                const url = `${options.createUrl}/${encodeURIComponent(this.session.id)}`;
                this.session = await this.request(url); this.remember();
                if (this.session.status === 'expired' || new Date(this.session.expires_at) <= new Date()) throw new Error('This upload expired. Start a different upload and select the file again.');
                while (!this.paused && this.session.offset < this.file.size) {
                    const chunk = this.file.slice(this.session.offset, this.session.offset + this.session.chunk_bytes);
                    const checksum = await this.digest(chunk);
                    if (this.paused) break;
                    this.session = await this.request(url, 'PATCH', chunk, {
                        'Content-Type': 'application/octet-stream', 'Upload-Offset': String(this.session.offset), 'Upload-Checksum': checksum,
                    });
                    this.remember();
                }
                if (!this.paused && this.session.offset === this.file.size) {
                    this.message = 'Upload received. Finalizing…';
                    const result = await this.request(`${url}/finish`, 'POST');
                    this.completedUrl = result.video_url; this.message = 'Upload complete. Video inspection is queued.';
                    try { localStorage.removeItem(this.storageKey); } catch { /* Upload is safely complete. */ }
                } else this.message = 'Paused. Resume to continue from the confirmed offset.';
            } catch (error) {
                this.paused = true;
                if (error.name !== 'AbortError') this.error = `${error.message} Resume will first check the server’s confirmed offset.`;
                else this.message = 'Paused. The next resume will check which bytes the server received.';
            } finally { this.busy = false; this.controller = null; }
        },
        pause() { this.paused = true; this.controller?.abort(); },
        reset() {
            if (this.busy) return;
            try { localStorage.removeItem(this.storageKey); } catch { /* Optional browser persistence. */ }
            this.session = null; this.file = null; this.fingerprint = null; this.completedUrl = ''; this.error = ''; this.title = '';
            this.message = 'Select a video. The abandoned upload will be removed after expiry.';
            this.$refs.file.value = '';
        },
    };
}
