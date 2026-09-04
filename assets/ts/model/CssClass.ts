/**
 * Mirrors NeuroSYS\View\Html\CssClass — every class name the site puts on an element.
 *
 * A misspelled class errors nowhere; the element just renders unstyled. The PHP side additionally
 * checks these against style.css, in both directions.
 */
export enum CssClass {
  SiteHeader   = 'site-header',
  SiteNav      = 'site-nav',
  SiteFooter   = 'site-footer',
  Logo         = 'logo',
  LogoDot      = 'logo-dot',
  ProfileLinks = 'profile-links',
  ProfileLink  = 'profile-link',
  PageSection  = 'page-section',
  PageHeading  = 'page-heading',
  BtnPrimary   = 'btn-primary',
  Muted        = 'muted',
  Bang         = 'bang',
  HomeHero     = 'home-hero',
  HomeEyebrow  = 'home-eyebrow',
  HomeTitle    = 'home-title',
  Hero         = 'hero',
  ReleaseInfo  = 'release-info',
  Tagline      = 'tagline',
  BackHome     = 'back-home',
  StatsSub     = 'stats-sub',
  StatsTable   = 'stats-table',
  StatsCount   = 'stats-count',
}
