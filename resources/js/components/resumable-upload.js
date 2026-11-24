export default function resumableUpload(options) {
    return {
        file: null, title: '', session: null, fingerprint: null, busy: false, paused: true,
        error: '', message: '', completedUrl: '', controller: null,
        get storageKey() { return `homereel.upload.${options.account}`; },
        get percentage() { return this.session ? Math.floor(this.session.offset / this.session.size * 100) : 0; },
        get fileLabel() { return this.file ? `${this.file.name} · ${this.formatSize(this.file.size)}` : (this.session ? `${this.session.filename} · ${this.formatSize(this.session.size)}` : 'Keine Datei ausgewählt'); },
        formatSize(bytes) { if (bytes < 1048576) return `${(bytes / 1024).toLocaleString('de', { maximumFractionDigits: 1 })} KiB`;
            return `${(bytes / 1048576).toLocaleString('de', { maximumFractionDigits: 1 })} MiB`; },
        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey));
                if (saved?.session?.id) {
                    this.session = saved.session; this.fingerprint = saved.fingerprint; this.title = saved.title;
                    this.message = 'Wähle dieselbe Datei erneut, um fortzufahren. Dein bestätigter Fortschritt ist auf dem Server gespeichert.';
                }
            } catch { this.message = 'Dein Browser kann den Fortschritt nicht speichern. Lass diese Seite während des Uploads geöffnet.'; }
        },
        remember() {
            try { localStorage.setItem(this.storageKey, JSON.stringify({ session: this.session, fingerprint: this.fingerprint, title: this.title })); }
            catch { this.message = 'Der Upload kann fortgesetzt werden. Nach dem Schliessen dieser Seite ist dies jedoch nicht möglich.'; }
        },
        async selectFile(event) {
            this.error = ''; const file = event.target.files[0]; if (!file) return;
            this.busy = true;
            try {
                if (file.size > options.maxBytes || !file.size) throw new Error('Wähle eine nicht leere Videodatei innerhalb der maximalen Upload-Grösse.');
                const sample = new Blob([file.slice(0, 65536), file.slice(Math.max(0, file.size - 65536)), `${file.name}:${file.size}:${file.lastModified}`]);
                const fingerprint = await this.digest(sample);
                if (this.session && fingerprint !== this.fingerprint) throw new Error('Diese Datei passt nicht zum gespeicherten Upload. Wähle die ursprüngliche Datei oder «Neuen Upload starten».');
                this.file = file; this.fingerprint = fingerprint;
                if (!this.session) this.title = file.name.replace(/\.[^.]+$/, '');
                this.message = this.session ? 'Datei stimmt überein. Du kannst den Upload fortsetzen.' : 'Bereit zum Hochladen.';
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
                    this.message = `Upload-Limit erreicht. Automatischer Neuversuch in ${delay} Sekunden. Du kannst weiterhin pausieren.`;
                    throttled = true;
                    await this.waitForRetry(delay * 1000, signal);
                    continue;
                }
                if (!response.ok) throw new Error(response.status === 419 ? 'Deine Sitzung ist abgelaufen. Melde dich erneut an und wähle dieselbe Datei, um fortzufahren.' : result.message || `Anfrage fehlgeschlagen (${response.status}). Pausiere den Upload und versuche es erneut.`);
                if (throttled) this.message = 'Upload fortgesetzt.';
                return result;
            }
        },
        waitForRetry(milliseconds, signal) {
            return new Promise((resolve, reject) => {
                const abort = () => {
                    clearTimeout(timer);
                    signal.removeEventListener('abort', abort);
                    reject(new DOMException('Upload pausiert.', 'AbortError'));
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
                if (this.session.status === 'expired' || new Date(this.session.expires_at) <= new Date()) throw new Error('Dieser Upload ist abgelaufen. Starte einen neuen Upload und wähle die Datei erneut aus.');
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
                    this.message = 'Upload empfangen. Wird abgeschlossen…';
                    const result = await this.request(`${url}/finish`, 'POST');
                    this.completedUrl = result.video_url; this.message = 'Upload abgeschlossen. Dein Original ist sicher gespeichert.';
                    this.$nextTick?.(() => this.$refs.completeHeading?.focus());
                    try { localStorage.removeItem(this.storageKey); } catch { /* Upload is safely complete. */ }
                } else this.message = 'Pausiert. Setze den Upload ab dem bestätigten Fortschritt fort.';
            } catch (error) {
                this.paused = true;
                if (error.name !== 'AbortError') this.error = `${error.message} Beim Fortsetzen wird zuerst der vom Server bestätigte Fortschritt geprüft.`;
                else this.message = 'Pausiert. Beim Fortsetzen wird geprüft, welche Daten der Server empfangen hat.';
            } finally { this.busy = false; this.controller = null; }
        },
        pause() { this.paused = true; this.controller?.abort(); },
        reset() {
            if (this.busy) return;
            try { localStorage.removeItem(this.storageKey); } catch { /* Optional browser persistence. */ }
            const wasCompleted = !!this.completedUrl;
            this.session = null; this.file = null; this.fingerprint = null; this.completedUrl = ''; this.error = ''; this.title = '';
            this.message = wasCompleted
                ? 'Wähle ein weiteres Video zum Hochladen.'
                : 'Wähle ein Video. Unvollständige Uploads werden nach Ablauf entfernt.';
            this.$refs.file.value = '';
            this.$nextTick?.(() => this.$refs.file.focus());
        },
    };
}
