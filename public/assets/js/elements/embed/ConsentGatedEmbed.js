import { CssClass } from '../../model/CssClass.js';
import { CustomProperty } from '../../model/CustomProperty.js';
import { EmbedAttribute } from '../../model/EmbedAttribute.js';
import { HtmlTag } from '../../model/HtmlTag.js';
import { Platform, displayName } from '../../model/Platform.js';
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
        const label = document.createElement(HtmlTag.P);
        label.textContent = `${provider} player`;
        const button = document.createElement(HtmlTag.Button);
        button.className = CssClass.BtnPrimary;
        button.textContent = 'Load player';
        button.addEventListener('click', () => { this.load(); }, { once: true });
        const hint = document.createElement(HtmlTag.Small);
        hint.textContent = `Third-party content — clicking connects you to ${provider}’s servers.`;
        this.replaceChildren(label, button, hint);
    }
    load() {
        this.replaceChildren(this.buildEmbed());
        this.setAttribute(EmbedAttribute.Loaded, '');
    }
}
//# sourceMappingURL=ConsentGatedEmbed.js.map