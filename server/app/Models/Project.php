<?php

namespace App\Models;

use App\Models\Concerns\HasExternalReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An optional, corporation-scoped grouping of work orders — e.g. the housing
 * block whose apartments are inspected in one go. Work orders can exist
 * without a project.
 */
class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasExternalReference, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'name',
        'external_ref',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Rounds filed under this project (SO8 — organization only, not
     * membership; a round's cases may come from anywhere).
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }
}
