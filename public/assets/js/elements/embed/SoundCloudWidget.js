import { Platform, displayName } from '../../model/Platform.js';
import { SoundCloudOption } from '../../model/SoundCloudOption.js';
import { SoundCloudPlayerAttribute } from '../../model/SoundCloudPlayerAttribute.js';
import { EmbedAttribute } from '../../model/EmbedAttribute.js';
import { SoundCloudPlayerStyle, isVisual } from '../../model/SoundCloudPlayerStyle.js';
import { Config } from '../../Config.js';
import { HtmlTag } from '../../model/HtmlTag.js';
import { ConsentGatedEmbed } from './ConsentGatedEmbed.js';
/**
 * SoundCloud's furniture, for any resource its widget can resolve.
 *
 * The middle of three layers, and the split is by what varies. ConsentGatedEmbed owns what every
 * provider shares — the gate's wording, the reserved height, the click, the swap. This owns what
 * every *SoundCloud* embed shares — the accent, the attribution block, the iframe, and the widget
 * URL with every option resolved. A subclass owns only its resource: which thing the player plays.
 *
 * That is why this is not called SoundCloudEmbed: on the PHP side that name means the *track*, and
 * this is the layer above either kind. The widget is what SoundCloud calls it too.
 *
 * The generated markup stays deliberately identical to the embed dialog's: same query parameters in
 * the same order, same attribution, same styling. Both are their furniture, not ours; see
 * docs/branding.md for the same stance on brand assets. The styles are applied through the CSSOM
 * rather than a style attribute, which is what lets the page's own style-src stay strict.
 */
export class SoundCloudWidget extends ConsentGatedEmbed {
    /**
     * The player accent, as SoundCloud's `color` parameter wants it.
     *
     * Intentionally *not* the site's --accent (#6a00ff), which reads as near-black against the
     * player's own dark chrome. This is a lighter purple picked to sit in the same family while
     * staying legible on SoundCloud's background.
     */
    static ACCENT = '#9e55e6';
    /**
     * The artist's page on SoundCloud, which the attribution credits and links to.
     *
     * soundcloud.com rather than Config.PLAYER_HOST, and it stays here rather than in Config: for the
     * track player this is only ever a link target, never loaded, so it needs no CSP entry and is not
     * a fact the server shares. The handle it is built from is. <soundcloud-profile> also resolves
     * *through* it — see SoundCloudProfile.resourceUrl() — which is why it is protected.
     */
    static PROFILE = `https://soundcloud.com/${Config.HANDLE}`;
    /** SoundCloud's own attribution styling, reproduced property for property. */
    static ATTRIBUTION_STYLE = {
        fontSize: '10px',
        color: '#cccccc',
        lineBreak: 'anywhere',
        wordBreak: 'normal',
        overflow: 'hidden',
        whiteSpace: 'nowrap',
        textOverflow: 'ellipsis',
        fontFamily: 'Interstate,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Garuda,Verdana,'
            + 'Tahoma,sans-serif',
        fontWeight: '100',
    };
    static ATTRIBUTION_LINK_STYLE = {
        color: '#cccccc',
        textDecoration: 'none',
    };
    platform() {
        return Platform.SoundCloud;
    }
    buildEmbed() {
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
    buildIframe() {
        const iframe = document.createElement(HtmlTag.Iframe);
        iframe.width = '100%';
        iframe.height = this.getAttribute(EmbedAttribute.Height) ?? '';
        iframe.title = `${this.subject()} on ${displayName(this.platform())}`;
        iframe.src = this.playerUrl();
        // allow, scrolling and frameborder are set as attributes rather than properties: the last two
        // are deprecated and have no property at all, and `allow` is reflected inconsistently enough
        // that the element tests caught it. What SoundCloud ships is the attribute, either way.
        iframe.setAttribute('allow', 'autoplay; encrypted-media');
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('frameborder', 'no');
        return iframe;
    }
    /**
     * Builds the credit line SoundCloud's embed carries.
     *
     * SoundCloud asks that embeds keep this attribution, so it renders whether or not ShowUser is on
     * — that toggle governs the player chrome, not the credit.
     */
    buildAttribution() {
        const credit = document.createElement(HtmlTag.Div);
        Object.assign(credit.style, SoundCloudWidget.ATTRIBUTION_STYLE);
        credit.append(this.attributionLink(SoundCloudWidget.PROFILE, Config.NAME));
        const target = this.attributionTarget();
        if (target !== null) {
            credit.append(' · ', this.attributionLink(target.href, target.text));
        }
        return credit;
    }
    /** Builds one attribution link, styled the way SoundCloud styles it. */
    attributionLink(href, text) {
        const link = document.createElement(HtmlTag.A);
        link.href = href;
        link.title = text;
        link.target = '_blank';
        link.textContent = text;
        Object.assign(link.style, SoundCloudWidget.ATTRIBUTION_LINK_STYLE);
        return link;
    }
    /** Builds the widget URL the iframe loads, with every option resolved to true/false. */
    playerUrl() {
        const params = new URLSearchParams();
        const enabled = (this.getAttribute(SoundCloudPlayerAttribute.Options) ?? '').split(/\s+/);
        params.set('url', this.resourceUrl());
        params.set('color', SoundCloudWidget.ACCENT);
        // Every case, in declaration order — the ones not listed go out as false rather than being
        // omitted, which is what SoundCloud's dialog produces.
        for (const option of Object.values(SoundCloudOption)) {
            params.set(option, String(enabled.includes(option)));
        }
        params.set('visual', String(isVisual(this.playerStyle())));
        return `${Config.PLAYER_HOST}/player/?${params.toString()}`;
    }
    playerStyle() {
        return this.getAttribute(SoundCloudPlayerAttribute.PlayerStyle) === SoundCloudPlayerStyle.Classic
            ? SoundCloudPlayerStyle.Classic
            : SoundCloudPlayerStyle.Visual;
    }
}
//# sourceMappingURL=SoundCloudWidget.js.map