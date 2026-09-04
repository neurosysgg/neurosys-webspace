import { NestedElement } from '../NestedElement.js';
import { ReleaseList } from './ReleaseList.js';

/** <release-card slug> — one catalogue entry. `slug` names the release it links to. */
export class ReleaseCard extends NestedElement {
  protected parent(): CustomElementConstructor { return ReleaseList; }
}

customElements.define('release-card', ReleaseCard);
