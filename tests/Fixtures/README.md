# Synthetic test video

`sample.mp4` is a two-second solid-color H.264 / AAC clip generated locally. It contains no personal footage. Regenerate with:

```sh
ffmpeg -f lavfi -i color=c=0x27483f:s=320x180:r=10 -f lavfi -i sine=frequency=440 -t 2 -c:v libx264 -pix_fmt yuv420p -c:a aac -movflags +faststart -y tests/Fixtures/sample.mp4
```

The playback test creates a sparse 5 GiB file temporarily; it does not commit or fill a multi-gigabyte fixture.
