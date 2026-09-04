import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { ReleaseList } from './ReleaseList.js';
/** <release-card slug> — one catalogue entry. `slug` names the release it links to. */
export class ReleaseCard extends NestedElement {
    parent() { return ReleaseList; }
}
customElements.define(Tag.ReleaseCard, ReleaseCard);
//# sourceMappingURL=ReleaseCard.js.map