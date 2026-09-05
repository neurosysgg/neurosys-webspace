import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { ReleaseCard } from './ReleaseCard.js';
export class ReleaseTitle extends NestedElement {
    parent() { return ReleaseCard; }
}
customElements.define(Tag.ReleaseTitle, ReleaseTitle);
//# sourceMappingURL=ReleaseTitle.js.map