import { NestedElement } from '../NestedElement.js';
import { ReleaseCard } from './ReleaseCard.js';
/** <release-title> — the release title. */
export class ReleaseTitle extends NestedElement {
    parent() { return ReleaseCard; }
}
customElements.define('release-title', ReleaseTitle);
//# sourceMappingURL=ReleaseTitle.js.map