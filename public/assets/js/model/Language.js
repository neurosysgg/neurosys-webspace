export var Language;
(function (Language) {
    Language["English"] = "en";
    Language["German"] = "de";
})(Language || (Language = {}));
export function pageLanguage() {
    const stated = document.documentElement.lang;
    return Object.values(Language).includes(stated) ? stated : Language.English;
}
//# sourceMappingURL=Language.js.map