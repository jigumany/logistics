param([string]$PhpBinary = $env:PHP_BINARY)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
$previousPhpBinary = $env:PHP_BINARY
try {
    docker info --format '{{.ServerVersion}}'
    if ($LASTEXITCODE) { throw 'Docker Linux engine is unavailable. Start Docker Desktop after restarting Windows.' }

    $running = docker compose ps --status running -q platform
    if (!$running) { throw 'Start the development stack with scripts/dev.ps1 before running tests.' }

    docker compose exec -T platform php artisan config:clear
    if ($LASTEXITCODE) { throw 'Unable to clear cached development configuration before database-isolated tests.' }

    $exists = docker compose exec -T postgres psql -U logistics -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = 'logistics_test'"
    if ($LASTEXITCODE) { throw 'Unable to check the dedicated PostgreSQL test database.' }
    if (($exists -join '').Trim() -ne '1') {
        docker compose exec -T postgres createdb -U logistics -O logistics logistics_test
        if ($LASTEXITCODE) { throw 'Unable to create the dedicated PostgreSQL test database.' }
    }

    docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=logistics_test -e DB_USERNAME=logistics -e DB_PASSWORD=local-development-only -e DB_URL= platform php artisan test
    if ($LASTEXITCODE) { throw 'PostgreSQL/PostGIS tests failed.' }
    docker compose exec -T platform php vendor/bin/pint --test
    if ($LASTEXITCODE) { throw 'PHP formatting checks failed.' }
    docker compose exec -T platform php vendor/bin/phpstan analyse --memory-limit=512M --no-progress
    if ($LASTEXITCODE) { throw 'PHP static analysis failed.' }

    npm run typecheck
    if ($LASTEXITCODE) { throw 'TypeScript checks failed.' }
    npm run format:check
    if ($LASTEXITCODE) { throw 'Frontend formatting checks failed.' }
    npm test
    if ($LASTEXITCODE) { throw 'Frontend unit tests failed.' }
    npm run build
    if ($LASTEXITCODE) { throw 'Production builds failed.' }

    if (!$PhpBinary) { $PhpBinary = (Get-Command php -ErrorAction Stop).Source }
    & $PhpBinary -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'
    if ($LASTEXITCODE) { throw 'Browser tests require PHP 8.3+. Pass its executable using -PhpBinary.' }
    $env:PHP_BINARY = $PhpBinary
    npm run test:e2e
    if ($LASTEXITCODE) { throw 'Browser tests failed.' }
} finally {
    $env:PHP_BINARY = $previousPhpBinary
    Pop-Location
}
