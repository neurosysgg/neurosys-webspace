/**
 * Shared DOM helpers.
 *
 * Everything here exists because the browser hands back `Element`, `null` or `string | undefined`
 * where the markup guarantees something narrower.
 */

// The event nav.ts fires after swapping #content. It stays private on purpose: dispatchNavigate()
// and onNavigate() are the only way to reach it, so the two sides cannot drift apart on a typo.
const NAVIGATE_EVENT = 'neurosys:navigate';

/** Announce that #content has been replaced. */
export function dispatchNavigate(): void {
  document.dispatchEvent(new Event(NAVIGATE_EVENT));
}

/**
 * Run `handler` every time #content is replaced.
 *
 * Nothing in the repo subscribes any more — the custom elements the swap brings in are upgraded by
 * the browser on their own. This stays as the documented way to hook a non-element into navigation.
 */
export function onNavigate(handler: () => void): void {
  document.addEventListener(NAVIGATE_EVENT, handler);
}

/** `EventTarget` also covers document and window, and neither of those has closest(). */
export function isElement(target: EventTarget | null): target is Element {
  return target instanceof Element;
}
