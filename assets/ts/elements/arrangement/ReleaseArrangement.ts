import { Tag } from '../../model/Tag.js';

/**
 * <release-arrangement> — how a track is laid out in time.
 *
 * Builds nothing, deliberately, and the reason is the one DownloadList and ReleaseList give: what
 * it wraps is server-rendered, so the arrangement is readable with no JS at all. That mattered more
 * here than anywhere else — every self-building element on this site costs a no-JS visitor the
 * content inside it, and the release page has already spent that on the cover and the player.
 * A list of section names is text; it had no business becoming a script.
 */
export class ReleaseArrangement extends HTMLElement {}

customElements.define(Tag.ReleaseArrangement, ReleaseArrangement);
