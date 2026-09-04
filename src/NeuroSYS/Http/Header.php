<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The Header class. One response header, ready to send.
 *
 * Replaces the raw `'Allow: GET, HEAD'` a response used to be handed — a string carrying both the
 * name and the value, with nothing checking either. The name is a {@link HeaderName} case, and the
 * `Name: value` formatting happens here rather than at each `header()` call.
 */
final readonly class Header
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HeaderName $name  The header to send.
     * @param string     $value Its value.
     */
    public function __construct(
        public HeaderName $name,
        public string     $value,
    ) {}

    /** Formats the header for {@link \header()}: `Name: value`. */
    public function line(): string
    {
        return $this->name->headerName() . ': ' . $this->value;
    }
}
