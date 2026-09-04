<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        // php-cs-fixer can only force ALL function braces to one style (same_line or
        // next_line) - there's no "leave as authored" mode, so turn brace placement
        // enforcement off entirely rather than let it steamroll one-line accessors
        // into multi-line (or vice versa) across the whole codebase
        'braces_position' => false,
        // don't collapse column-aligned constructor param type hints back to a single space
        'type_declaration_spaces' => false,
        // don't collapse column-aligned one-liner method braces back to a single space
        // (also gives up this rule's other spacing fixes around `function`/parens - accepted trade-off)
        'function_declaration' => false,
    ])
    ->setFinder(
        (new Finder())
            ->in([__DIR__ . '/src', __DIR__ . '/public', __DIR__ . '/test'])
    )
;
