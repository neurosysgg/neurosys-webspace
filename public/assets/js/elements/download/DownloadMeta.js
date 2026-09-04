import { NestedElement } from '../NestedElement.js';
import { DownloadCard } from './DownloadCard.js';
/** <download-meta> — the quality or licensing note under the name. */
export class DownloadMeta extends NestedElement {
    parent() { return DownloadCard; }
}
customElements.define('download-meta', DownloadMeta);
//# sourceMappingURL=DownloadMeta.js.map