/**
 * What a consent-gated embed carries about itself once the visitor has clicked.
 *
 * Client-only, like TerminalFieldAttribute, and read by the stylesheet: `&[loaded]` is what stops
 * the box being a gate and lets it just hold the player.
 */
export var EmbedAttribute;
(function (EmbedAttribute) {
    EmbedAttribute["Loaded"] = "loaded";
})(EmbedAttribute || (EmbedAttribute = {}));
//# sourceMappingURL=EmbedAttribute.js.map