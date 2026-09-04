import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { DownloadCard } from './DownloadCard.js';
/** <download-label> — the format's name. CSS draws the ↓ in front of it. */
export class DownloadLabel extends NestedElement {
    parent() { return DownloadCard; }
}
customElements.define(Tag.DownloadLabel, DownloadLabel);
//# sourceMappingURL=DownloadLabel.js.map