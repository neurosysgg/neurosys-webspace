/**
 * Entry point — the only script Layout.php loads. Everything else is imported from here.
 */
import { onNavigate } from './dom.js';
import { initNav } from './nav.js';
import { initPlayer } from './player.js';
initNav();
initPlayer();
// #content has just been replaced, so the gate, the cover and the buttons are all new elements.
onNavigate(initPlayer);
//# sourceMappingURL=main.js.map