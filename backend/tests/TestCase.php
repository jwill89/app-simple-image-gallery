<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Override;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /**
     * Each request to the real server starts with nothing but the session its
     * cookie names. A test's requests share one app, whose session store keeps
     * the last request's data and merges the next one's into it, so a sign-in
     * would leak into every later request; it is emptied first.
     *
     * @param array<mixed> $parameters
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @param array<mixed> $server
     * @return TestResponse<Response>
     */
    #[Override]
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->app?->resolved('session.store')) {
            $this->app->make('session.store')->flush();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
