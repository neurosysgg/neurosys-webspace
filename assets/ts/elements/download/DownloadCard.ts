import { NestedElement } from '../NestedElement.js';
import { DownloadList } from './DownloadList.js';

/** <download-card format> — one format's card. `format` names it: flac, wav, mp3, stems… */
export class DownloadCard extends NestedElement {
  protected parent(): CustomElementConstructor { return DownloadList; }
}

customElements.define('download-card', DownloadCard);
