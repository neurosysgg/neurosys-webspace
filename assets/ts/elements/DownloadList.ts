import { NestedElement } from './NestedElement.js';

/**
 * <download-list> — the download group on a release page.
 *
 * This one and <download-card> below build nothing, and cannot: what they wrap is a real
 * <a data-no-spa>, which has to be server-rendered so downloads work without JS and bypass the SPA
 * router. Their contents are the one part of the page that must not move to the client.
 */
export class DownloadList extends HTMLElement {}

/** One format's card. `format` names it: flac, wav, mp3, stems… */
export class DownloadCard extends NestedElement {
  protected parent(): CustomElementConstructor { return DownloadList; }
}

/** The format's name. CSS draws the ↓ in front of it. */
export class DownloadLabel extends NestedElement {
  protected parent(): CustomElementConstructor { return DownloadCard; }
}

/** The quality or licensing note under the name. */
export class DownloadMeta extends NestedElement {
  protected parent(): CustomElementConstructor { return DownloadCard; }
}

customElements.define('download-list', DownloadList);
customElements.define('download-card', DownloadCard);
customElements.define('download-label', DownloadLabel);
customElements.define('download-meta', DownloadMeta);
