<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The HtmlTag enum. The standard HTML elements this site emits.
 *
 * Not every element that exists — only the ones actually used, the same way {@link HtmlAttribute}
 * and {@link \NeuroSYS\Http\Security\PermissionsPolicyFeature} list what is used rather than what is
 * possible. Adding markup that needs a new element means adding its case, which is the moment to ask
 * whether it should be one of ours instead — see {@link Tag}.
 *
 * "Used" means by either side. `<iframe>`, `<small>`, `<div>` and `<textarea>` are only ever created
 * by the client, but they are elements this site emits all the same, and `assets/ts/model/HtmlTag.ts`
 * mirrors this list so both halves agree on every one.
 */
enum HtmlTag: string implements TagName
{
    case Html   = 'html';
    case Head   = 'head';
    case Meta   = 'meta';
    case Link   = 'link';
    case Title  = 'title';
    case Script = 'script';
    case Body   = 'body';

    case Header  = 'header';
    case Nav     = 'nav';
    case Main    = 'main';
    case Footer  = 'footer';
    case Section = 'section';

    case H1 = 'h1';
    case H2 = 'h2';
    case H3 = 'h3';
    case P  = 'p';
    case Br = 'br';

    case A      = 'a';
    case Img    = 'img';
    case Button = 'button';
    case Span   = 'span';
    case Small  = 'small';
    case Strong = 'strong';
    case Div    = 'div';

    /** Created client-side only: the player's frame, and the textarea that decodes entities. */
    case Iframe   = 'iframe';
    case Textarea = 'textarea';

    case Table = 'table';
    case Tr    = 'tr';
    case Td    = 'td';

    /**
     * @return string
     */
    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isVoid(): bool
    {
        return match ($this) {
            self::Meta, self::Link, self::Img, self::Br => true,
            default                                     => false,
        };
    }
}
