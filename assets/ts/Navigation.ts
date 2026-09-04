import { ElementId } from './model/ElementId.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkAttribute } from './model/LinkAttribute.js';
import { RequestHeader } from './model/RequestHeader.js';
import { RequestedWith } from './model/RequestedWith.js';

/**
 * SPA navigation: intercept internal link clicks, fetch the page as a content fragment, and swap it
 * into #content. Every link is a real href, so direct loads and no-JS behave identically.
 */
export class Navigation {
  /**
   * Fired on `document` once #content has been replaced.
   *
   * Private, and reachable only through onNavigate() — the name existed in two files once, where a
   * typo on either side broke the other in silence. Custom elements no longer need it: the browser
   * upgrades those on its own. It stays for anything that is not an element.
   */
  private static readonly EVENT = 'neurosys:navigate';

  /**
   * An anchor pointing somewhere on this site.
   *
   * Built from the same tag and attribute names the server writes rather than spelled out, so the
   * selector cannot go on matching nothing after a rename it was never told about.
   */
  private static readonly INTERNAL_LINK = `${HtmlTag.A}[${HtmlAttribute.Href}^="/"]`;

  /**
   * The <title> ViewResponse leads a fragment with.
   *
   * Named from HtmlTag for the same reason as the selector above, and deliberately not global —
   * the same expression is used to read the title and then to strip it, and a /g regex carries
   * lastIndex between those two calls.
   */
  private static readonly TITLE = new RegExp(
    `<${HtmlTag.Title}>([\\s\\S]*?)</${HtmlTag.Title}>`,
  );

  private constructor(private readonly content: HTMLElement) {}

  /**
   * Builds a Navigation for this document, or null when there is no #content to swap into.
   *
   * Returning null rather than throwing is the point: with no listeners registered every link
   * stays a plain href, which lands the visitor on the same page by the browser's own route.
   */
  public static forDocument(): Navigation | null {
    const content = document.getElementById(ElementId.Content);

    return content === null ? null : new Navigation(content);
  }

  /** Runs `handler` every time #content is replaced. */
  public static onNavigate(handler: () => void): void {
    document.addEventListener(Navigation.EVENT, handler);
  }

  /** Starts intercepting link clicks and back/forward. */
  public start(): void {
    document.addEventListener('click', (e) => { this.onClick(e); });
    window.addEventListener('popstate', () => { void this.go(location.pathname); });
  }

  private onClick(e: MouseEvent): void {
    // Let the browser handle open-in-new-tab/window and non-primary buttons.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

    // A click can land on the document itself, and EventTarget has no closest().
    if (!(e.target instanceof Element)) return;

    // The selector is what makes the anchor type true.
    const link = e.target.closest<HTMLAnchorElement>(Navigation.INTERNAL_LINK);

    if (link === null || link.hasAttribute(LinkAttribute.NoSpa)) return;

    e.preventDefault();
    history.pushState({}, '', link.href);
    void this.go(link.href);
  }

  private async go(url: string): Promise<void> {
    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { [RequestHeader.RequestedWith]: RequestedWith.XmlHttpRequest }
      });

      if (!response.ok) {
        location.assign(url);
        return;
      }

      const html  = await response.text();
      const title = html.match(Navigation.TITLE)?.[1];

      if (title !== undefined) document.title = Navigation.decodeEntities(title);
      else console.warn('No title found in HTML response');

      this.content.innerHTML = html.replace(Navigation.TITLE, '');
      document.dispatchEvent(new Event(Navigation.EVENT));
      window.scrollTo(0, 0);
    } catch {
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
  private static decodeEntities(text: string): string {
    const el = document.createElement(HtmlTag.Textarea);
    el.innerHTML = text;

    return el.value;
  }
}
