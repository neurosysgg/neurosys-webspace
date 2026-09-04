<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The CssClass enum. Every class name the site puts on an element.
 *
 * The last silent-failure reader in the markup. A misspelled class does not error anywhere — the
 * element simply renders unstyled, which on a dark page can look like a layout bug rather than a
 * typo. Both sides write these: the views here, and `<soundcloud-player>`'s gate button on the
 * client, so `assets/ts/model/CssClass.ts` mirrors it.
 *
 * Unusually, this one is testable in both directions. `HtmlTest` parses `style.css` and asserts the
 * two sets match exactly: a class here that the stylesheet never mentions is an element styled by
 * nothing, and a selector there that no case names is a rule nothing can match.
 */
enum CssClass: string
{
    case SiteHeader = 'site-header';
    case SiteNav    = 'site-nav';
    case SiteFooter = 'site-footer';
    case Logo       = 'logo';
    case LogoDot    = 'logo-dot';

    case ProfileLinks = 'profile-links';
    case ProfileLink  = 'profile-link';

    case PageSection = 'page-section';
    case PageHeading = 'page-heading';
    case BtnPrimary  = 'btn-primary';
    case Muted       = 'muted';
    case Bang        = 'bang';

    case HomeHero    = 'home-hero';
    case HomeEyebrow = 'home-eyebrow';
    case HomeTitle   = 'home-title';

    case Hero        = 'hero';
    case ReleaseInfo = 'release-info';
    case Tagline     = 'tagline';
    case BackHome    = 'back-home';

    case StatsSub   = 'stats-sub';
    case StatsTable = 'stats-table';
    case StatsCount = 'stats-count';
}
