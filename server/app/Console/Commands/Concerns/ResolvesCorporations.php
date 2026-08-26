<?php

namespace App\Console\Commands\Concerns;

use App\Models\Corporation;

/**
 * How every ops command names the firm it is acting on.
 */
trait ResolvesCorporations
{
    /**
     * A firm by id or by exact name. An ambiguous name is refused rather
     * than guessed — acting on the wrong firm is silent and hard to notice.
     */
    private function resolveCorporation(string $reference): ?Corporation
    {
        if (ctype_digit($reference)) {
            return Corporation::find((int) $reference);
        }

        $matches = Corporation::where('name', $reference)->get();

        if ($matches->count() > 1) {
            $this->error("Several corporations are named '{$reference}' — use the id instead.");

            return null;
        }

        return $matches->first();
    }
}
