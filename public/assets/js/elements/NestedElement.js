export class NestedElement extends HTMLElement {
    connectedCallback() {
        const expected = this.parent();
        let node = this.parentElement;
        while (node !== null) {
            if (node instanceof expected)
                return;
            node = node.parentElement;
        }
        throw new Error(`<${this.localName}> must be inside <${NestedElement.tagOf(expected)}>, but is not.`);
    }
    static tagOf(constructor) {
        return customElements.getName?.(constructor) ?? constructor.name;
    }
}
//# sourceMappingURL=NestedElement.js.map