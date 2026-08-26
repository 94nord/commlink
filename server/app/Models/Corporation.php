<?php

namespace App\Models;

use App\Enums\AddressType;
use App\Enums\Module;
use Database\Factories\CorporationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Corporation extends Model
{
    /** @use HasFactory<CorporationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'logo_path',
    ];

    /**
     * Get the addresses the corporation is reachable at — the numbers it
     * texts from, later the mailboxes it writes from.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CorporationAddress::class);
    }

    /**
     * The address new exchanges of this kind start from.
     *
     * Deliberately not "the current address": an exchange already under way
     * keeps whatever it started on, and every address the corporation has
     * ever had stays live for inbound.
     */
    public function primaryAddress(AddressType $type): ?CorporationAddress
    {
        return $this->addresses()
            ->where('address_type', $type)
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Get the users belonging to the corporation.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the customers belonging to the corporation.
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get the staff members belonging to the corporation.
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /**
     * Get the inbox conversations belonging to the corporation.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the modules the corporation is switched on for.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(CorporationModule::class);
    }

    /**
     * Whether the firm is switched on for this module.
     *
     * Loaded through the relation rather than queried, so asking about
     * several modules while rendering one payload costs one query, not one
     * per question.
     */
    public function hasModule(Module $module): bool
    {
        return $this->modules->contains('module', $module);
    }

    /**
     * Get the addresses the corporation's *customers* are reachable at —
     * the mirror of addresses(), which is where the firm itself is reached.
     */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * Get the messages belonging to the corporation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the files the corporation holds — pictures that came in with a
     * message, and whatever later points at the same rows.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * Get the photo upload links the corporation has handed out.
     */
    public function uploadLinks(): HasMany
    {
        return $this->hasMany(UploadLink::class);
    }
}
