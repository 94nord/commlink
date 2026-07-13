<?php

namespace App\Models;

use App\Enums\CustomerKind;
use App\Models\Concerns\HasExternalReference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The durable customer anchor (a person or an organization), scoped to one
 * corporation — the same real person at two corporations is two unlinked
 * customers. Not a login account: no credentials live here.
 *
 * Interactions (visits, work orders) reference the customer, never its
 * email. Resolution always goes email -> customer -> interactions, so the
 * inline email can later move into a contact-points table without touching
 * a single relationship.
 */
class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasExternalReference, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'kind',
        'name',
        'email',
        'address',
        'external_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CustomerKind::class,
        ];
    }

    /**
     * Emails are stored normalized so one address resolves to exactly one
     * live customer per corporation (enforced by a partial unique index).
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    /**
     * Resolve a contact address to its single live customer within a
     * corporation. This is the only sanctioned path from an email to
     * interaction data — never key visits or work orders off the email
     * itself.
     */
    public static function findByEmail(Corporation $corporation, string $email): ?self
    {
        return static::query()
            ->where('corporation_id', $corporation->id)
            ->where('email', mb_strtolower(trim($email)))
            ->first();
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
