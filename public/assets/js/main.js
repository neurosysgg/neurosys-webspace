/**
 * Entry point — the only script Layout.php loads. Everything else is imported from here.
 */
// Imported for the side effect: each module registers its element with the browser. Registration is
// all it takes — the browser then upgrades every instance it parses, including markup the swap below
// brings in, so there is nothing to re-run after a navigation.
import './elements/CoverArt.js';
import './elements/PlayerConsent.js';
import { Navigation } from './Navigation.js';
Navigation.forDocument()?.start();
//# sourceMappingURL=main.js.map