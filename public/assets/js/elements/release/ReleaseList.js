import { Tag } from '../../model/Tag.js';
/**
 * <release-list> — the catalogue.
 *
 * Like the download group, this and the tags beside it wrap a real <a> and so build nothing: a
 * catalogue that only works with JS is not a catalogue. What they can do is refuse to be somewhere
 * they do not belong, which is what the children inherit and this one has no parent to need.
 */
export class ReleaseList extends HTMLElement {
}
customElements.define(Tag.ReleaseList, ReleaseList);
//# sourceMappingURL=ReleaseList.js.map