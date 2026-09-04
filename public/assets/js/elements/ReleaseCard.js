import { NestedElement } from './NestedElement.js';
/**
 * <release-list> and the catalogue entries inside it.
 *
 * Like the download group, these wrap a real <a> and so build nothing: a catalogue that only works
 * with JS is not a catalogue. What they can do is refuse to be somewhere they do not belong.
 */
export class ReleaseList extends HTMLElement {
}
/** One entry. `slug` names the release it links to. */
export class ReleaseCard extends NestedElement {
    parent() { return ReleaseList; }
}
/** The release title. */
export class ReleaseTitle extends NestedElement {
    parent() { return ReleaseCard; }
}
/** The bpm · key · genre · description line under it. */
export class ReleaseMeta extends NestedElement {
    parent() { return ReleaseCard; }
}
customElements.define('release-list', ReleaseList);
customElements.define('release-card', ReleaseCard);
customElements.define('release-title', ReleaseTitle);
customElements.define('release-meta', ReleaseMeta);
//# sourceMappingURL=ReleaseCard.js.map