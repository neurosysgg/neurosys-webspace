import { Platform, displayName } from '../../model/Platform.js';
import { SoundCloudOption } from '../../model/SoundCloudOption.js';
import { SoundCloudPlayerAttribute } from '../../model/SoundCloudPlayerAttribute.js';
import { SoundCloudPlayerStyle, isVisual } from '../../model/SoundCloudPlayerStyle.js';
import { Tag } from '../../model/Tag.js';
import { ConsentGatedEmbed } from './ConsentGatedEmbed.js';

/**
 * <soundcloud-player track-id permalink secret-token player-style options track-title height>
 *
 * The provider's furniture lives here, not in the server's output. ReleaseView sends the release's
 * facts as typed attributes — which track, which layout, which toggles — and this builds the widget
 * URL and the attribution block SoundCloud's own embed dialog produces.
 *
 * The generated markup stays deliberately identical to that dialog's: same query parameters in the
 * same order, same attribution, same styling. Both are their furniture, not ours; see
 * docs/branding.md for the same stance on brand assets. The styles are applied through the CSSOM
 * rather than a style attribute, which is what lets the page's own style-src stay strict.
 */
export class SoundCloudPlayer extends ConsentGatedEmbed {
  /**
   * The player accent, as SoundCloud's `color` parameter wants it.
   *
   * Intentionally *not* the site's --accent (#6a00ff), which reads as near-black against the
   * player's own dark chrome. This is a lighter purple picked to sit in the same family while
   * staying legible on SoundCloud's background.
   */
  private static readonly ACCENT = '#9e55e6';

  /** The artist profile the attribution block credits and links to. */
  private static readonly ARTIST_HANDLE = 'neurosysgg';
  private static readonly ARTIST_NAME   = 'neuro.SYS';

  /** SoundCloud's own attribution styling, reproduced property for property. */
  private static readonly ATTRIBUTION_STYLE: Partial<CSSStyleDeclaration> = {
    fontSize:     '10px',
    color:        '#cccccc',
    lineBreak:    'anywhere',
    wordBreak:    'normal',
    overflow:     'hidden',
    whiteSpace:   'nowrap',
    textOverflow: 'ellipsis',
    fontFamily:   'Interstate,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Garuda,Verdana,'
      + 'Tahoma,sans-serif',
    fontWeight:   '100',
  };

  private static readonly ATTRIBUTION_LINK_STYLE: Partial<CSSStyleDeclaration> = {
    color:          '#cccccc',
    textDecoration: 'none',
  };

  protected platform(): Platform {
    return Platform.SoundCloud;
  }

  protected buildEmbed(): DocumentFragment {
    const fragment = document.createDocumentFragment();
    fragment.append(this.buildIframe(), this.buildAttribution());

    return fragment;
  }

  /**
   * Builds the player iframe.
   *
   * `scrolling` and `frameborder` are deprecated HTML attributes with no property to set them, but
   * they are what SoundCloud ships and what is verified working, so they stay.
   */
  private buildIframe(): HTMLIFrameElement {
    const iframe = document.createElement('iframe');

    iframe.width  = '100%';
    iframe.height = this.getAttribute(SoundCloudPlayerAttribute.Height) ?? '';
    iframe.title  = `${this.trackTitle()} on ${displayName(this.platform())}`;
    iframe.src    = this.playerUrl();

    // allow, scrolling and frameborder are set as attributes rather than properties: the last two
    // are deprecated and have no property at all, and `allow` is reflected inconsistently enough
    // that the element tests caught it. What SoundCloud ships is the attribute, either way.
    iframe.setAttribute('allow', 'autoplay; encrypted-media');
    iframe.setAttribute('scrolling', 'no');
    iframe.setAttribute('frameborder', 'no');

    return iframe;
  }

  /**
   * Builds the artist · track credit line SoundCloud's embed carries.
   *
   * SoundCloud asks that embeds keep this attribution, so it renders whether or not ShowUser is on
   * — that toggle governs the player chrome, not the credit.
   */
  private buildAttribution(): HTMLDivElement {
    const credit = document.createElement('div');
    Object.assign(credit.style, SoundCloudPlayer.ATTRIBUTION_STYLE);

    credit.append(
      this.attributionLink(
        `https://soundcloud.com/${SoundCloudPlayer.ARTIST_HANDLE}`,
        SoundCloudPlayer.ARTIST_NAME,
      ),
      ' · ',
      this.attributionLink(this.trackPermalink(), this.trackTitle()),
    );

    return credit;
  }

  /** Builds one attribution link, styled the way SoundCloud styles it. */
  private attributionLink(href: string, text: string): HTMLAnchorElement {
    const link = document.createElement('a');

    link.href        = href;
    link.title       = text;
    link.target      = '_blank';
    link.textContent = text;
    Object.assign(link.style, SoundCloudPlayer.ATTRIBUTION_LINK_STYLE);

    return link;
  }

  /** Builds the widget URL the iframe loads, with every option resolved to true/false. */
  private playerUrl(): string {
    const params  = new URLSearchParams();
    const enabled = (this.getAttribute(SoundCloudPlayerAttribute.Options) ?? '').split(/\s+/);

    params.set('url', this.trackUrl());
    params.set('color', SoundCloudPlayer.ACCENT);

    // Every case, in declaration order — the ones not listed go out as false rather than being
    // omitted, which is what SoundCloud's dialog produces.
    for (const option of Object.values(SoundCloudOption)) {
      params.set(option, String(enabled.includes(option)));
    }

    params.set('visual', String(isVisual(this.playerStyle())));

    return `https://w.soundcloud.com/player/?${params.toString()}`;
  }

  /**
   * Returns the API track reference the player resolves.
   *
   * SoundCloud's dialog emits the `soundcloud:tracks:<id>` URN form rather than a bare id —
   * unusual, but it is what the live embeds use, so it is reproduced as-is.
   */
  private trackUrl(): string {
    const url   = `https://api.soundcloud.com/tracks/soundcloud:tracks:${this.getAttribute(SoundCloudPlayerAttribute.TrackId) ?? ''}`;
    const token = this.secretToken();

    return token === '' ? url : `${url}?secret_token=${token}`;
  }

  /** Returns the public track page the attribution links to. */
  private trackPermalink(): string {
    const url   = `https://soundcloud.com/${SoundCloudPlayer.ARTIST_HANDLE}/${this.getAttribute(SoundCloudPlayerAttribute.Permalink) ?? ''}`;
    const token = this.secretToken();

    return token === '' ? url : `${url}/${token}`;
  }

  private secretToken(): string {
    return this.getAttribute(SoundCloudPlayerAttribute.SecretToken) ?? '';
  }

  private trackTitle(): string {
    return this.getAttribute(SoundCloudPlayerAttribute.TrackTitle) ?? '';
  }

  private playerStyle(): SoundCloudPlayerStyle {
    return this.getAttribute(SoundCloudPlayerAttribute.PlayerStyle) === SoundCloudPlayerStyle.Classic
      ? SoundCloudPlayerStyle.Classic
      : SoundCloudPlayerStyle.Visual;
  }
}

customElements.define(Tag.SoundCloudPlayer, SoundCloudPlayer);
