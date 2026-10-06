<?php

namespace App\Platform\Tenancy;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Workspace (tenant). A global table: the runtime role `app` has no privilege on it, and its rows
 * are read through the Access-owned lookup function only. Keys are UUIDv7 generated here.
 *
 * @property string $id
 * @property string $name
 * @property string|null $label
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'label', 'status'])]
class Workspace extends Model
{
    use HasUuids;
}
