import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { DownloadCard } from './DownloadCard.js';
/** <download-meta> — the quality or licensing note under the name. */
export class DownloadMeta extends NestedElement {
    parent() { return DownloadCard; }
}
customElements.define(Tag.DownloadMeta, DownloadMeta);
//# sourceMappingURL=DownloadMeta.js.map