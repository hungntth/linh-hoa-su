$ErrorActionPreference = 'Stop'
$files = @(
    @{ Source = 'docker/game.env.example'; Target = 'docker/game.env' },
    @{ Source = 'docker/php-config.php.example'; Target = 'docker/php-config.php' },
    @{ Source = 'data/lhs/LHS-dist/.env.example'; Target = 'data/lhs/LHS-dist/.env' }
)
foreach ($file in $files) {
    if (-not (Test-Path -LiteralPath $file.Target)) {
        Copy-Item -LiteralPath $file.Source -Destination $file.Target
    }
}
New-Item -ItemType Directory -Force -Path 'docker/seed' | Out-Null
Write-Output 'Docker configuration ready. Run: docker compose up -d --build'
