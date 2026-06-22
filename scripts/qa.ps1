param(
    [ValidateSet('all', 'syntax', 'phpcs', 'cs', 'phpstan', 'phpunit')]
    [string] $Target = 'all'
)

$ErrorActionPreference = 'Stop'

$phpFiles = @(
    'script.php',
    'services/provider.php',
    'src/Extension/Cfi.php',
    'src/Fields/PlugininfoField.php',
    'layouts/default.php',
    'layouts/upload.php',
    'tests/bootstrap.php'
)

function Invoke-Step {
    param(
        [string] $Name,
        [scriptblock] $Command
    )

    Write-Host "== $Name =="
    & $Command
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

if ($Target -in @('all', 'syntax')) {
    Invoke-Step 'PHP syntax' {
        foreach ($file in $phpFiles) {
            php -l $file
            if ($LASTEXITCODE -ne 0) {
                exit $LASTEXITCODE
            }
        }
    }
}

if ($Target -in @('all', 'phpcs')) {
    Invoke-Step 'PHPCS' {
        php E:/.agents/tools/php-qa/vendor/bin/phpcs --standard=phpcs.xml --report=full --report-file=.webtolk/tmp/phpcs.txt
        if (Test-Path '.webtolk/tmp/phpcs.txt') {
            Get-Content '.webtolk/tmp/phpcs.txt'
        }
    }
}

if ($Target -in @('all', 'cs')) {
    Invoke-Step 'PHP-CS-Fixer dry run' {
        php E:/.agents/tools/php-qa/vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php
    }
}

if ($Target -in @('all', 'phpstan')) {
    Invoke-Step 'PHPStan' {
        php -d opcache.enable_cli=0 E:/.agents/tools/php-qa/vendor/bin/phpstan analyse --configuration=phpstan.neon --debug
    }
}

if ($Target -in @('all', 'phpunit')) {
    Invoke-Step 'PHPUnit' {
        php E:/.agents/tools/php-qa/vendor/bin/phpunit --configuration=phpunit.xml
    }
}
