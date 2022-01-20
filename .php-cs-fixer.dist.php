<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'array_syntax' => ['syntax' => 'short'],
        'yoda_style' =>
            [
                'equal' => false,
                'identical' => false,
                'less_and_greater' => null,
            ],
    ])
    ->setFinder($finder);
