<?php

namespace App\Services\Video;

use App\Models\VideoConversionSetting;

class ConversionSettings
{
    /** @return array{enabled: bool, automatic: bool} */
    public function values(): array
    {
        $settings = VideoConversionSetting::find(1);

        return [
            'enabled' => (bool) ($settings?->enabled ?? config('video.optimization_enabled')),
            'automatic' => (bool) ($settings?->automatic ?? config('video.optimization_auto')),
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

    public function save(bool $enabled, bool $automatic): void
    {
        VideoConversionSetting::updateOrCreate(['id' => 1], ['enabled' => $enabled, 'automatic' => $enabled && $automatic]);
    }
}
