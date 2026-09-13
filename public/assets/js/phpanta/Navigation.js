import { ElementId } from './model/ElementId.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkAttribute } from './model/LinkAttribute.js';
import { MediaType } from './model/MediaType.js';
import { RequestHeader } from './model/RequestHeader.js';
import { RequestedWith } from './model/RequestedWith.js';
import { ResponseHeader } from './model/ResponseHeader.js';
export class Navigation {
    content;
    static EVENT = 'phpanta:navigate';
    static INTERNAL_LINK = `${HtmlTag.A}[${HtmlAttribute.Href}^="/"]`;
    static TITLE = new RegExp(`<${HtmlTag.Title}>([\\s\\S]*?)</${HtmlTag.Title}>`);
    static DOCUMENT = /^\s*<!doctype html/i;
    static PARAMETERS = /;[\s\S]*/;
    navigation = 0;
    inFlight = null;
    positions = new Map();
    key = '';
    shown = '';
    announcer = Navigation.liveRegion();
    constructor(content) {
        this.content = content;
    }
    static forDocument() {
        const content = document.getElementById(ElementId.Content);
        return null === content ? null : new Navigation(content);
    }
    static onNavigate(handler) {
        document.addEventListener(Navigation.EVENT, handler);
    }
    start() {
        history.scrollRestoration = 'manual';
        this.content.tabIndex = -1;
        document.body.append(this.announcer);
        this.shown = Navigation.documentOf(location.href);
        this.adopt();
        const position = Navigation.entryOf(history.state)?.scrollY;
        if (position !== undefined)
            window.scrollTo(0, position);
        document.addEventListener('click', (e) => { this.onClick(e); });
        window.addEventListener('popstate', () => { this.onPopState(); });
        window.addEventListener('pagehide', () => { this.remember(true); });
    }
    onClick(e) {
        if (e.defaultPrevented)
            return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || 0 !== e.button)
            return;
        if (!(e.target instanceof Element))
            return;
        const link = e.target.closest(Navigation.INTERNAL_LINK);
        if (null === link || link.hasAttribute(LinkAttribute.NoSpa))
            return;
        if (link.hasAttribute(HtmlAttribute.Download) || !Navigation.opensHere(link))
            return;
        const url = new URL(link.href);
        if (url.origin !== location.origin)
            return;
        if (url.hash !== '' && Navigation.documentOf(url.href) === Navigation.documentOf(location.href)) {
            return;
        }
        e.preventDefault();
        this.remember(true);
        this.key = Navigation.freshKey();
        history.pushState({ key: this.key }, '', url.href);
        void this.go(url.href, undefined);
    }
    onPopState() {
        this.remember(false);
        this.adopt();
        const position = this.positions.get(this.key) ?? Navigation.entryOf(history.state)?.scrollY;
        if (Navigation.documentOf(location.href) !== this.shown) {
            void this.go(location.href, position);
            return;
        }
        Navigation.land(new URL(location.href).hash, position);
    }
    async go(url, position) {
        this.inFlight?.abort();
        const controller = new AbortController();
        const navigation = ++this.navigation;
        this.inFlight = controller;
        this.shown = Navigation.documentOf(url);
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { [RequestHeader.RequestedWith]: RequestedWith.XmlHttpRequest },
                signal: controller.signal
            });
            if (navigation !== this.navigation)
                return;
            if (!response.ok || !Navigation.isPage(response)) {
                location.replace(url);
                return;
            }
            const html = await response.text();
            if (navigation !== this.navigation)
                return;
            const page = Navigation.page(html);
            if (page === null) {
                location.replace(url);
                return;
            }
            if (page.title !== null)
                document.title = page.title;
            else
                console.warn('No title found in HTML response');
            this.content.innerHTML = page.content;
            document.dispatchEvent(new Event(Navigation.EVENT));
            this.arrive(new URL(url).hash, position);
        }
        catch {
            if (navigation !== this.navigation)
                return;
            location.replace(url);
        }
    }
    arrive(hash, position) {
        this.content.focus({ preventScroll: true });
        this.announcer.textContent = document.title;
        if (!Navigation.land(hash, position))
            window.scrollTo(0, 0);
    }
    remember(onEntry) {
        this.positions.set(this.key, window.scrollY);
        if (onEntry)
            history.replaceState({ key: this.key, scrollY: window.scrollY }, '');
    }
    adopt() {
        const entry = Navigation.entryOf(history.state);
        if (entry !== null) {
            this.key = entry.key;
            return;
        }
        this.key = Navigation.freshKey();
        history.replaceState({ key: this.key }, '');
    }
    static land(hash, position) {
        if (position !== undefined) {
            window.scrollTo(0, position);
            return true;
        }
        const target = Navigation.fragmentTarget(hash);
        target?.scrollIntoView();
        return target !== null;
    }
    static fragmentTarget(hash) {
        const fragment = hash.slice(1);
        return document.getElementById(fragment) ?? Navigation.decodedTarget(fragment);
    }
    static decodedTarget(fragment) {
        try {
            return document.getElementById(decodeURIComponent(fragment));
        }
        catch {
            return null;
        }
    }
    static opensHere(link) {
        const target = link.target.toLowerCase();
        return target === '' || target === '_self';
    }
    static isPage(response) {
        const type = response.headers.get(ResponseHeader.ContentType) ?? '';
        return type.replace(Navigation.PARAMETERS, '').trim().toLowerCase() === MediaType.Html;
    }
    static entryOf(state) {
        if (typeof state !== 'object' || state === null)
            return null;
        const { key, scrollY } = state;
        if (typeof key !== 'string')
            return null;
        return typeof scrollY === 'number' ? { key, scrollY } : { key };
    }
    static freshKey() {
        return Math.random().toString(36).slice(2);
    }
    static documentOf(url) {
        const { pathname, search } = new URL(url);
        return pathname + search;
    }
    static liveRegion() {
        const region = document.createElement(HtmlTag.Div);
        region.setAttribute(HtmlAttribute.AriaLive, 'polite');
        Object.assign(region.style, {
            position: 'absolute',
            width: '1px',
            height: '1px',
            overflow: 'hidden',
            clipPath: 'inset(50%)',
            whiteSpace: 'nowrap',
        });
        return region;
    }
    static page(html) {
        if (!Navigation.DOCUMENT.test(html)) {
            const title = html.match(Navigation.TITLE)?.[1];
            return {
                title: title === undefined ? null : Navigation.decodeEntities(title),
                content: html.replace(Navigation.TITLE, ''),
            };
        }
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const content = parsed.getElementById(ElementId.Content);
        if (content === null)
            return null;
        return { title: parsed.title === '' ? null : parsed.title, content: content.innerHTML };
    }
    static decodeEntities(text) {
        const el = document.createElement(HtmlTag.Textarea);
        el.innerHTML = text;
        return el.value;
    }
}
//# sourceMappingURL=Navigation.js.map