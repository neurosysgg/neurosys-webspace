/**
 * Entry point — the only script Layout.php loads. Everything else is imported from here.
 */

// Imported for the side effect: each module registers its element with the browser. Registration is
// all it takes — the browser then upgrades every instance it parses, including markup the swap below
// brings in, so there is nothing to re-run after a navigation.
//
// One import per tag, because one file per class: this list is the site's whole vocabulary, and a
// tag missing from it is a tag the browser never hears about. test/js/vocabulary.test.mjs asserts
// the list is complete, since the tags the elements build themselves appear in no served markup for
// the verify script to catch.
import './elements/CoverArt.js';

import './elements/embed/SoundCloudPlayer.js';
import './elements/embed/SoundCloudProfile.js';

import './elements/terminal/TerminalWindow.js';
import './elements/terminal/TerminalCommand.js';
import './elements/terminal/TerminalField.js';
import './elements/terminal/TerminalKey.js';
import './elements/terminal/TerminalValue.js';
import './elements/terminal/TerminalCursor.js';

import './elements/download/DownloadList.js';
import './elements/download/DownloadCard.js';
import './elements/download/DownloadLabel.js';
import './elements/download/DownloadMeta.js';

import './elements/arrangement/ReleaseArrangement.js';
import './elements/arrangement/ArrangementSection.js';

import './elements/waveform/DemoWaveform.js';

import './elements/release/ReleaseList.js';
import './elements/release/ReleaseCard.js';
import './elements/release/ReleaseTitle.js';
import './elements/release/ReleaseMeta.js';

// The framework's two, which the admin's machine service writes — a deployment that switches the
// service on shows them, and one that does not never serves a page with either.
import './phpanta/elements/MachineStats.js';
import './phpanta/elements/MachineFilter.js';

import { Navigation } from './phpanta/Navigation.js';
import { Passkey } from './phpanta/Passkey.js';

Navigation.forDocument()?.start();

// Unconditionally, because a form a passkey answers can arrive with a navigation after this has run:
// the admin's pages are swapped in like any other.
Passkey.start();
