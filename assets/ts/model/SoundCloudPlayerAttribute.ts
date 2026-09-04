/**
 * Mirrors NeuroSYS\Model\Embed\SoundCloudPlayerAttribute — what the server tells the player.
 *
 * These names are the whole interface between the two halves of the player. A typo on either side
 * is a silent null: a widget URL with no track, or an iframe with no height.
 */
export enum SoundCloudPlayerAttribute {
  TrackId     = 'track-id',
  Permalink   = 'permalink',
  SecretToken = 'secret-token',
  PlayerStyle = 'player-style',
  Options     = 'options',
  TrackTitle  = 'track-title',
  Height      = 'height',
}
