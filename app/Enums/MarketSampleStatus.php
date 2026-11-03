<?php

namespace App\Enums;

enum MarketSampleStatus: string
{
    case Collecting = 'collecting';
    case Ready = 'ready';
    case Failed = 'failed';
}
