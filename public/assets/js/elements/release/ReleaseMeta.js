import { NestedElement } from '../NestedElement.js';
import { ReleaseCard } from './ReleaseCard.js';
/** <release-meta> — the bpm · key · genre · description line under the title. */
export class ReleaseMeta extends NestedElement {
    parent() { return ReleaseCard; }
}
customElements.define('release-meta', ReleaseMeta);
//# sourceMappingURL=ReleaseMeta.js.map