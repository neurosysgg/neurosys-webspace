import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../../phpanta/elements/NestedElement.js';
import { ReleaseList } from './ReleaseList.js';
export class ReleaseCard extends NestedElement {
    parent() { return ReleaseList; }
}
customElements.define(Tag.ReleaseCard, ReleaseCard);
//# sourceMappingURL=ReleaseCard.js.map