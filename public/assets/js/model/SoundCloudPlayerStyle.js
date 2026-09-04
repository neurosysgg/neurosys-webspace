/**
 * Mirror of NeuroSYS\Model\Embed\SoundCloudPlayerStyle — the two layouts SoundCloud offers.
 *
 * height() is deliberately not mirrored: the server sends the height as an attribute, so it stays
 * one fact in one place. What the client needs is the `visual` query flag.
 */
export var SoundCloudPlayerStyle;
(function (SoundCloudPlayerStyle) {
    SoundCloudPlayerStyle["Visual"] = "visual";
    SoundCloudPlayerStyle["Classic"] = "classic";
})(SoundCloudPlayerStyle || (SoundCloudPlayerStyle = {}));
/** The value of the player's `visual` query flag for this layout. Mirrors ::isVisual(). */
export function isVisual(style) {
    return style === SoundCloudPlayerStyle.Visual;
}
//# sourceMappingURL=SoundCloudPlayerStyle.js.map