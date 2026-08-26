<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A standing invitation to add photos to one conversation, and nothing else.
 *
 * Whoever holds the token can use it — that is the point, since the customer
 * has no account — so its authority is deliberately narrow: one conversation
 * and an expiry it cannot outlive. It is not narrow on volume: a job that
 * turns out to need fifteen photos instead of five should not stall on a
 * total the customer has no way to see coming.
 */
class UploadLink extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'conversation_id',
        'user_id',
        'token_hash',
        'token',
        'expires_at',
        'revoked_at',
        'upload_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            // Readable again by the firm that handed it out, and by nobody
            // without APP_KEY. The hash beside it is what lookups use.
            'token' => 'encrypted',
        ];
    }

    /**
     * A fresh token: long and random, because holding it is the whole
     * authority a link carries.
     */
    public static function newToken(): string
    {
        return Str::random(40);
    }

    /**
     * How a token is looked up: deterministic and indexed, which the
     * encrypted copy beside it cannot be.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The one that this token opens, if it still opens anything.
     */
    public static function findUsable(string $token): ?self
    {
        return static::query()->usable()->where('token_hash', static::hash($token))->first();
    }

    /**
     * @param  Builder<UploadLink>  $query
     * @return Builder<UploadLink>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The staff member who asked for the photos. Null once they are gone.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ! $this->hasExpired();
    }

    /**
     * Close this link now. Kept apart from letting it expire so the log can
     * still tell a link that ran out from one the firm replaced.
     */
    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }
}
