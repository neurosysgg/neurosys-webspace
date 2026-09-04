/**
 * <download-list> — the download group on a release page.
 *
 * This one and the tags beside it build nothing, and cannot: what they wrap is a real
 * <a data-no-spa>, which has to be server-rendered so downloads work without JS and bypass the SPA
 * router. Their contents are the one part of the page that must not move to the client. So a plain
 * HTMLElement here is the finished implementation, not a stub — what the children have on top of it
 * is the nesting guard, which is all a name can enforce.
 */
export class DownloadList extends HTMLElement {
}
customElements.define('download-list', DownloadList);
//# sourceMappingURL=DownloadList.js.map