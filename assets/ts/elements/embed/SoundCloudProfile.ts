import { Config } from '../../Config.js';
import { Tag } from '../../model/Tag.js';
import type { AttributionTarget } from './SoundCloudWidget.js';
import { SoundCloudWidget } from './SoundCloudWidget.js';

/**
 * <soundcloud-profile player-style options height>
 *
 * The whole account rather than one track: SoundCloud's widget resolves a profile URL to the
 * artist's latest tracks, which is what this lists. The client-side half of
 * NeuroSYS\Model\Embed\SoundCloudProfileEmbed.
 *
 * Note what it does *not* receive. There is no id, handle or title attribute, because there is no
 * release to take them from — the artist is Config.HANDLE, which this side already mirrors for the
 * attribution link. So the server sends only the three things that are its business: which layout,
 * which toggles, and how tall the gate reserves. That keeps the guarantee the track player has:
 * nothing in the served page names SoundCloud until the visitor clicks.
 */
export class SoundCloudProfile extends SoundCloudWidget {
  /**
   * The profile page itself is the resource — the widget resolves it to the latest tracks.
   *
   * The same URL the attribution already links to, which is the whole reason no new fact had to be
   * fetched from SoundCloud for this element: no numeric user id, just the handle.
   */
  protected resourceUrl(): string {
    return SoundCloudProfile.PROFILE;
  }

  protected subject(): string {
    return Config.NAME;
  }

  /**
   * None. The attribution credits the artist, and a profile embed *is* the artist — there is no
   * second thing to name, the way a track has its own page.
   */
  protected attributionTarget(): AttributionTarget | null {
    return null;
  }
}

customElements.define(Tag.SoundCloudProfile, SoundCloudProfile);
