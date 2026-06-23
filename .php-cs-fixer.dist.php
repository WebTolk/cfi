<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/services',
        __DIR__ . '/src',
        __DIR__ . '/layouts',
    ])
    ->append([
        __DIR__ . '/script.php',
        __DIR__ . '/tests/bootstrap.php',
    ])
    ->exclude([
        '.packages',
        '.phing',
        '.webtolk',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try'],
        ],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
    ])
    ->setFinder($finder);

