<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The HtmlAttribute enum. The standard HTML attributes this site emits.
 *
 * `class` is {@link self::ClassName} rather than `Class`, because `HtmlAttribute::Class` parses as
 * the `::class` magic constant and would quietly evaluate to the enum's own name — a case where the
 * wrong thing compiles, which is exactly what this file exists to prevent.
 */
enum HtmlAttribute: string implements AttributeName
{
    case ClassName = 'class';
    case Id        = 'id';
    case Lang      = 'lang';
    case Title     = 'title';

    case Href   = 'href';
    case Src    = 'src';
    case Rel    = 'rel';
    case Target = 'target';
    case Type   = 'type';

    case Alt     = 'alt';
    case Height  = 'height';
    case Width   = 'width';
    case Charset = 'charset';
    case Name    = 'name';
    case Content = 'content';

    case AriaLabel = 'aria-label';

    public function attribute(): string
    {
        return $this->value;
    }

    /** `href` and `src` are the two the browser dereferences; the rest are values it reads. */
    public function isUrl(): bool
    {
        return match ($this) {
            self::Href, self::Src => true,
            default              => false,
        };
    }
}
