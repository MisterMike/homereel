export default function mediaThumbnail() {
    return {
        loaded: false,
        failed: false,
        init() {
            const image = this.$refs.image;
            if (image?.complete) {
                this.loaded = image.naturalWidth > 0;
                this.failed = !this.loaded;
            }
        },
    };
}
