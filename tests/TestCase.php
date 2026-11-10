<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // No test reaches a real provider (map search, routing, Paystack, SMS): an unfaked HTTP call fails the
        // test instead of quietly depending on the network, as the business queue's place lookup once did in CI.
        Http::preventStrayRequests();
    }
}
