/**
 * <download-list> and the download cards inside it.
 *
 * No behaviour: each card wraps a real <a data-no-spa>, because a download has to work without JS
 * and has to bypass the SPA router — see Navigation. Registered so the vocabulary is declared.
 */
export class DownloadList extends HTMLElement {}

/** One format's card. `format` names it: flac, wav, mp3, stems… */
export class DownloadCard extends HTMLElement {}

/** The format's name. CSS draws the ↓ in front of it. */
export class DownloadLabel extends HTMLElement {}

/** The quality or licensing note under the name. */
export class DownloadMeta extends HTMLElement {}

customElements.define('download-list', DownloadList);
customElements.define('download-card', DownloadCard);
customElements.define('download-label', DownloadLabel);
customElements.define('download-meta', DownloadMeta);
