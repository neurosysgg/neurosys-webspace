import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkRel } from './model/LinkRel.js';
export class LanguageChoice {
    addresses;
    static CHOSEN = 'phpanta:language';
    static FOLLOWED = 'phpanta:language-followed';
    static ALTERNATE = `${HtmlTag.Link}[${HtmlAttribute.Rel}~="${LinkRel.Alternate}"][${HtmlAttribute.HrefLang}]`;
    static SWITCH = `${HtmlTag.A}[${HtmlAttribute.HrefLang}]`;
    static SUBTAGS = /-.*$/s;
    constructor(addresses) {
        this.addresses = addresses;
    }
    static forDocument() {
        const addresses = new Map();
        document.querySelectorAll(LanguageChoice.ALTERNATE).forEach((link) => {
            LanguageChoice.keep(addresses, link);
        });
        return addresses.size < 2 ? null : new LanguageChoice(addresses);
    }
    static keep(addresses, link) {
        addresses.set(link.hreflang.toLowerCase(), new URL(link.href));
    }
    start() {
        document.addEventListener('click', (e) => { this.onClick(e); });
        this.arrive();
    }
    onClick(e) {
        if (!(e.target instanceof Element))
            return;
        const chosen = e.target.closest(LanguageChoice.SWITCH)?.hreflang.toLowerCase();
        if (chosen !== undefined && this.addresses.has(chosen)) {
            LanguageChoice.write(LanguageChoice.CHOSEN, chosen);
        }
    }
    arrive() {
        for (const address of this.addresses.values()) {
            if (address.pathname === location.pathname)
                return;
        }
        const language = this.chosen() ?? this.followed();
        const target = language === null || language === document.documentElement.lang
            ? undefined
            : this.addresses.get(language);
        if (target === undefined)
            return;
        const url = new URL(target);
        url.hash = location.hash;
        location.replace(url.href);
    }
    chosen() {
        const chosen = LanguageChoice.read(LanguageChoice.CHOSEN);
        return chosen !== null && this.addresses.has(chosen) ? chosen : null;
    }
    followed() {
        if (LanguageChoice.read(LanguageChoice.FOLLOWED) !== null)
            return null;
        LanguageChoice.write(LanguageChoice.FOLLOWED, '1');
        for (const tag of navigator.languages) {
            const primary = tag.toLowerCase().replace(LanguageChoice.SUBTAGS, '');
            if (this.addresses.has(primary))
                return primary;
        }
        return null;
    }
    static read(key) {
        try {
            return localStorage.getItem(key);
        }
        catch {
            return null;
        }
    }
    static write(key, value) {
        try {
            localStorage.setItem(key, value);
        }
        catch {
        }
    }
}
//# sourceMappingURL=LanguageChoice.js.map