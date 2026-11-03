<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The rows the API contract was recorded on (contract.sql: 30 media, 16 tags
 * in five categories, implications, Danbooru mappings and a dismissed pair),
 * loaded onto the migrated schema. The comments in contract.sql say which rows
 * exist for which recorded case.
 */
final class ContractFixtureSeeder extends Seeder
{
    public function run(): void
    {
        // A trusted SQL script of several statements, so it goes to PDO as it is.
        DB::connection()->getPdo()->exec((string) file_get_contents(ContractFixture::path('contract.sql')));
    }
}
