/**
 * SPA navigation: intercept internal link clicks, fetch the page as a content fragment, and swap it
 * into #content. Every link is a real href, so direct loads and no-JS behave identically.
 */

import { dispatchNavigate, isElement } from './dom.js';

// The fragment's <title> is HTML-escaped by ViewResponse, so it has to be decoded
// before it reaches document.title — otherwise a track called "rock & roll" shows
// up in the tab as "rock &amp; roll".
function decodeEntities(text: string): string {
  const el = document.createElement('textarea');
  el.innerHTML = text;

  return el.value;
}

async function navigate(content: HTMLElement, url: string): Promise<void> {
  try {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (!response.ok) {
      location.assign(url);
      return;
    }

    const html  = await response.text();
    const title = html.match(/<title>([\s\S]*?)<\/title>/)?.[1];

    if (title !== undefined) document.title = decodeEntities(title);
    else console.warn('No title found in HTML response');

    content.innerHTML = html.replace(/<title>[\s\S]*?<\/title>/, '');
    dispatchNavigate();
    window.scrollTo(0, 0);
  } catch {
    // pushState already ran, so the URL points at a page the visitor never got.
    // Hand the navigation back to the browser rather than strand them there.
    location.assign(url);
  }
}

export function initNav(): void {
  const content = document.getElementById('content');

  // No #content means there is nothing to swap a fragment into. Registering nothing leaves every
  // link a plain href, which lands the visitor on the same page by the browser's own route.
  if (content === null) return;

  document.addEventListener('click', (e) => {
    // Let the browser handle open-in-new-tab/window and non-primary buttons.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

    // A click can land on the document itself, which has no closest().
    if (!isElement(e.target)) return;

    // The selector is what makes the anchor type true.
    const link = e.target.closest<HTMLAnchorElement>('a[href^="/"]');

    if (link === null || link.hasAttribute('data-no-spa')) return;

    e.preventDefault();
    history.pushState({}, '', link.href);
    void navigate(content, link.href);
  });

  window.addEventListener('popstate', () => {
    void navigate(content, location.pathname);
  });
}
