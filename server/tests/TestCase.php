<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Requests carry an Origin header matching SANCTUM_STATEFUL_DOMAINS so
     * Sanctum treats them as first-party SPA requests (session-cookie auth)
     * the same way the web/ app's requests will.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:5173');
    }
}
