import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../../phpanta/elements/NestedElement.js';
import { DownloadCard } from './DownloadCard.js';
export class DownloadMeta extends NestedElement {
    parent() { return DownloadCard; }
}
customElements.define(Tag.DownloadMeta, DownloadMeta);
//# sourceMappingURL=DownloadMeta.js.map