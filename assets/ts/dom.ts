/**
 * Shared DOM helpers.
 *
 * Everything here exists because the browser hands back `Element`, `null` or `string | undefined`
 * where the markup guarantees something narrower. Doing that narrowing once, here, is what stops a
 * renamed `data-` attribute from silently writing the text "undefined" into the page.
 */

// The event nav.ts fires after swapping #content. It stays private on purpose: dispatchNavigate()
// and onNavigate() are the only way to reach it, so the two sides cannot drift apart on a typo.
const NAVIGATE_EVENT = 'neurosys:navigate';

/** Announce that #content has been replaced. */
export function dispatchNavigate(): void {
  document.dispatchEvent(new Event(NAVIGATE_EVENT));
}

/** Run `handler` every time #content is replaced. */
export function onNavigate(handler: () => void): void {
  document.addEventListener(NAVIGATE_EVENT, handler);
}

/**
 * querySelectorAll as a real array of the element type the selector implies.
 * The caller names the type; the selector is what makes it true.
 */
export function queryAll<T extends Element>(selector: string, root: ParentNode = document): T[] {
  return Array.from(root.querySelectorAll<T>(selector));
}

/** `EventTarget` also covers document and window, and neither of those has closest(). */
export function isElement(target: EventTarget | null): target is Element {
  return target instanceof Element;
}

/**
 * A data-* attribute's value, or null when it is missing or empty.
 *
 * `dataset` returns `string | undefined`, and a present-but-empty attribute returns ''. Both used
 * to reach the DOM unchecked, as the literal string "undefined" or as an empty URL.
 */
export function datasetValue(el: HTMLElement, key: string): string | null {
  const value = el.dataset[key];

  return value === undefined || value === '' ? null : value;
}
