export default function videoPlayer(options = {}) {
    return {
        loading: true,
        failed: false,
        quality: options.initialQuality ?? 'original',
        sources: options.sources ?? {},
        pendingPlayback: null,
        playbackMessage: '',
        init() {
            this.loading = this.$refs.video.readyState < 2;
            this.failed = Boolean(this.$refs.video.error);
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
            const video = this.$refs.video;
            const playback = this.pendingPlayback;
            if (!playback) return;
            this.pendingPlayback = null;
            video.currentTime = Number.isFinite(video.duration)
                ? Math.min(playback.time, Math.max(0, video.duration - 0.1))
                : playback.time;
            video.playbackRate = playback.rate;
            this.playbackMessage = `Switched to ${this.quality === 'optimized' ? 'Optimized' : 'Original'}.`;
            if (playback.playing) {
                try {
                    await video.play();
                } catch {
                    this.playbackMessage += ' Press play to continue.';
                }
            }
        },
        retry() {
            this.failed = false;
            this.loading = true;
            this.$refs.video.load();
        },
    };
}
