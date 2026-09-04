<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Exception\MimeTypeException;
use NeuroSYS\Support\Charset;

/**
 * The MimeType class. What a response body is: a type, a subtype, and the encoding it is in.
 *
 * A class rather than an enum because the value carries a parameter — the same reasoning
 * {@link Security\StrictTransportSecurity} records for carrying a number. The enum this replaced
 * held `text/html` as one opaque string and stapled `; charset=utf-8` onto every case, which said
 * the quiet part out loud: what was being modelled was never the type, it was the type *and its
 * charset*, and an enum case cannot hold the second one. So the parts are typed and separate here,
 * the way {@link Header} is a {@link HeaderName} beside a value rather than one string carrying
 * both.
 *
 * The charset is still the half that earns it. A browser told a document's bytes are text but not
 * which encoding has to decide for itself; `X-Content-Type-Options: nosniff` stops it guessing the
 * *type*, and nothing stops it guessing the encoding.
 *
 * {@link ViewResponse} used to send no `Content-Type` at all and inherit PHP's `default_mimetype`
 * and `default_charset` ini settings, which happen to be right. That is a fact about the runtime,
 * not about this code, and it was the only response on the site whose headers were not written
 * down anywhere — awkward in particular for the AJAX fragment, which carries no charset
 * declaration of its own, so the header is all a browser has to go on.
 */
final readonly class MimeType
{
    /**
     * A subtype: an alphanumeric first character, then alphanumerics and the three separators real
     * subtypes use — `svg+xml`, `vnd.api+json`, `x-www-form-urlencoded` — up to the 127 characters
     * the registry allows.
     *
     * Narrower than RFC 9110's token grammar on purpose, the way {@link Security\CspHost} is
     * narrower than a URL. A token may legally hold half a dozen punctuation characters that no
     * registered subtype has ever used; a value carrying one is a paste that went wrong, and there
     * is more to be had from saying so than from accepting it.
     */
    private const string SUBTYPE_PATTERN = '#^[a-z0-9][a-z0-9+._-]{0,126}$#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param TopLevelType $type    The half before the slash. `text` for everything sent here.
     * @param string       $subtype The half after it, alone: `html`, not `text/html` and not
     *                              `html; q=1`.
     * @param Charset|null $charset The encoding, rendered as the `charset` parameter. Defaults to
     *                              the site's one {@link Charset}, because every body sent here is
     *                              text and a body of text with no stated encoding is the mistake
     *                              this class exists to prevent. Null for a type that has no
     *                              encoding to declare, which is most of them — `image/png` is
     *                              bytes, not characters.
     *
     * @throws MimeTypeException if $subtype is not a subtype.
     */
    public function __construct(
        public TopLevelType $type,
        public string       $subtype,
        public ?Charset     $charset = Charset::Utf8,
    ) {
        $this->verify();
    }

    /** A page, or the fragment of one {@link ViewResponse} sends the SPA router. */
    public static function html(): self
    {
        return new self(TopLevelType::Text, 'html');
    }

    /** A 405 refusal, or a download that has no file behind it yet. */
    public static function plainText(): self
    {
        return new self(TopLevelType::Text, 'plain');
    }

    /** Returns the `Content-Type` value: the type, and the encoding if there is one to declare. */
    public function render(): string
    {
        $essence = $this->type->value . '/' . $this->subtype;

        return $this->charset === null ? $essence : $essence . '; charset=' . $this->charset->value;
    }

    /**
     * @throws MimeTypeException
     */
    private function verify(): void
    {
        if (preg_match(self::SUBTYPE_PATTERN, $this->subtype) !== 1) {
            throw new MimeTypeException(sprintf(
                "MimeType::\$subtype must be a bare subtype like 'html', got '%s'. "
                . 'It is the half after the slash and carries nothing else: no slash of its own, '
                . 'and no parameter — the charset is a separate argument.',
                $this->subtype,
            ));
        }
    }
}
