import { CeremonyType } from './model/CeremonyType.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { PasskeyAttribute } from './model/PasskeyAttribute.js';
import { PasskeyFormField } from './model/PasskeyFormField.js';
export class Passkey {
    static FORM = `${HtmlTag.Form}[${PasskeyAttribute.Ceremony}]`;
    static ES256 = -7;
    static HANDLE = 16;
    static ACCOUNT = 'admin';
    answered = new WeakSet();
    asking = new WeakSet();
    constructor() { }
    static start() {
        const passkey = new Passkey();
        document.addEventListener('submit', (e) => { void passkey.onSubmit(e); });
    }
    async onSubmit(e) {
        const form = e.target instanceof Element ? e.target.closest(Passkey.FORM) : null;
        const credentials = navigator.credentials;
        if (form === null || credentials === undefined || this.answered.delete(form))
            return;
        e.preventDefault();
        if (this.asking.has(form))
            return;
        this.asking.add(form);
        Passkey.waiting(form, true);
        const answer = await Passkey.ceremony(form, credentials);
        this.asking.delete(form);
        Passkey.waiting(form, false);
        Passkey.unanswered(form, answer === null);
        if (answer === null)
            return;
        answer.forEach((value, field) => { Passkey.fill(form, field, value); });
        this.send(form, e.submitter);
    }
    static waiting(form, waiting) {
        form.querySelectorAll(HtmlTag.Button).forEach((button) => {
            button.toggleAttribute(HtmlAttribute.Disabled, waiting);
        });
    }
    static unanswered(form, show) {
        form.querySelector(`[${PasskeyAttribute.Status}]`)?.toggleAttribute(HtmlAttribute.Hidden, !show);
    }
    send(form, submitter) {
        this.answered.add(form);
        form.requestSubmit(submitter);
    }
    static async ceremony(form, credentials) {
        try {
            const challenge = Passkey.bytes(form.getAttribute(PasskeyAttribute.Challenge) ?? '');
            return form.getAttribute(PasskeyAttribute.Ceremony) === CeremonyType.Create
                ? await Passkey.create(credentials, challenge)
                : await Passkey.get(credentials, challenge);
        }
        catch {
            return null;
        }
    }
    static async get(credentials, challenge) {
        const credential = await credentials.get({
            publicKey: { challenge, userVerification: 'required' },
        });
        if (credential === null)
            return null;
        const response = credential.response;
        return new Map([
            [PasskeyFormField.Credential, credential.id],
            [PasskeyFormField.ClientData, Passkey.text(response.clientDataJSON)],
            [PasskeyFormField.AuthenticatorData, Passkey.text(response.authenticatorData)],
            [PasskeyFormField.Signature, Passkey.text(response.signature)],
        ]);
    }
    static async create(credentials, challenge) {
        const credential = await credentials.create({
            publicKey: {
                challenge,
                rp: { name: location.hostname },
                user: {
                    id: crypto.getRandomValues(new Uint8Array(Passkey.HANDLE)),
                    name: Passkey.ACCOUNT,
                    displayName: Passkey.ACCOUNT,
                },
                pubKeyCredParams: [{ type: 'public-key', alg: Passkey.ES256 }],
                authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
            },
        });
        const response = credential?.response;
        const key = response?.getPublicKey() ?? null;
        if (credential === null || response === undefined || key === null)
            return null;
        return new Map([
            [PasskeyFormField.Credential, credential.id],
            [PasskeyFormField.ClientData, Passkey.text(response.clientDataJSON)],
            [PasskeyFormField.AuthenticatorData, Passkey.text(response.getAuthenticatorData())],
            [PasskeyFormField.Key, Passkey.text(key)],
        ]);
    }
    static fill(form, field, value) {
        let input = form.querySelector(`${HtmlTag.Input}[${HtmlAttribute.Name}="${field}"]`);
        if (input === null) {
            input = document.createElement(HtmlTag.Input);
            input.setAttribute(HtmlAttribute.Type, 'hidden');
            input.setAttribute(HtmlAttribute.Name, field);
            form.append(input);
        }
        input.setAttribute(HtmlAttribute.Value, value);
    }
    static bytes(base64url) {
        const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=');
        return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
    }
    static text(buffer) {
        let binary = '';
        for (const byte of new Uint8Array(buffer))
            binary += String.fromCharCode(byte);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
}
//# sourceMappingURL=Passkey.js.map