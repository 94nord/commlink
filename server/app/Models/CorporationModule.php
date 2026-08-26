<?php

namespace App\Models;

use App\Enums\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One module a firm is switched on for. The row existing is the whole of it
 * — see the migration for why there is no `enabled` column.
 */
class CorporationModule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'module',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module' => Module::class,
        ];
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }
}
