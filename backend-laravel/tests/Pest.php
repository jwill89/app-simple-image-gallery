<?php

declare(strict_types=1);

use Tests\TestCase;

// Feature tests run inside the booted application; unit tests don't need it.
pest()->extend(TestCase::class)->in('Feature');
