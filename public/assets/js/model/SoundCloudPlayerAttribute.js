/**
 * Mirrors NeuroSYS\Model\Embed\SoundCloudPlayerAttribute — what the server tells the player.
 *
 * These names are the whole interface between the two halves of the player. A typo on either side
 * is a silent null: a widget URL with no track, or an iframe with no height.
 */
export var SoundCloudPlayerAttribute;
(function (SoundCloudPlayerAttribute) {
    SoundCloudPlayerAttribute["TrackId"] = "track-id";
    SoundCloudPlayerAttribute["Permalink"] = "permalink";
    SoundCloudPlayerAttribute["SecretToken"] = "secret-token";
    SoundCloudPlayerAttribute["PlayerStyle"] = "player-style";
    SoundCloudPlayerAttribute["Options"] = "options";
    SoundCloudPlayerAttribute["TrackTitle"] = "track-title";
})(SoundCloudPlayerAttribute || (SoundCloudPlayerAttribute = {}));
//# sourceMappingURL=SoundCloudPlayerAttribute.js.map