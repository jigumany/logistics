param([switch]$Seed)

$ErrorActionPreference = 'Stop'
function Wait-HttpReady([string]$Url) {
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5
            if ($response.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Seconds 2
    }
    throw "Service did not become ready: $Url"
}

$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
    if (!(Test-Path apps/platform/.env)) {
        Copy-Item apps/platform/.env.example apps/platform/.env
    }
    $settings = Get-Content apps/platform/.env -Raw
    if ($Seed -and ($settings -match '(?m)^DEMO_PASSWORD=\s*$')) {
        throw 'Set DEMO_PASSWORD in apps/platform/.env to at least 12 characters before seeding.'
    }
    docker compose up -d --build postgres redis minio platform nginx
    if ($LASTEXITCODE) { throw 'Docker services failed to start.' }

    docker compose exec -T platform composer install --no-interaction
    if ($LASTEXITCODE) { throw 'Composer install failed.' }

    if ($settings -match '(?m)^APP_KEY=\s*$') {
        docker compose exec -T platform php artisan key:generate
        if ($LASTEXITCODE) { throw 'Application key generation failed.' }
    }
    docker compose exec -T platform php artisan migrate --force
    if ($LASTEXITCODE) { throw 'Migrations failed.' }

    docker compose exec -T platform php artisan storage:prepare-local
    if ($LASTEXITCODE) { throw 'Private development storage setup failed.' }

    npm ci
    if ($LASTEXITCODE) { throw 'Node dependency installation failed.' }
    npm run build --workspace=@logistics/platform
    if ($LASTEXITCODE) { throw 'Platform build failed.' }

    if ($Seed) {
        docker compose exec -T platform php artisan db:seed
        if ($LASTEXITCODE) { throw 'Development seeding failed.' }
    }
    docker compose run --rm --no-deps website npm ci --ignore-scripts
    if ($LASTEXITCODE) { throw 'Linux website dependencies failed to install.' }

    docker compose up -d horizon scheduler website
    if ($LASTEXITCODE) { throw 'Worker or website startup failed.' }

    Wait-HttpReady 'http://localhost:8080/health/ready'
    Wait-HttpReady 'http://localhost:8080/login'
    Wait-HttpReady 'http://localhost:3000'
    Write-Output 'Platform: http://localhost:8080/login'
    Write-Output 'Website: http://localhost:3000'
} finally {
    Pop-Location
}
