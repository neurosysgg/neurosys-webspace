<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The line between Phpanta and this site: nothing under `phpanta/src/` names a site class.
 *
 * The framework cannot know about the site that uses it — a second site built on it would have none
 * of this one's classes — so no framework file may reach one, whether by an import, a qualified
 * name, or an unqualified one that PHP resolves against the file's own namespace. The last is why
 * this reads tokens and resolves names the way {@link GuidelineTest} does, rather than grepping
 * `use` lines.
 *
 * The line was drawn before either side of it moved: the files headed for the framework were
 * listed, every reach from them into the site was pinned, and the list was worked down to nothing
 * while everything still lived under `src/NeuroSYS/`. Then they moved. What is left to guard is
 * that it stays at nothing.
 *
 * **Comments do not count here.** A docblock that mentions the site is a sentence, not a
 * dependency; the tokenizer hands comments over as their own tokens, so they never reach the
 * resolver.
 */
#[CoversNothing]
final class BoundaryTest extends TestCase
{
    /**
     * @return void
     */
    public function testNothingInTheFrameworkNamesTheSite(): void
    {
        $reaches = [];

        foreach (SourceTree::classes() as $path => $class) {
            if (!str_starts_with($class, 'Phpanta\\')) {
                continue;
            }

            foreach (self::referenced($path, $class) as $name) {
                $reaches[] = substr($path, strlen(NEUROSYS_ROOT) + 1) . ' → ' . $name;
            }
        }

        $this->assertSame([], $reaches, 'A framework file names a site class. Phpanta cannot know about the site.');
    }

    /**
     * The framework's own tree is not empty — a walk that found nothing would pass the test above.
     *
     * @return void
     */
    public function testTheFrameworkTreeIsWhereTheWalkLooks(): void
    {
        $framework = array_filter(
            SourceTree::classes(),
            static fn(string $class): bool => str_starts_with($class, 'Phpanta\\'),
        );

        $this->assertGreaterThan(100, count($framework));
    }

    /**
     * Every class under `NeuroSYS\` that one file names in code, resolved the way PHP resolves it.
     *
     * A name is resolved against the file's imports first, then against its own namespace, and it
     * counts only if a class, interface, enum or trait of exactly that name exists — so a method
     * called `release()` is not the class `Release`, which `class_exists()` alone would say it was,
     * since it compares without case.
     *
     * @param string       $path
     * @param class-string $class
     * @return list<class-string>
     */
    private static function referenced(string $path, string $class): array
    {
        $tokens    = PhpToken::tokenize(file_get_contents($path));
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $imports   = [];
        $names     = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === T_USE && self::isImport($tokens, $i)) {
                [$name, $alias, $i]        = self::import($tokens, $i);
                $short                     = self::shortName($name);
                $imports[$alias ?? $short] = $name;
                $names[]                   = $name;
                continue;
            }

            if ($token->id === T_NAME_FULLY_QUALIFIED) {
                $names[] = ltrim($token->text, '\\');
                continue;
            }

            if ($token->id !== T_STRING && $token->id !== T_NAME_QUALIFIED) {
                continue;
            }

            if (self::isMember($tokens, $i)) {
                continue;
            }

            $first   = strtok($token->text, '\\');
            $rest    = substr($token->text, strlen($first));
            $names[] = isset($imports[$first]) ? $imports[$first] . $rest : $namespace . '\\' . $token->text;
        }

        $found = [];

        foreach (array_unique($names) as $name) {
            $ours = str_starts_with($name, 'NeuroSYS\\') && self::exists($name);

            if ($ours && new ReflectionClass($name)->getName() === $name) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Whether the `use` at $i is an import, rather than a trait's or a closure's: an import sits at
     * brace depth zero.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return bool
     */
    private static function isImport(array $tokens, int $i): bool
    {
        $depth = 0;

        for ($k = 0; $k < $i; $k++) {
            $depth += (int) ($tokens[$k]->text === '{') - (int) ($tokens[$k]->text === '}');
            $depth += (int) ($tokens[$k]->id === T_CURLY_OPEN || $tokens[$k]->id === T_DOLLAR_OPEN_CURLY_BRACES);
        }

        return $depth === 0;
    }

    /**
     * One import statement starting at $i, as `[name, alias, index of its ;]`.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return array{string, ?string, int}
     */
    private static function import(array $tokens, int $i): array
    {
        $name  = '';
        $alias = null;
        $count = count($tokens);

        for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';'; $j++) {
            if ($tokens[$j]->id === T_AS) {
                $alias = '';
                continue;
            }

            if ($tokens[$j]->id === T_STRING || $tokens[$j]->id === T_NAME_QUALIFIED) {
                if ($alias === null) {
                    $name .= $tokens[$j]->text;
                } else {
                    $alias = $tokens[$j]->text;
                }
            }
        }

        return [$name, $alias, $j];
    }

    /**
     * Whether the name at $i is a member, a declaration or a label rather than a class.
     *
     * `->name`, `?->name`, `::NAME`, `function name`, `const NAME`, `case Name`, a named argument's
     * `name:` and the declared name after `class`/`enum`/`interface`/`trait` are all spelled with
     * the same token as a class name; the token before (or, for a named argument, after) is what
     * tells them apart.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return bool
     */
    private static function isMember(array $tokens, int $i): bool
    {
        for ($p = $i - 1; $p >= 0 && $tokens[$p]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]); $p--);
        for ($n = $i + 1; $n < count($tokens) && $tokens[$n]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]); $n++);

        $before = $tokens[$p] ?? null;
        $after  = $tokens[$n] ?? null;

        if (
            $before !== null && $before->is([
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CASE,
            T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT, T_NAMESPACE, T_GOTO,
            ])
        ) {
            return true;
        }

        // A named argument, `name: value` — but not the `?:` or `? :` of a ternary, whose colon
        // follows an expression rather than a bare name inside an argument list.
        return $after?->text === ':' && $before !== null && ($before->text === '(' || $before->text === ',');
    }

    /**
     * The last segment of a qualified name, or the name itself when it has none.
     *
     * @param string $name
     * @return string
     */
    private static function shortName(string $name): string
    {
        $separator = strrpos($name, chr(92));

        return $separator === false ? $name : substr($name, $separator + 1);
    }

    /**
     * @param string $name
     * @return bool
     */
    private static function exists(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
    }
}
