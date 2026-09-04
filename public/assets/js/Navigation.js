/**
 * SPA navigation: intercept internal link clicks, fetch the page as a content fragment, and swap it
 * into #content. Every link is a real href, so direct loads and no-JS behave identically.
 */
export class Navigation {
    content;
    /**
     * Fired on `document` once #content has been replaced.
     *
     * Private, and reachable only through onNavigate() — the name existed in two files once, where a
     * typo on either side broke the other in silence. Custom elements no longer need it: the browser
     * upgrades those on its own. It stays for anything that is not an element.
     */
    static EVENT = 'neurosys:navigate';
    constructor(content) {
        this.content = content;
    }
    /**
     * Builds a Navigation for this document, or null when there is no #content to swap into.
     *
     * Returning null rather than throwing is the point: with no listeners registered every link
     * stays a plain href, which lands the visitor on the same page by the browser's own route.
     */
    static forDocument() {
        const content = document.getElementById('content');
        return content === null ? null : new Navigation(content);
    }
    /** Runs `handler` every time #content is replaced. */
    static onNavigate(handler) {
        document.addEventListener(Navigation.EVENT, handler);
    }
    /** Starts intercepting link clicks and back/forward. */
    start() {
        document.addEventListener('click', (e) => { this.onClick(e); });
        window.addEventListener('popstate', () => { void this.go(location.pathname); });
    }
    onClick(e) {
        // Let the browser handle open-in-new-tab/window and non-primary buttons.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0)
            return;
        // A click can land on the document itself, and EventTarget has no closest().
        if (!(e.target instanceof Element))
            return;
        // The selector is what makes the anchor type true.
        const link = e.target.closest('a[href^="/"]');
        if (link === null || link.hasAttribute('data-no-spa'))
            return;
        e.preventDefault();
        history.pushState({}, '', link.href);
        void this.go(link.href);
    }
    async go(url) {
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                location.assign(url);
                return;
            }
            const html = await response.text();
            const title = html.match(/<title>([\s\S]*?)<\/title>/)?.[1];
            if (title !== undefined)
                document.title = Navigation.decodeEntities(title);
            else
                console.warn('No title found in HTML response');
            this.content.innerHTML = html.replace(/<title>[\s\S]*?<\/title>/, '');
            document.dispatchEvent(new Event(Navigation.EVENT));
            window.scrollTo(0, 0);
        }
        catch {
            // pushState already ran, so the URL points at a page the visitor never got.
            // Hand the navigation back to the browser rather than strand them there.
            location.assign(url);
        }
    }
    /**
     * The fragment's <title> is HTML-escaped by ViewResponse, so it has to be decoded before it
     * reaches document.title — otherwise a track called "rock & roll" shows up in the tab as
     * "rock &amp; roll".
     */
    static decodeEntities(text) {
        const el = document.createElement('textarea');
        el.innerHTML = text;
        return el.value;
    }
}
//# sourceMappingURL=Navigation.js.map