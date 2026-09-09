<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    // PHP 8.5 is newer than php-cs-fixer officially supports; without this it refuses to
    // run at all and needs PHP_CS_FIXER_IGNORE_ENV=1 on every invocation.
    ->setUnsupportedPhpVersionAllowed(true)
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

        // ── the import rules, adopted from a PhpStorm inspection run on 2026-09-05 ──
        //
        // These three are not style. They are the rules that pass was actually about, and encoding
        // them here is what makes every reader agree: `composer lint` enforces them, PhpStorm's
        // PhpCSFixerValidationInspection reads this file, and Neovim's conform runs php-cs-fixer
        // with --config resolved from the buffer's project root. One statement, three consumers,
        // and the editor applies it on save rather than reporting it.
        //
        // Verified against the hand-applied pass: with these on, php-cs-fixer reproduces it and
        // finds only the three docblocks it had missed.

        // A global class is imported rather than written with a leading backslash: `use NoDiscard;`
        // and `#[NoDiscard]`, not `#[\NoDiscard]`. Constants and functions are deliberately left
        // alone — `PHP_EOL` and `strlen()` read better global, and importing them would churn every
        // file for nothing.
        'global_namespace_import' => [
            'import_classes'   => true,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // The counterpart: an import nothing uses is removed rather than reported. Worth having
        // automatic — an unused import is the residue of a refactor, and the last pass left two.
        'no_unused_imports' => true,

        // The same rule inside docblocks and signatures, which the class rule above does not reach:
        // `@throws ReflectionException`, not `@throws \ReflectionException`.
        'fully_qualified_strict_types' => true,

        // Alphabetical, so an import list has one right order rather than the order things were
        // needed in. Adopted 2026-09-06, out of the tools review: `Release\ProjectFile` had
        // `Tool\Flp\*` above `Support\*` and `SoundCloud\TokenStore` had `Support\File` below a
        // `Tool\Http\`, and the rule then found five more under `src/` and `test/` that nobody had
        // noticed either. That is the argument for stating it here rather than fixing the two by
        // hand: an import list out of order is the one kind of drift that never fails anything, so
        // it accumulates. A rule notices; a person reading a diff does not.
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
    ])
    ->setFinder(
        (new Finder())
            // `data/` is deliberately absent from this list, and it is the one place the two linters
            // disagree. phpcs lints it and exempts `PSR12.Files.FileHeader` there, because a data
            // file is a `use` block, a docblock and a `return` — a shape PSR-12's header rules do
            // not describe. This fixer has no such carve-out, so adding the directory would not
            // report that disagreement, it would *rewrite* data/releases.php to settle it. Reading
            // the catalogue is what a check is for; reformatting it is not.
            ->in([__DIR__ . '/src', __DIR__ . '/public', __DIR__ . '/test', __DIR__ . '/tools'])
            // in() takes directories, so the two PHP files at the repo root need naming. autoload.php
            // is the file every request loads through, and neither linter had ever seen it. This file
            // is the second, and it had the same gap for the same reason — with nobody noticing that
            // the rules were stated in a file the rules did not reach.
            ->append([__DIR__ . '/autoload.php', __FILE__])
    )
;
