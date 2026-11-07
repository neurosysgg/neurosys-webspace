<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Data;

use BackedEnum;
use Closure;
use JsonException;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Site;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use Phpanta\Text\Translation;
use Phpanta\Text\Verbatim;
use ReflectionClass;
use stdClass;

/**
 * The Record class. One JSON object out of a data file, read field by field with its type checked.
 *
 * **Every refusal names where it is**: `data/releases.json: ill.formats[2].type: …`, because the
 * file is written by hand as often as by a tool, and a page that fails to load with "expected a
 * string" and no line would be worse than the PHP it replaced, whose parse errors had one. A refusal
 * is a {@link ReleaseVerificationException}, the kind every model's own constructor throws, so a
 * caller meets one exception for "this data is wrong" whichever layer noticed.
 *
 * Strict in the direction that matters: a field of the wrong type is refused rather than cast, and
 * a missing required field is refused rather than defaulted. A key no codec asks for is ignored, so
 * a file a newer tool wrote still reads.
 */
final readonly class Record
{
    /** How deep a data file may nest: a release's arrangement's section is four objects down. */
    private const int MAX_DEPTH = 16;

    /** What every data file is written with, so a tool's write and the file it replaces diff cleanly. */
    private const int ENCODING = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param stdClass $values The object.
     * @param string   $where  Where it is, for a refusal: the file, then the path inside it.
     */
    private function __construct(private stdClass $values, public string $where) {}

    /**
     * The object $json is.
     *
     * @param string $json
     * @param string $where The file it came from.
     * @return self
     * @throws ReleaseVerificationException if $json is not JSON, or not an object.
     */
    public static function parse(string $json, string $where): self
    {
        try {
            $values = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ReleaseVerificationException(sprintf('%s: not JSON (%s).', $where, $e->getMessage()));
        }

        if (!$values instanceof stdClass) {
            throw new ReleaseVerificationException(sprintf('%s: must be an object.', $where));
        }

        return new self($values, $where);
    }

    /**
     * $values as a data file holds it: pretty-printed, slashes and Unicode as themselves, a newline
     * at the end.
     *
     * @param mixed $values What a codec built.
     * @return string
     * @throws ReleaseVerificationException if something in it cannot be JSON.
     */
    public static function encode(mixed $values): string
    {
        try {
            return json_encode($values, self::ENCODING) . "\n";
        } catch (JsonException $e) {
            throw new ReleaseVerificationException('The data cannot be written as JSON: ' . $e->getMessage());
        }
    }

    /**
     * Each member of this object that is an object itself, keyed by its name — a data file's
     * entries, keyed by slug, in the order the file writes them.
     *
     * @return SearchableCollection<self>
     * @throws ReleaseVerificationException if a member is not an object.
     */
    public function entries(): SearchableCollection
    {
        $entries = new SearchableCollection(self::class);

        foreach (get_object_vars($this->values) as $name => $value) {
            $where = $this->where . ': ' . $name;
            if (!$value instanceof stdClass) {
                throw new ReleaseVerificationException(sprintf('%s: must be an object.', $where));
            }
            $entries = $entries->with((string) $name, new self($value, $where));
        }

        return $entries;
    }

    /**
     * Whether $key is written at all — null included, which says something a missing key does not.
     *
     * @param RecordKey $key
     * @return bool
     */
    public function present(RecordKey $key): bool
    {
        return property_exists($this->values, $key->value);
    }

    /**
     * @param RecordKey $key
     * @return string
     * @throws ReleaseVerificationException if it is missing or not a string.
     */
    public function string(RecordKey $key): string
    {
        return $this->optionalString($key) ?? throw $this->refusal($key, 'is required');
    }

    /**
     * @param RecordKey $key
     * @return string|null Null where it is missing or null.
     * @throws ReleaseVerificationException if it is anything but a string.
     */
    public function optionalString(BackedEnum $key): ?string
    {
        $value = $this->values->{(string) $key->value} ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->refusal($key, 'must be a string');
        }

        return $value;
    }

    /**
     * @param RecordKey $key
     * @return int
     * @throws ReleaseVerificationException if it is missing or not a whole number.
     */
    public function int(RecordKey $key): int
    {
        return $this->optionalInt($key) ?? throw $this->refusal($key, 'is required');
    }

    /**
     * @param RecordKey $key
     * @return int|null Null where it is missing or null.
     * @throws ReleaseVerificationException if it is anything but a whole number.
     */
    public function optionalInt(RecordKey $key): ?int
    {
        $value = $this->values->{$key->value} ?? null;
        if ($value !== null && !is_int($value)) {
            throw $this->refusal($key, 'must be a whole number');
        }

        return $value;
    }

    /**
     * The case of $enum that $key names by its value.
     *
     * @template E of BackedEnum
     * @param RecordKey       $key
     * @param class-string<E> $enum
     * @return E
     * @throws ReleaseVerificationException if it is missing or names no case.
     */
    public function enum(RecordKey $key, string $enum): BackedEnum
    {
        return $this->optionalEnum($key, $enum) ?? throw $this->refusal($key, 'is required');
    }

    /**
     * @template E of BackedEnum
     * @param RecordKey       $key
     * @param class-string<E> $enum
     * @return E|null Null where it is missing or null.
     * @throws ReleaseVerificationException if it names no case.
     */
    public function optionalEnum(RecordKey $key, string $enum): ?BackedEnum
    {
        $value = $this->optionalString($key);

        return $value === null ? null : $enum::tryFrom($value) ?? throw $this->refusal(
            $key,
            sprintf("names no %s ('%s')", new ReflectionClass($enum)->getShortName(), $value),
        );
    }

    /**
     * @param RecordKey $key
     * @return self|null Null where it is missing or null.
     * @throws ReleaseVerificationException if it is anything but an object.
     */
    public function optionalRecord(RecordKey $key): ?self
    {
        $value = $this->values->{$key->value} ?? null;
        if ($value === null) {
            return null;
        }
        if (!$value instanceof stdClass) {
            throw $this->refusal($key, 'must be an object');
        }

        return new self($value, $this->at($key));
    }

    /**
     * Each object in the list $key holds, in order.
     *
     * @param RecordKey $key
     * @return Collection<self> Empty where it is missing or null.
     * @throws ReleaseVerificationException if it is not a list of objects.
     */
    public function records(RecordKey $key): Collection
    {
        $records = new Collection(self::class);

        foreach ($this->list($key) as $i => $value) {
            $where = sprintf('%s[%d]', $this->at($key), $i);
            if (!$value instanceof stdClass) {
                throw new ReleaseVerificationException(sprintf('%s: must be an object.', $where));
            }
            $records = $records->with(new self($value, $where));
        }

        return $records;
    }

    /**
     * Each string in the list $key holds, made into a T.
     *
     * @template T of object
     * @param RecordKey         $key
     * @param class-string<T>   $type
     * @param Closure(string):T $make
     * @return Collection<T> Empty where it is missing or null.
     * @throws ReleaseVerificationException if it is not a list of strings.
     */
    public function strings(RecordKey $key, string $type, Closure $make): Collection
    {
        $made = new Collection($type);

        foreach ($this->list($key) as $i => $value) {
            if (!is_string($value)) {
                throw new ReleaseVerificationException(sprintf('%s[%d]: must be a string.', $this->at($key), $i));
            }
            $made = $made->with($make($value));
        }

        return $made;
    }

    /**
     * What $key says: a plain string, which reads the same in every language, or an object with a
     * text per language the site offers.
     *
     * **The default language is required and the others may fall back to it**, the rule release
     * descriptions have always had: they are written by whoever releases the track, possibly before
     * the German exists. A language the site does not offer is refused, since it would be a text
     * nothing ever shows.
     *
     * @param RecordKey $key
     * @return Translatable|null Null where it is missing or null.
     * @throws ReleaseVerificationException if it is neither, or an object leaves the default out.
     */
    public function text(RecordKey $key): ?Translatable
    {
        $value = $this->values->{$key->value} ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return new Verbatim($value);
        }
        if (!$value instanceof stdClass) {
            throw $this->refusal($key, 'must be a string, or an object with a text per language');
        }

        $languages = Site::current()->languages();
        $words     = new self($value, $this->at($key));
        $texts     = [];
        foreach (get_object_vars($value) as $tag => $text) {
            if ($languages->tryFrom((string) $tag) === null) {
                throw new ReleaseVerificationException(sprintf(
                    "%s: '%s' is not a language the site offers.",
                    $words->where,
                    $tag,
                ));
            }
        }
        foreach ($languages->offered() as $language) {
            /** @var Language $language */
            $text = $words->optionalString($language);
            if ($text !== null) {
                $texts[$language->value] = $text;
            }
        }
        if (!isset($texts[$languages->default()->value])) {
            throw $words->refusal($languages->default(), 'is required: the other languages fall back to it');
        }

        return new Translation(...$texts);
    }

    /**
     * $text as {@link self::text()} reads it back: a string for a text that reads the same in every
     * language, an object of a text per offered language for any other — only the ones it writes,
     * where it is a {@link Translation}, so a German left to fall back stays left.
     *
     * @param Translatable $text
     * @return string|stdClass
     */
    public static function textValue(Translatable $text): string|stdClass
    {
        if ($text instanceof Verbatim) {
            return $text->text;
        }

        $words = new stdClass();
        foreach (Site::current()->languages()->offered() as $language) {
            /** @var Language $language */
            $written = $text instanceof Translation ? $text->{$language->value} : $text->in($language);
            if ($written !== null) {
                $words->{$language->value} = $written;
            }
        }

        return $words;
    }

    /**
     * Where $key is inside this object.
     *
     * @param BackedEnum $key
     * @return string
     */
    private function at(BackedEnum $key): string
    {
        return $this->where . '.' . $key->value;
    }

    /**
     * The list $key holds.
     *
     * @param RecordKey $key
     * @return list<mixed>
     * @throws ReleaseVerificationException if it is not a list.
     */
    #[BareArray('a door: the list json_decode() hands back, walked once into a Collection by its two callers')]
    private function list(RecordKey $key): array
    {
        $value = $this->values->{$key->value} ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->refusal($key, 'must be a list');
        }

        return $value;
    }

    /**
     * @param BackedEnum $key
     * @param string               $what What is wrong with it.
     * @return ReleaseVerificationException
     */
    private function refusal(BackedEnum $key, string $what): ReleaseVerificationException
    {
        return new ReleaseVerificationException(sprintf('%s: %s.', $this->at($key), $what));
    }
}
