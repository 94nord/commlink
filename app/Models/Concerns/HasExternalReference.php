<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Optional external reference (X2): a business entity can be linked to a
 * record in the corporation's host system, or stand alone (external_ref
 * null). Pair with the $table->externalRef() migration macro.
 */
trait HasExternalReference
{
    public function isExternallyLinked(): bool
    {
        return $this->external_ref !== null;
    }

    public function scopeExternallyLinked(Builder $query): Builder
    {
        return $query->whereNotNull('external_ref');
    }
}
