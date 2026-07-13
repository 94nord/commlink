<?php

namespace App\Mail\Concerns;

use App\Models\Corporation;
use Illuminate\Support\Facades\Storage;

trait WithCorporationLogo
{
    /**
     * Raw logo bytes for inline embedding in the mail footer, or null when
     * the corporation has no logo (or the object is missing).
     */
    protected function logoData(Corporation $corporation): ?string
    {
        if ($corporation->logo_path === null || ! Storage::exists($corporation->logo_path)) {
            return null;
        }

        return Storage::get($corporation->logo_path);
    }
}
