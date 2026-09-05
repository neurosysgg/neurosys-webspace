import { Platform, displayName } from '../../model/Platform.js';
import { SoundCloudOption } from '../../model/SoundCloudOption.js';
import { SoundCloudPlayerAttribute } from '../../model/SoundCloudPlayerAttribute.js';
import { EmbedAttribute } from '../../model/EmbedAttribute.js';
import { SoundCloudPlayerStyle, isVisual } from '../../model/SoundCloudPlayerStyle.js';
import { Config } from '../../Config.js';
import { HtmlTag } from '../../model/HtmlTag.js';
import { ConsentGatedEmbed } from './ConsentGatedEmbed.js';
export class SoundCloudWidget extends ConsentGatedEmbed {
    static ACCENT = '#9e55e6';
    static PROFILE = `https://soundcloud.com/${Config.HANDLE}`;
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
    buildIframe() {
        const iframe = document.createElement(HtmlTag.Iframe);
        iframe.width = '100%';
        iframe.height = this.getAttribute(EmbedAttribute.Height) ?? '';
        iframe.title = `${this.subject()} on ${displayName(this.platform())}`;
        iframe.src = this.playerUrl();
        iframe.setAttribute('allow', 'autoplay; encrypted-media');
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('frameborder', 'no');
        return iframe;
    }
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
    attributionLink(href, text) {
        const link = document.createElement(HtmlTag.A);
        link.href = href;
        link.title = text;
        link.target = '_blank';
        link.textContent = text;
        Object.assign(link.style, SoundCloudWidget.ATTRIBUTION_LINK_STYLE);
        return link;
    }
    playerUrl() {
        const params = new URLSearchParams();
        const enabled = (this.getAttribute(SoundCloudPlayerAttribute.Options) ?? '').split(/\s+/);
        params.set('url', this.resourceUrl());
        params.set('color', SoundCloudWidget.ACCENT);
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