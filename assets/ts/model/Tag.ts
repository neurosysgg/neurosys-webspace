/**
 * Mirrors NeuroSYS\View\Html\Tag — every custom element the site emits or builds.
 *
 * The server writes these tag names and this side registers and creates them, so they are the same
 * fact stated twice. test/js/enum-parity.test.mjs compares the two: a tag renamed on one side only
 * is an element the browser has never heard of, which renders as an inert inline box with no error.
 */
export enum Tag {
  SoundCloudPlayer  = 'soundcloud-player',
  SoundCloudProfile = 'soundcloud-profile',

  CoverArt = 'cover-art',

  TerminalWindow  = 'terminal-window',
  TerminalCommand = 'terminal-command',
  TerminalField   = 'terminal-field',
  TerminalKey     = 'terminal-key',
  TerminalValue   = 'terminal-value',
  TerminalCursor  = 'terminal-cursor',

  DownloadList  = 'download-list',
  DownloadCard  = 'download-card',
  DownloadLabel = 'download-label',
  DownloadMeta  = 'download-meta',

  ReleaseList  = 'release-list',
  ReleaseCard  = 'release-card',
  ReleaseTitle = 'release-title',
  ReleaseMeta  = 'release-meta',
}
