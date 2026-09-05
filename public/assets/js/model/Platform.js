export var Platform;
(function (Platform) {
    Platform["SoundCloud"] = "soundcloud";
    Platform["Spotify"] = "spotify";
    Platform["AppleMusic"] = "apple-music";
    Platform["YouTube"] = "youtube";
    Platform["X"] = "x";
    Platform["GitHub"] = "github";
})(Platform || (Platform = {}));
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