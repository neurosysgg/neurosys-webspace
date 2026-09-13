export var Language;
(function (Language) {
    Language["English"] = "en";
    Language["German"] = "de";
    Language["French"] = "fr";
    Language["Spanish"] = "es";
    Language["Italian"] = "it";
    Language["Dutch"] = "nl";
})(Language || (Language = {}));
export function pageLanguage(offered) {
    const stated = document.documentElement.lang;
    return offered.find((language) => language === stated) ?? offered[0];
}
//# sourceMappingURL=Language.js.map