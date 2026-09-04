/**
 * The CSS custom properties an element sets on itself.
 *
 * Client-only, like TerminalFieldAttribute and EmbedAttribute: nothing server-side writes one, and
 * the reader is the stylesheet, which no test can follow. The gate sets this from its own height
 * attribute so the placeholder is exactly as tall as the iframe that replaces it — get the name
 * wrong and the fallback in the stylesheet quietly takes over, and the page jumps on load.
 */
export var CustomProperty;
(function (CustomProperty) {
    CustomProperty["PlayerHeight"] = "--player-height";
})(CustomProperty || (CustomProperty = {}));
//# sourceMappingURL=CustomProperty.js.map