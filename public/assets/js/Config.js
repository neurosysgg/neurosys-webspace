/**
 * Mirrors the part of NeuroSYS\Config the client reads.
 *
 * Three facts, and each is stated on both sides because both sides need it:
 *
 * - `NAME` and `HANDLE` are the artist the player credits and links to.
 * - `PLAYER_HOST` is the widget origin, and it is also the CSP's whole `frame-src`. If these drift,
 *   the player is blocked by our own policy with nothing in the page to explain it.
 *
 * Deliberately not the whole of Config: the data paths and the logging switch are the server's
 * business, and a mirror with no reader is just something to keep in sync.
 */
export class Config {
    static NAME = 'neuro.SYS';
    static HANDLE = 'neurosysgg';
    static PLAYER_HOST = 'https://w.soundcloud.com';
}
//# sourceMappingURL=Config.js.map