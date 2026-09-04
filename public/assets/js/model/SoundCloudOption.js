/**
 * Mirror of NeuroSYS\Model\Embed\SoundCloudOption.
 *
 * Each case is backed by the literal query-string key the player reads. SoundCloudPlayer enables
 * exactly the options its `options` attribute lists; every other case is sent as `false` rather
 * than omitted, matching what SoundCloud's own embed dialog produces.
 *
 * Declaration order is the rendered order — the query string is built by iterating these — so
 * test/enum-parity.* compares the two lists in order, not as sets.
 */
export var SoundCloudOption;
(function (SoundCloudOption) {
    SoundCloudOption["AutoPlay"] = "auto_play";
    SoundCloudOption["HideRelated"] = "hide_related";
    SoundCloudOption["ShowComments"] = "show_comments";
    SoundCloudOption["ShowUser"] = "show_user";
    SoundCloudOption["ShowReposts"] = "show_reposts";
    SoundCloudOption["ShowTeaser"] = "show_teaser";
})(SoundCloudOption || (SoundCloudOption = {}));
//# sourceMappingURL=SoundCloudOption.js.map