param(
    [ValidateSet('info', 'build', 'dev')]
    [string] $Target = 'build'
)

$ErrorActionPreference = 'Stop'

$config = '.webtolk/build/package.config.json'

switch ($Target) {
    'info' {
        php E:/.agents/tools/phing-packager/bin/packager.php info --config=$config
        break
    }
    'build' {
        php E:/.agents/tools/phing-packager/bin/packager.php package --config=$config --output-dir=.packages
        break
    }
    'dev' {
        php E:/.agents/tools/phing-packager/bin/packager.php package-dev --config=$config --output-dir=.packages
        break
    }
}

exit $LASTEXITCODE

