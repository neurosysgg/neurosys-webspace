/**
 * Mirror of NeuroSYS\Model\Embed\SoundCloudOption.
 *
 * Each case is backed by the literal query-string key the player reads. SoundCloudPlayer enables
 * exactly the options its `options` attribute lists; every other case is sent as `false` rather
 * than omitted, matching what SoundCloud's own embed dialog produces.
 *
 * Declaration order is the rendered order — the query string is built by iterating these — so
 * test/enum-parity.* compares the two lists in order, not as sets.
 */
export enum SoundCloudOption {
  AutoPlay     = 'auto_play',
  HideRelated  = 'hide_related',
  ShowComments = 'show_comments',
  ShowUser     = 'show_user',
  ShowReposts  = 'show_reposts',
  ShowTeaser   = 'show_teaser',
}
