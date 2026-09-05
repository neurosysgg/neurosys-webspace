/**
 * Mirrors NeuroSYS\Model\Embed\EmbedAttribute — what every consent-gated embed carries.
 *
 * The provider-agnostic half of the pair. ConsentGatedEmbed reads only these, which is what lets it
 * stay provider-agnostic: the height it reserves is Embed::height(), a fact about the embed rather
 * than about SoundCloud, and it used to arrive under a SoundCloudPlayerAttribute case.
 *
 * `loaded` is written here and read only by the stylesheet — `&[loaded]` is what stops the box being
 * a gate and lets it just hold the player. It is a case on the PHP side too, so the parity test can
 * follow it; the server never writes it.
 */
export var EmbedAttribute;
(function (EmbedAttribute) {
    EmbedAttribute["Height"] = "height";
    EmbedAttribute["Loaded"] = "loaded";
})(EmbedAttribute || (EmbedAttribute = {}));
//# sourceMappingURL=EmbedAttribute.js.map