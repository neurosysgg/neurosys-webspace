import { NestedElement } from '../NestedElement.js';
import { DownloadCard } from './DownloadCard.js';
/** <download-label> — the format's name. CSS draws the ↓ in front of it. */
export class DownloadLabel extends NestedElement {
    parent() { return DownloadCard; }
}
customElements.define('download-label', DownloadLabel);
//# sourceMappingURL=DownloadLabel.js.map