<?php

namespace App\Contracts;

use App\Models\Video;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface VideoDelivery
{
    public function stream(Video $video, Request $request): Response;

    public function thumbnail(Video $video, Request $request): Response;
}
