/**
 * <release-card slug="ill"> — one entry in the catalogue.
 *
 * No behaviour: the card wraps a real <a>, so it navigates with or without JS. Registered so the
 * vocabulary is declared in one place rather than existing only as a CSS selector.
 */
export class ReleaseCard extends HTMLElement {
}
/** The release title. */
export class ReleaseTitle extends HTMLElement {
}
/** The bpm · key · genre · description line under it. */
export class ReleaseMeta extends HTMLElement {
}
customElements.define('release-card', ReleaseCard);
customElements.define('release-title', ReleaseTitle);
customElements.define('release-meta', ReleaseMeta);
//# sourceMappingURL=ReleaseCard.js.map