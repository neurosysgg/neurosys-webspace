import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { ReleaseCard } from './ReleaseCard.js';
export class ReleaseMeta extends NestedElement {
    parent() { return ReleaseCard; }
}
customElements.define(Tag.ReleaseMeta, ReleaseMeta);
//# sourceMappingURL=ReleaseMeta.js.map