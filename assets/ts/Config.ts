import { Language } from './phpanta/model/Language.js';

/**
 * Mirrors the part of NeuroSYS\Config the client reads.
 *
 * Four facts, and each is stated on both sides because both sides need it:
 *
 * - `NAME` and `HANDLE` are the artist the player credits and links to.
 * - `PLAYER_HOST` is the widget origin, and it is also the CSP's whole `frame-src`. If these drift,
 *   the player is blocked by our own policy with nothing in the page to explain it.
 * - `LANGUAGES` are the languages the site offers, its default first — what every element's words
 *   are a Record over, and what `pageLanguage()` narrows <html lang> to.
 *
 * Deliberately not the whole of Config: the data paths and the logging switch are the server's
 * business, and a mirror with no reader is just something to keep in sync.
 */
export class Config {
  static readonly NAME = 'neuro.SYS';

  static readonly HANDLE = 'neurosysgg';

  static readonly PLAYER_HOST = 'https://w.soundcloud.com';

  static readonly LANGUAGES = [Language.English, Language.German] as const;
}

/** A language the site offers — the key of every Record of words the client writes. */
export type SiteLanguage = (typeof Config.LANGUAGES)[number];
