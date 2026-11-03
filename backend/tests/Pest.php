<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests run inside the booted application, on a freshly migrated
// in-memory database; unit tests need neither.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
