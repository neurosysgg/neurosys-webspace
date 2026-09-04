/**
 * Mirror of NeuroSYS\Model\Platform.
 *
 * Only the parts the client needs: the cases and displayName(). The label() and icon accessors stay
 * server-side, since the footer is rendered there and nothing here reads them.
 *
 * A mirror is a second copy of a fact, which is exactly the kind of thing this codebase refuses to
 * leave unguarded — test/enum-parity.* compares this file against the PHP enum, case for case and
 * value for value, and the verify script fails if they drift.
 */
export var Platform;
(function (Platform) {
    Platform["SoundCloud"] = "soundcloud";
    Platform["Spotify"] = "spotify";
    Platform["AppleMusic"] = "apple-music";
    Platform["YouTube"] = "youtube";
    Platform["X"] = "x";
    Platform["GitHub"] = "github";
})(Platform || (Platform = {}));
/**
 * The platform's name as it reads in body copy — the plain noun, without the verb label() carries.
 * Mirrors Platform::displayName(); used to word the embed consent notice.
 */
export function displayName(platform) {
    switch (platform) {
        case Platform.SoundCloud: return 'SoundCloud';
        case Platform.Spotify: return 'Spotify';
        case Platform.AppleMusic: return 'Apple Music';
        case Platform.YouTube: return 'YouTube';
        case Platform.X: return 'X';
        case Platform.GitHub: return 'GitHub';
    }
}
//# sourceMappingURL=Platform.js.map