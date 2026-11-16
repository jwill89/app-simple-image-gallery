<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Timestamp;
use Illuminate\Database\Eloquent\Attributes\Table;
use Laravel\Passkeys\Passkey as PackagePasskey;

/**
 * laravel/passkeys' passkey, with its times stored in the gallery's form
 * (App\Support\Timestamp). AppServiceProvider makes the package use it.
 */
#[Table(name: 'passkeys')]
class Passkey extends PackagePasskey
{
    #[\Override]
    protected $dateFormat = Timestamp::FORMAT;
}
