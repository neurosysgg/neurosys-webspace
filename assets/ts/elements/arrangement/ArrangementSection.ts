import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { ReleaseArrangement } from './ReleaseArrangement.js';

/** <arrangement-section kind> — one part of the arrangement. `kind` decides which accent it takes. */
export class ArrangementSection extends NestedElement {
  protected parent(): CustomElementConstructor { return ReleaseArrangement; }
}

customElements.define(Tag.ArrangementSection, ArrangementSection);
