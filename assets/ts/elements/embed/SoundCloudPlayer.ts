import { SoundCloudPlayerAttribute } from '../../model/SoundCloudPlayerAttribute.js';
import { Tag } from '../../model/Tag.js';
import type { AttributionTarget } from './SoundCloudWidget.js';
import { SoundCloudWidget } from './SoundCloudWidget.js';

/**
 * <soundcloud-player track-id permalink secret-token player-style options track-title height>
 *
 * One track, the client-side half of NeuroSYS\Model\Embed\SoundCloudEmbed. ReleaseView sends the
 * release's facts as typed attributes — which track, which layout, which toggles — and everything
 * built from them lives in SoundCloudWidget, which this narrows to a single track.
 *
 * The URN form of the resource URL is the unusual part and is deliberate: SoundCloud's own dialog
 * emits `soundcloud:tracks:<id>` rather than a bare id, and it is what the live embeds use.
 */
export class SoundCloudPlayer extends SoundCloudWidget {
  /**
   * Returns the API track reference the player resolves.
   *
   * SoundCloud's dialog emits the `soundcloud:tracks:<id>` URN form rather than a bare id —
   * unusual, but it is what the live embeds use, so it is reproduced as-is.
   */
  protected resourceUrl(): string {
    const url   = `https://api.soundcloud.com/tracks/soundcloud:tracks:${this.getAttribute(SoundCloudPlayerAttribute.TrackId) ?? ''}`;
    const token = this.secretToken();

    return token === '' ? url : `${url}?secret_token=${token}`;
  }

  protected subject(): string {
    return this.trackTitle();
  }

  /** A track credits the artist and then the track, which is the page the title links to. */
  protected attributionTarget(): AttributionTarget {
    return { href: this.trackPermalink(), text: this.trackTitle() };
  }

  /** Returns the public track page the attribution links to. */
  private trackPermalink(): string {
    const url   = `${SoundCloudPlayer.PROFILE}/${this.getAttribute(SoundCloudPlayerAttribute.Permalink) ?? ''}`;
    const token = this.secretToken();

    return token === '' ? url : `${url}/${token}`;
  }

  private secretToken(): string {
    return this.getAttribute(SoundCloudPlayerAttribute.SecretToken) ?? '';
  }

  private trackTitle(): string {
    return this.getAttribute(SoundCloudPlayerAttribute.TrackTitle) ?? '';
  }
}

customElements.define(Tag.SoundCloudPlayer, SoundCloudPlayer);
