import { NestedElement } from '../NestedElement.js';
import { DownloadCard } from './DownloadCard.js';

/** <download-meta> — the quality or licensing note under the name. */
export class DownloadMeta extends NestedElement {
  protected parent(): CustomElementConstructor { return DownloadCard; }
}

customElements.define('download-meta', DownloadMeta);
