<?php

namespace App\Models;

use App\Enums\AddressType;
use App\Enums\CustomerKind;
use App\Models\Concerns\HasExternalReference;
use App\Support\EmailAddress;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the firm deals with, scoped to one corporation — the same real
 * person at two corporations is two unlinked customers. Not a login account:
 * no credentials live here.
 *
 * **A customer may have no name.** An unknown number that texts in becomes a
 * customer immediately, because the message has to belong to somebody; who
 * that somebody is can be filled in later, or never. Unnamed customers are
 * kept out of the customer list — the inbox is where they belong, shown by
 * their number — but they are otherwise ordinary people with addresses and
 * history. Naming one, or merging it into somebody already known, is how the
 * firm says who it was.
 *
 * Addresses are `identities`, not columns here — numbers *and* mailboxes.
 * One store, so an address typed on this record is immediately one the inbox
 * can answer on, and an address the customer writes from is immediately on
 * this record. Nothing about a person's contact details is a column here.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
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

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    /**
     * Every address this person can be reached at.
     */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * The team's state about this person — status, later ownership and
     * watchers. It exists only once something has actually been said, so a
     * customer whose number is merely written down is not an inbox item.
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function threads(): HasManyThrough
    {
        return $this->hasManyThrough(Thread::class, Identity::class);
    }

    /**
     * Everything ever said to or by this person, oldest first, across every
     * address and every channel. Resolved at read time, so moving an address
     * between people rewrites both timelines for free.
     *
     * @return Builder<Message>
     */
    public function timeline(): Builder
    {
        return Message::query()
            ->whereIn('thread_id', $this->threads()->select('threads.id'))
            ->orderBy('sent_at')
            ->orderBy('id');
    }

    /**
     * Somebody has said who this is.
     */
    public function isNamed(): bool
    {
        return filled($this->name);
    }

    /**
     * What to call this person: their name once known, otherwise the address
     * they were first reached at.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->name ?? $this->identities->first()?->value,
        );
    }

    /**
     * An address of a given kind, if the firm holds one — used when writing
     * to somebody nobody has written to yet.
     */
    public function addressFor(AddressType $addressType): ?string
    {
        return $this->identities->firstWhere('address_type', $addressType)?->value;
    }

    /**
     * The mailbox the firm holds for this person, if any.
     *
     * A convenience over `addressFor()`, kept because "the customer's email"
     * is a thing forms and payloads still ask for even though it is now one
     * address among several rather than a column.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->addressFor(AddressType::Email),
        );
    }

    /**
     * Resolve a mailbox to its single live customer within a corporation.
     * This is the only sanctioned path from an email to interaction data —
     * never key interactions off the email itself.
     *
     * Resolves through identities, which is the one store for addresses;
     * uniqueness is the identities partial index.
     */
    public static function findByEmail(Corporation $corporation, string $email): ?self
    {
        // A lookup, not an assertion: asking who owns an unparseable address
        // is answered "nobody", never with an exception.
        if (! EmailAddress::isValid($email)) {
            return null;
        }

        $identity = Identity::query()
            ->where('corporation_id', $corporation->id)
            ->where('address_type', AddressType::Email)
            ->where('value', EmailAddress::normalize($email))
            ->first();

        return $identity?->customer;
    }

    /**
     * Only people somebody has actually named. The rest are numbers the
     * inbox knows about and the address book does not need to show.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNamed(Builder $query): void
    {
        $query->whereNotNull('name');
    }
}
