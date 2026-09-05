import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { ReleaseArrangement } from './ReleaseArrangement.js';
export class ArrangementSection extends NestedElement {
    parent() { return ReleaseArrangement; }
}
customElements.define(Tag.ArrangementSection, ArrangementSection);
//# sourceMappingURL=ArrangementSection.js.map