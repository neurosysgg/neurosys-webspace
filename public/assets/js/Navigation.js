import { ElementId } from './model/ElementId.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkAttribute } from './model/LinkAttribute.js';
import { RequestHeader } from './model/RequestHeader.js';
import { RequestedWith } from './model/RequestedWith.js';
export class Navigation {
    content;
    static EVENT = 'neurosys:navigate';
    static INTERNAL_LINK = `${HtmlTag.A}[${HtmlAttribute.Href}^="/"]`;
    static TITLE = new RegExp(`<${HtmlTag.Title}>([\\s\\S]*?)</${HtmlTag.Title}>`);
    navigation = 0;
    inFlight = null;
    constructor(content) {
        this.content = content;
    }
    static forDocument() {
        const content = document.getElementById(ElementId.Content);
        return content === null ? null : new Navigation(content);
    }
    static onNavigate(handler) {
        document.addEventListener(Navigation.EVENT, handler);
    }
    start() {
        document.addEventListener('click', (e) => { this.onClick(e); });
        window.addEventListener('popstate', () => { void this.go(location.pathname); });
    }
    onClick(e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0)
            return;
        if (!(e.target instanceof Element))
            return;
        const link = e.target.closest(Navigation.INTERNAL_LINK);
        if (link === null || link.hasAttribute(LinkAttribute.NoSpa))
            return;
        if (new URL(link.href).origin !== location.origin)
            return;
        e.preventDefault();
        history.pushState({}, '', link.href);
        void this.go(link.href);
    }
    async go(url) {
        this.inFlight?.abort();
        const controller = new AbortController();
        const navigation = ++this.navigation;
        this.inFlight = controller;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { [RequestHeader.RequestedWith]: RequestedWith.XmlHttpRequest },
                signal: controller.signal
            });
            if (navigation !== this.navigation)
                return;
            if (!response.ok) {
                location.assign(url);
                return;
            }
            const html = await response.text();
            if (navigation !== this.navigation)
                return;
            const title = html.match(Navigation.TITLE)?.[1];
            if (title !== undefined)
                document.title = Navigation.decodeEntities(title);
            else
                console.warn('No title found in HTML response');
            this.content.innerHTML = html.replace(Navigation.TITLE, '');
            document.dispatchEvent(new Event(Navigation.EVENT));
            window.scrollTo(0, 0);
        }
        catch {
            if (navigation !== this.navigation)
                return;
            location.assign(url);
        }
    }
    static decodeEntities(text) {
        const el = document.createElement(HtmlTag.Textarea);
        el.innerHTML = text;
        return el.value;
    }
}
//# sourceMappingURL=Navigation.js.map