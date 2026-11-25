<?php

namespace App\Jobs;

class PrepareWebVideo extends OptimizeVideo
{
    protected bool $standard = true;

    public function __construct(int $videoId, string $token)
    {
        parent::__construct($videoId, $token);
        $this->onQueue('standard');
    }
}
