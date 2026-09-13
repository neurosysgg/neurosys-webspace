import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../../phpanta/elements/NestedElement.js';
import { ReleaseCard } from './ReleaseCard.js';

/** <release-title> — the release title. */
export class ReleaseTitle extends NestedElement {
  protected parent(): CustomElementConstructor { return ReleaseCard; }
}

customElements.define(Tag.ReleaseTitle, ReleaseTitle);
