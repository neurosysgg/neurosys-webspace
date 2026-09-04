import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { DownloadList } from './DownloadList.js';
/** <download-card format> — one format's card. `format` names it: flac, wav, mp3, stems… */
export class DownloadCard extends NestedElement {
    parent() { return DownloadList; }
}
customElements.define(Tag.DownloadCard, DownloadCard);
//# sourceMappingURL=DownloadCard.js.map