/**
 * Entry point — the only script Layout.php loads. Everything else is imported from here.
 */

// Imported for the side effect: each module registers its element with the browser. Registration is
// all it takes — the browser then upgrades every instance it parses, including markup nav.ts swaps
// into #content, so there is nothing to re-run after a navigation.
import './elements/cover-art.js';
import './elements/player-consent.js';

import { initNav } from './nav.js';

initNav();
