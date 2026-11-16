<?php

namespace App\Models;

use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider's scheduling override — see this table's migration. Read through DiscoveryProviderInventory, which is the
 * only thing that decides what the override means.
 *
 * @property int $id
 * @property JobDiscoverySource $provider
 * @property ScheduleOverride $schedule_override
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'schedule_override'])]
class DiscoveryProviderSetting extends Model
{
    protected function casts(): array
    {
        return [
            'provider' => JobDiscoverySource::class,
            'schedule_override' => ScheduleOverride::class,
        ];
    }
}
