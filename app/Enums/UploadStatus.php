<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
}
