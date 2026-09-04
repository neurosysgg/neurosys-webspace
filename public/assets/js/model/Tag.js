/**
 * Mirrors NeuroSYS\View\Html\Tag — every custom element the site emits or builds.
 *
 * The server writes these tag names and this side registers and creates them, so they are the same
 * fact stated twice. test/js/enum-parity.test.mjs compares the two: a tag renamed on one side only
 * is an element the browser has never heard of, which renders as an inert inline box with no error.
 */
export var Tag;
(function (Tag) {
    Tag["SoundCloudPlayer"] = "soundcloud-player";
    Tag["CoverArt"] = "cover-art";
    Tag["TerminalWindow"] = "terminal-window";
    Tag["TerminalCommand"] = "terminal-command";
    Tag["TerminalField"] = "terminal-field";
    Tag["TerminalKey"] = "terminal-key";
    Tag["TerminalValue"] = "terminal-value";
    Tag["TerminalCursor"] = "terminal-cursor";
    Tag["DownloadList"] = "download-list";
    Tag["DownloadCard"] = "download-card";
    Tag["DownloadLabel"] = "download-label";
    Tag["DownloadMeta"] = "download-meta";
    Tag["ReleaseList"] = "release-list";
    Tag["ReleaseCard"] = "release-card";
    Tag["ReleaseTitle"] = "release-title";
    Tag["ReleaseMeta"] = "release-meta";
})(Tag || (Tag = {}));
//# sourceMappingURL=Tag.js.map