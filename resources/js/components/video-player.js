export default function videoPlayer(options = {}) {
    return {
        loading: true,
        failed: false,
        quality: options.initialQuality ?? 'original',
        sources: options.sources ?? {},
        pendingPlayback: null,
        playbackMessage: '',
        viewRecorded: false,
        recordingView: false,
        fullscreenAvailable: false, fullscreen: false, fullscreenPending: false,
        landscape: false, mobileDevice: false,
        automaticFullscreen: false, landscapeAttempted: false, fullscreenNeedsTap: false,
        mobileQuery: null, landscapeQuery: null, orientationListener: null, fullscreenListener: null,
        get offerFullscreen() { return this.fullscreenAvailable && this.mobileDevice && this.landscape && !this.fullscreen; },
        init() {
            const video = this.$refs.video;
            this.loading = video.readyState < 1;
            this.failed = Boolean(video.error);
            if (typeof window === 'undefined' || typeof window.matchMedia !== 'function' || typeof document === 'undefined') return;
            this.fullscreenAvailable = (typeof video.requestFullscreen === 'function' && document.fullscreenEnabled !== false) || typeof video.webkitEnterFullscreen === 'function';
            this.mobileQuery = window.matchMedia('(pointer: coarse) and (max-width: 1024px)');
            this.landscapeQuery = window.matchMedia('(orientation: landscape)');
            this.landscape = this.landscapeQuery.matches;
            this.mobileDevice = this.mobileQuery.matches;
            this.orientationListener = () => this.handleOrientationChange();
            this.fullscreenListener = event => {
                this.syncFullscreen(event.type);
                if (this.fullscreen && this.automaticFullscreen && !this.landscapeQuery.matches) this.exitAutomaticFullscreen();
            };
            if (this.landscapeQuery.addEventListener) this.landscapeQuery.addEventListener('change', this.orientationListener);
            else this.landscapeQuery.addListener?.(this.orientationListener);
            document.addEventListener('fullscreenchange', this.fullscreenListener);
            video.addEventListener('webkitbeginfullscreen', this.fullscreenListener);
            video.addEventListener('webkitendfullscreen', this.fullscreenListener);
            this.syncFullscreen();
        },
        destroy() {
            if (!this.orientationListener) return;
            if (this.landscapeQuery.removeEventListener) this.landscapeQuery.removeEventListener('change', this.orientationListener);
            else this.landscapeQuery.removeListener?.(this.orientationListener);
            document.removeEventListener('fullscreenchange', this.fullscreenListener);
            this.$refs.video.removeEventListener('webkitbeginfullscreen', this.fullscreenListener);
            this.$refs.video.removeEventListener('webkitendfullscreen', this.fullscreenListener);
        },
        syncFullscreen(eventType = '') {
            const video = this.$refs.video;
            this.fullscreen = eventType === 'webkitendfullscreen' ? false : Boolean(eventType === 'webkitbeginfullscreen' || document.fullscreenElement === video || document.webkitFullscreenElement === video || video.webkitDisplayingFullscreen);
            if (!this.fullscreen && (eventType === 'fullscreenchange' || eventType === 'webkitendfullscreen')) this.automaticFullscreen = false;
        },
        async handleOrientationChange() {
            if (!this.landscapeQuery) return;
            this.landscape = this.landscapeQuery.matches;
            this.mobileDevice = this.mobileQuery.matches;
            if (!this.landscape) {
                this.landscapeAttempted = false;
                this.fullscreenNeedsTap = false;
                await this.exitAutomaticFullscreen();
                return;
            }
            const video = this.$refs.video;
            const activeFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
            if (!this.mobileQuery.matches || !this.fullscreenAvailable || this.fullscreen || this.fullscreenPending || this.landscapeAttempted || video.paused || video.ended || video.readyState < 1 || document.visibilityState === 'hidden' || (activeFullscreen && activeFullscreen !== video)) return;
            this.landscapeAttempted = true;
            await this.enterFullscreen(true);
        },
        async enterFullscreen(automatic = false) {
            if (!this.fullscreenAvailable || this.fullscreen || this.fullscreenPending) return;
            this.fullscreenPending = true;
            this.automaticFullscreen = automatic;
            this.landscapeAttempted = true;
            this.fullscreenNeedsTap = false;
            const video = this.$refs.video;
            try {
                if (typeof video.requestFullscreen === 'function' && document.fullscreenEnabled !== false) await video.requestFullscreen();
                else video.webkitEnterFullscreen();
                this.syncFullscreen();
                if (automatic && !this.landscapeQuery.matches) await this.exitAutomaticFullscreen();
            } catch {
                this.automaticFullscreen = false;
                this.fullscreenNeedsTap = true;
            } finally { this.fullscreenPending = false; }
        },
        async exitAutomaticFullscreen() {
            if (!this.automaticFullscreen) return;
            const video = this.$refs.video;
            if (document.fullscreenElement !== video && !video.webkitDisplayingFullscreen && !this.fullscreen) return;
            try {
                if (document.fullscreenElement === video && document.exitFullscreen) await document.exitFullscreen();
                else if ((video.webkitDisplayingFullscreen || this.fullscreen) && video.webkitExitFullscreen) video.webkitExitFullscreen();
            } catch { /* Fullscreen remains controlled by the browser. */ }
            finally { this.automaticFullscreen = false; }
        },
        switchQuality(quality) {
            if (quality === this.quality || !this.sources[quality]) return;
            const video = this.$refs.video;
            this.pendingPlayback ??= {
                time: video.currentTime || 0,
                playing: !video.paused && !video.ended,
                rate: video.playbackRate,
            };
            this.quality = quality;
            this.failed = false;
            this.loading = true;
            this.playbackMessage = '';
            video.src = this.sources[quality];
            video.load();
        },
        async restorePlayback() {
            this.loading = false;
            const video = this.$refs.video;
            const playback = this.pendingPlayback;
            if (!playback) return;
            this.pendingPlayback = null;
            video.currentTime = Number.isFinite(video.duration)
                ? Math.min(playback.time, Math.max(0, video.duration - 0.1))
                : playback.time;
            video.playbackRate = playback.rate;
            this.playbackMessage = `Gewechselt zu ${this.quality === 'optimized' ? 'Optimiert' : 'Original'}.`;
            if (playback.playing) {
                try {
                    await video.play();
                } catch {
                    this.playbackMessage += ' Drücke auf Wiedergabe, um fortzufahren.';
                }
            }
        },
        async recordView() {
            if (!options.viewUrl || this.viewRecorded || this.recordingView) return;
            this.recordingView = true;
            try {
                const response = await fetch(options.viewUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': options.csrf },
                });
                if (!response.ok) return;
                const result = await response.json();
                this.viewRecorded = true;
                this.$dispatch('video-view-recorded', { videoId: options.videoId, count: result.view_count });
            } catch { /* View counting must not interrupt playback. */ }
            finally { this.recordingView = false; }
        },
        retry() {
            this.failed = false;
            this.loading = true;
            this.$refs.video.load();
        },
    };
}
