export default function videoPlayer() {
    return {
        loading: true,
        failed: false,
        init() {
            this.loading = this.$refs.video.readyState < 2;
            this.failed = Boolean(this.$refs.video.error);
        },
        retry() {
            this.failed = false;
            this.loading = true;
            this.$refs.video.load();
        },
    };
}
