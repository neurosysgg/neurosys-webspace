<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Http;

use NeuroSYS\Http\Header;
use NeuroSYS\Http\HeaderName;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\TopLevelType;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;

/**
 * The Request class. One request this tooling is about to send.
 *
 * The site's {@link \NeuroSYS\Http\Request} names the same thing pointing the other way — one is a
 * request the site *received* and answers, this is one a command *sends* and reads the answer to.
 * Neither is the other's inverse and neither shares a line of code with it; they share a word,
 * because there is only one word.
 *
 * Three factories rather than a constructor, because there are exactly three shapes of request in
 * this repo and each carries its body differently: a bare `GET`, a form-encoded body for the token
 * exchange, and a multipart body for the upload. A fourth shape is a fourth factory, and until
 * something needs one there is no fourth to get wrong.
 *
 * {@link HttpMethod} is the site's enum, reused rather than restated. Its docblock is written about
 * the methods the site *answers*, and the vocabulary is the same one either way round.
 */
final readonly class Request
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpMethod $method
     * @param string     $url
     * @param SearchableCollection<Header> $headers Keyed by header name, which is what keeps the
     *                                       last write: a request cannot end up carrying two
     *                                       `Authorization`s because two callers each added one.
     * @param Collection<FormField> $fields The body. Empty for a request that has none.
     * @param bool       $multipart Whether $fields go out as `multipart/form-data`. A
     *                              {@link FilePart} is only meaningful when this is true.
     */
    private function __construct(
        public HttpMethod $method,
        public string     $url,
        public SearchableCollection $headers,
        public Collection $fields,
        public bool       $multipart,
    ) {}

    /**
     * A request with no body.
     *
     * @param string $url
     * @param Header ...$headers
     * @return self
     */
    public static function get(string $url, Header ...$headers): self
    {
        return new self(
            HttpMethod::Get,
            $url,
            self::headers(...$headers),
            new Collection(FormField::class),
            false,
        );
    }

    /**
     * A `POST` whose body is form-encoded — the shape every OAuth token request takes.
     *
     * @param string $url
     * @param Collection<FormField> $fields
     * @param Header ...$headers
     * @return self
     */
    public static function form(string $url, Collection $fields, Header ...$headers): self
    {
        return new self(
            HttpMethod::Post,
            $url,
            self::headers(
                new Header(
                    OutboundHeader::ContentType,
                    new MimeType(TopLevelType::Application, 'x-www-form-urlencoded', null),
                ),
                ...$headers,
            ),
            $fields,
            false,
        );
    }

    /**
     * A `POST` whose body is multipart, because one of its fields is a file.
     *
     * No `Content-Type` is set here on purpose: a multipart body carries a boundary, the boundary
     * belongs to whatever assembles the body, and a header written beside it would be a second
     * answer to a settled question — see {@link OutboundHeader::ContentType}.
     *
     * @param string $url
     * @param Collection<FormField> $fields
     * @param Header ...$headers
     * @return self
     */
    public static function multipart(string $url, Collection $fields, Header ...$headers): self
    {
        return new self(HttpMethod::Post, $url, self::headers(...$headers), $fields, true);
    }

    /**
     * The form-encoded body, for a request that has one.
     *
     * @return string
     */
    public function body(): string
    {
        $pairs = [];

        foreach ($this->fields as $field) {
            if (!$field->isFile()) {
                $pairs[$field->name] = $field->value;
            }
        }

        return http_build_query($pairs);
    }

    /**
     * One header's value, or null where the request does not carry it.
     *
     * Here so that a fake {@link Transport} can assert what went out without reaching into an
     * array by a string literal, which is the spelling mistake this repo types away everywhere
     * else.
     *
     * @param HeaderName $name
     * @return Header|null
     */
    public function header(HeaderName $name): ?Header
    {
        return $this->headers->find($name->headerName());
    }

    /**
     * The headers, keyed by name.
     *
     * @param Header ...$headers
     * @return SearchableCollection<Header>
     */
    private static function headers(Header ...$headers): SearchableCollection
    {
        $collection = new SearchableCollection(Header::class);

        foreach ($headers as $header) {
            $collection = $collection->with($header->name->headerName(), $header);
        }

        return $collection;
    }
}
