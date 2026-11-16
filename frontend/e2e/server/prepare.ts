/**
 * Readies the end-to-end run's server before it starts (playwright.config.ts), once the
 * SPA is built: a fresh data folder, the migrations, then the contract fixture the
 * backend's tests replay against (tests/Fixtures/contract.sql); and the build laid out
 * as the deploy lays it out, its index.html where the app reads it and every other file
 * in the server's public folder, beside a front controller that hands the rest to the
 * app's own public/index.php.
 *
 * It refuses to run if Laravel would use any database but that one, since the
 * migrations and the fixture would otherwise land in the database backend/.env names.
 */
import { execFileSync } from 'node:child_process'
import {
  copyFileSync,
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import {
  BACKEND_DIR,
  DATA_DIR,
  DATABASE,
  DIST_DIR,
  PUBLIC_DIR,
  SPA_INDEX,
  backendEnv,
} from '../env.ts'

const env = { ...process.env, ...backendEnv }

function artisan(...args: string[]): string {
  return execFileSync('php', ['artisan', ...args], { cwd: BACKEND_DIR, env, encoding: 'utf8' })
}

if (existsSync(join(BACKEND_DIR, 'bootstrap/cache/config.php'))) {
  throw new Error(
    'backend/bootstrap/cache/config.php exists: a cached config ignores the run’s settings. Run `php artisan config:clear` in backend/.',
  )
}

const builtIndex = join(DIST_DIR, 'index.html')
if (!readFileSync(builtIndex, 'utf8').includes('nonce="__CSP_NONCE__"')) {
  throw new Error(
    `${builtIndex} has no nonce placeholder: vite.config.ts's html.cspNonce must match SpaController::NONCE_PLACEHOLDER.`,
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

// The build: index.html for the app to serve, the rest as files.
mkdirSync(dirname(SPA_INDEX), { recursive: true })
copyFileSync(builtIndex, SPA_INDEX)
cpSync(DIST_DIR, PUBLIC_DIR, { recursive: true, filter: (source) => source !== builtIndex })
const frontController = join(BACKEND_DIR, 'public/index.php').replaceAll('\\', '/')
writeFileSync(
  join(PUBLIC_DIR, 'index.php'),
  `<?php\n\nrequire '${frontController.replaceAll("'", "\\'")}';\n`,
)
