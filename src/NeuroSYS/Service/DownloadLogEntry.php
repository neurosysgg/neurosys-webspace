<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use JsonSerializable;
use NeuroSYS\Support\JsonDeserializable;
use stdClass;
use Stringable;

/**
 * The DownloadLogEntry class. An immutable value object representing a single download log event.
 *
 * Implements {@link JsonSerializable} + {@link JsonDeserializable} for symmetric JSON codec,
 * and {@link Stringable} so instances can be written directly to a file or echoed.
 */
readonly class DownloadLogEntry implements JsonSerializable, JsonDeserializable, Stringable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $time     ISO-8601 timestamp.
     * @param string $slug     The release slug.
     * @param string $format   The format identifier (e.g. 'flac', 'mp3').
     * @param string $referrer The HTTP Referer header value, or empty string if absent.
     */
    public function __construct(
        public string $time,
        public string $slug,
        public string $format,
        public string $referrer,
    ) {}

    /**
     * Serializes the entry to a JSON string.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /** @return array{time: string, slug: string, format: string, referrer: string} */
    public function jsonSerialize(): array
    {
        return [
            'time'     => $this->time,
            'slug'     => $this->slug,
            'format'   => $this->format,
            'referrer' => $this->referrer,
        ];
    }

    /**
     * Builds an entry from one log line, or null if that line is not one.
     *
     * The only place on this site where a value object is built out of text nothing on this side
     * wrote, so it is the only place the shape has to be *checked* rather than known.
     * {@link \NeuroSYS\Controller\StatsController} skips whatever comes back null, and the whole
     * job of this method is to make sure a line it cannot use comes back that way instead of some
     * other way. Two things it used to get wrong:
     *
     * - Decoding was `assoc: true`, which renders `{}` and `[]` as the same empty array — so a log
     *   line of `[1,2,3]` passed the `is_array()` guard and hydrated into an entry of four empty
     *   strings, counted in the total and filed under `/`. Corrupt input read as real data.
     *   Decoding to an object distinguishes the two, because an object is what an entry is.
     * - Nothing checked the *values*. A field holding a number, a bool or a nested object went
     *   straight to a `string`-typed constructor, and under `strict_types=1` that is an uncaught
     *   TypeError — so a single malformed line took the entire stats page down with a 500 rather
     *   than being skipped, which is the opposite of what the caller asks for.
     *
     * A *missing* field is still an empty one. That is deliberate and older than this note; see the
     * test named for it. Present-but-wrong-type is the different case, and it is refused.
     *
     * @param string $json
     * @return ?static
     */
    public static function fromJson(string $json): ?static
    {
        $data = json_decode($json);

        if (!$data instanceof stdClass) {
            return null;
        }

        $time     = $data->time     ?? '';
        $slug     = $data->slug     ?? '';
        $format   = $data->format   ?? '';
        $referrer = $data->referrer ?? '';

        if (!is_string($time) || !is_string($slug) || !is_string($format) || !is_string($referrer)) {
            return null;
        }

        return new static(time: $time, slug: $slug, format: $format, referrer: $referrer);
    }
}
