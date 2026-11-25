<?php

namespace App\Services\Video;

use App\Models\VideoConversionSetting;
use Illuminate\Validation\Rule;

class ConversionSettings
{
    public const WEB_PROFILE = ['crf' => 23, 'preset' => 'veryfast', 'max_height' => 720, 'video_bitrate' => 2500, 'audio_bitrate' => 128];

    public const DEFAULT_PROFILE = ['crf' => 23, 'preset' => 'veryfast', 'max_height' => 720, 'video_bitrate' => 2500, 'audio_bitrate' => 128];

    public const PRESETS = ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow'];

    /** @return array{enabled: bool, automatic: bool, crf: int, preset: string, max_height: int, video_bitrate: int, audio_bitrate: int} */
    public function values(): array
    {
        $settings = VideoConversionSetting::find(1);

        return [
            'enabled' => (bool) ($settings?->enabled ?? config('video.optimization_enabled')),
            'automatic' => (bool) ($settings?->automatic ?? config('video.optimization_auto')),
            'crf' => $settings?->crf ?? self::DEFAULT_PROFILE['crf'],
            'preset' => $settings?->preset ?? self::DEFAULT_PROFILE['preset'],
            'max_height' => $settings?->max_height ?? self::DEFAULT_PROFILE['max_height'],
            'video_bitrate' => $settings?->video_bitrate ?? self::DEFAULT_PROFILE['video_bitrate'],
            'audio_bitrate' => $settings?->audio_bitrate ?? self::DEFAULT_PROFILE['audio_bitrate'],
        ];
    }

    public function enabled(): bool
    {
        return $this->values()['enabled'];
    }

    public function automatic(): bool
    {
        $settings = $this->values();

        return $settings['enabled'] && $settings['automatic'];
    }

    /** @return array{crf: int, preset: string, max_height: int, video_bitrate: int, audio_bitrate: int} */
    public function profile(): array
    {
        return array_intersect_key($this->values(), self::DEFAULT_PROFILE);
    }

    /** @param array<string, mixed> $profile */
    public function save(bool $enabled, bool $automatic, array $profile = []): void
    {
        $profile = validator(array_merge($this->profile(), $profile), [
            'crf' => ['required', 'integer', 'between:16,30'],
            'preset' => ['required', Rule::in(self::PRESETS)],
            'max_height' => ['required', 'integer', Rule::in([0, 480, 720, 1080, 1440, 2160])],
            'video_bitrate' => ['required', 'integer', 'between:250,100000'],
            'audio_bitrate' => ['required', 'integer', Rule::in([64, 96, 128, 192, 256, 320])],
        ])->validate();
        VideoConversionSetting::updateOrCreate(['id' => 1], array_merge($profile, ['enabled' => $enabled, 'automatic' => $enabled && $automatic]));
    }

    /** @param array{crf: int, preset: string, max_height: int, video_bitrate: int, audio_bitrate: int} $profile */
    public function reservationBytes(float $duration, array $profile): int
    {
        $bitrate = ($profile['video_bitrate'] + $profile['audio_bitrate']) * 1000;
        $buffer = $profile['video_bitrate'] * 2000 / 8;

        return (int) ceil(($duration * $bitrate / 8 + $buffer) * 1.15) + 1048576;
    }

    /**
     * @param  array{crf: int, preset: string, max_height: int, video_bitrate: int, audio_bitrate: int}  $profile
     * @return array<int, string>
     */
    public function ffmpegArguments(array $profile): array
    {
        $height = (int) $profile['max_height'];
        $width = (int) floor($height * 16 / 9 / 2) * 2;
        $filter = $height === 0
            ? 'scale=w=trunc(iw/2)*2:h=trunc(ih/2)*2,setsar=1'
            : 'scale=w=min('.$width.'\\,iw):h=min('.$height.'\\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1';

        return [
            '-vf', $filter, '-c:v', 'libx264', '-preset', $profile['preset'], '-crf', (string) $profile['crf'],
            '-maxrate', $profile['video_bitrate'].'k', '-bufsize', ($profile['video_bitrate'] * 2).'k', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', $profile['audio_bitrate'].'k', '-ac', '2',
        ];
    }
}
