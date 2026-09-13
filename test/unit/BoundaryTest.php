<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The line between Phpanta and this site, drawn before either side of it moves.
 *
 * `test/framework-files.txt` names every file under `src/` that is headed for the framework. The
 * framework cannot know about the site that uses it, so none of those files may reach a class that
 * is not on the list — whether by an import, a qualified name, or an unqualified one that PHP
 * resolves against the file's own namespace. The last is why this reads tokens and resolves names
 * the way {@link GuidelineTest} does, rather than grepping `use` lines: `Site::NAME` written in
 * `NeuroSYS\Service` imports nothing and still reaches the site.
 *
 * **Comments do not count.** A docblock that says `{@link App::dataFile()}` is a sentence about
 * the site, and it is read and rewritten when the files move; the tokenizer hands comments over as
 * their own tokens, so they never reach the resolver.
 *
 * The violations that exist today are pinned in {@link self::STILL_REACHING}, in both directions:
 * a new one fails, and so does one that has been fixed but not crossed off. The list only shrinks,
 * and the move waits until it is empty.
 */
#[CoversNothing]
final class BoundaryTest extends TestCase
{
    /**
     * Every place a framework file still reaches the site, as `file → class`.
     *
     * @var list<string>
     */
    private const array STILL_REACHING = [];

    /**
     * Every file on the list exists, and every one of them names a class the autoloader can load.
     *
     * A stale line would be a file the boundary claims to guard and does not.
     *
     * @return void
     */
    public function testEveryListedFileIsAClassThatExists(): void
    {
        $missing = [];

        foreach (self::framework() as $path => $class) {
            if ($class === null) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'Listed in test/framework-files.txt, but no class loads from it.');
    }

    /**
     * No framework file reaches a class outside the framework, except the ones still pinned.
     *
     * @return void
     */
    public function testNoFrameworkFileReachesTheSite(): void
    {
        $this->assertSame(
            self::STILL_REACHING,
            self::reaching(),
            'A framework file reaches a site class, or a pinned reach has been removed and should be '
            . 'crossed off. The list only shrinks.',
        );
    }

    /**
     * Every framework file's reach into the site, sorted.
     *
     * @return list<string>
     */
    private static function reaching(): array
    {
        $framework = self::framework();
        $inside    = array_flip(array_filter($framework));
        $reaches   = [];

        foreach ($framework as $path => $class) {
            if ($class === null) {
                continue;
            }

            foreach (self::referenced(NEUROSYS_ROOT . '/' . $path, $class) as $name) {
                if (!isset($inside[$name])) {
                    $reaches[] = $path . ' → ' . $name;
                }
            }
        }

        $reaches = array_values(array_unique($reaches));
        sort($reaches);

        return $reaches;
    }

    /**
     * The list, as `path => class-string`, with null for a path no class loads from.
     *
     * @return array<string, ?class-string>
     */
    private static function framework(): array
    {
        $framework = [];
        $lines     = file(NEUROSYS_ROOT . '/test/framework-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $path) {
            $class = 'NeuroSYS\\' . str_replace('/', '\\', substr($path, strlen('src/NeuroSYS/'), -strlen('.php')));

            $framework[$path] = self::exists($class) ? new ReflectionClass($class)->getName() : null;
        }

        return $framework;
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
                [$name, $alias, $i] = self::import($tokens, $i);
                $short             = substr($name, (int) strrpos($name, '\\') + (str_contains($name, '\\') ? 1 : 0));
                $imports[$alias ?? $short] = $name;
                $names[] = $name;
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

            $first  = strtok($token->text, '\\');
            $rest   = substr($token->text, strlen($first));
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
     * Whether the `use` at $i is an import, rather than a trait's or a closure's.
     *
     * An import is a top-level statement: nothing but whitespace, a comment or a `;`/`}` before it
     * on the way back to the previous statement. A closure's `use` follows a `)`; a trait's sits in
     * a class body, which the brace depth answers.
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
     * @param string $name
     * @return bool
     */
    private static function exists(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
    }
}
