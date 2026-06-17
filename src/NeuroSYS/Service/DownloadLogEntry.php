<?php
declare(strict_types=1);

namespace NeuroSYS\Service;

use JsonSerializable;
use NeuroSYS\Support\JsonDeserializable;
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

    /** Serializes the entry to a JSON string. */
    public function toJson(): string
    {
        return json_encode($this, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

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

    public static function fromJson(string $json): ?static
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return new static(
            time:     $data['time']     ?? '',
            slug:     $data['slug']     ?? '',
            format:   $data['format']   ?? '',
            referrer: $data['referrer'] ?? '',
        );
    }
}
