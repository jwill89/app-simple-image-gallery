/**
 * Builds the end-to-end run's throwaway database before its server starts
 * (playwright.config.ts): a fresh data folder, the migrations, then the contract
 * fixture the backend's tests replay against (tests/Fixtures/contract.sql).
 *
 * It refuses to run if Laravel would use any database but that one, since the
 * migrations and the fixture would otherwise land in the database backend/.env names.
 */
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { BACKEND_DIR, DATA_DIR, DATABASE, backendEnv } from '../env.ts'

const env = { ...process.env, ...backendEnv }

function artisan(...args: string[]): string {
  return execFileSync('php', ['artisan', ...args], { cwd: BACKEND_DIR, env, encoding: 'utf8' })
}

if (existsSync(join(BACKEND_DIR, 'bootstrap/cache/config.php'))) {
  throw new Error(
    'backend/bootstrap/cache/config.php exists: a cached config ignores the run’s settings. Run `php artisan config:clear` in backend/.',
  )
}

rmSync(DATA_DIR, { recursive: true, force: true })
for (const folder of ['media/full', 'media/thumbs', 'dupes']) {
  mkdirSync(join(DATA_DIR, folder), { recursive: true })
}
writeFileSync(DATABASE, '')

const config = artisan('tinker', '--execute=echo config("database.connections.sqlite.database");')
if (resolve(config.trim()) !== resolve(DATABASE)) {
  throw new Error(
    `Laravel would use ${config.trim()}, not the run’s ${DATABASE}; nothing was changed.`,
  )
}

artisan('migrate', '--force', '--no-interaction')
// The fixture's rows, straight into the run's database file through PDO.
execFileSync(
  'php',
  [
    '-r',
    '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec(file_get_contents($argv[2]));',
    DATABASE,
    join(BACKEND_DIR, 'tests/Fixtures/contract.sql'),
  ],
  { env, stdio: 'inherit' },
)
