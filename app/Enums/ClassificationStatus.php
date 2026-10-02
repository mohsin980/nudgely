<?php

namespace App\Enums;

enum ClassificationStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
