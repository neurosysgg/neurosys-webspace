import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { DownloadCard } from './DownloadCard.js';
export class DownloadLabel extends NestedElement {
    parent() { return DownloadCard; }
}
customElements.define(Tag.DownloadLabel, DownloadLabel);
//# sourceMappingURL=DownloadLabel.js.map