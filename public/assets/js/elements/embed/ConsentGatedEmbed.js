import { Config } from '../../Config.js';
import { CssClass } from '../../model/CssClass.js';
import { CustomProperty } from '../../model/CustomProperty.js';
import { EmbedAttribute } from '../../model/EmbedAttribute.js';
import { HtmlTag } from '../../phpanta/model/HtmlTag.js';
import { Language, pageLanguage } from '../../phpanta/model/Language.js';
import { Platform, displayName } from '../../model/Platform.js';
const GATE = {
    [Language.English]: {
        label: (provider) => `${provider} player`,
        load: 'Load player',
        hint: (provider) => `Third-party content — clicking connects you to ${provider}’s servers.`,
    },
    [Language.German]: {
        label: (provider) => `${provider}-Player`,
        load: 'Player laden',
        hint: (provider) => `Inhalte von Drittanbietern — ein Klick verbindet dich mit den Servern von ${provider}.`,
    },
};
export class ConsentGatedEmbed extends HTMLElement {
    wired = false;
    connectedCallback() {
        if (this.wired)
            return;
        this.wired = true;
        this.reserveHeight();
        this.renderGate();
    }
    reserveHeight() {
        const height = this.getAttribute(EmbedAttribute.Height);
        if (height === null || height === '')
            return;
        this.style.setProperty(CustomProperty.PlayerHeight, `${height}px`);
    }
    renderGate() {
        const provider = displayName(this.platform());
        const words = GATE[pageLanguage(Config.LANGUAGES)];
        const label = document.createElement(HtmlTag.P);
        label.textContent = words.label(provider);
        const button = document.createElement(HtmlTag.Button);
        button.className = CssClass.BtnPrimary;
        button.textContent = words.load;
        button.addEventListener('click', () => { this.load(); }, { once: true });
        const hint = document.createElement(HtmlTag.Small);
        hint.textContent = words.hint(provider);
        this.replaceChildren(label, button, hint);
    }
    load() {
        this.replaceChildren(this.buildEmbed());
        this.setAttribute(EmbedAttribute.Loaded, '');
    }
}
//# sourceMappingURL=ConsentGatedEmbed.js.map