import { SoundCloudPlayerAttribute } from '../../model/SoundCloudPlayerAttribute.js';
import { Tag } from '../../model/Tag.js';
import { SoundCloudWidget } from './SoundCloudWidget.js';
export class SoundCloudPlayer extends SoundCloudWidget {
    resourceUrl() {
        const url = `https://api.soundcloud.com/tracks/soundcloud:tracks:${this.getAttribute(SoundCloudPlayerAttribute.TrackId) ?? ''}`;
        const token = this.secretToken();
        return token === '' ? url : `${url}?secret_token=${token}`;
    }
    subject() {
        return this.trackTitle();
    }
    attributionTarget() {
        return { href: this.trackPermalink(), text: this.trackTitle() };
    }
    trackPermalink() {
        const url = `${SoundCloudPlayer.PROFILE}/${this.getAttribute(SoundCloudPlayerAttribute.Permalink) ?? ''}`;
        const token = this.secretToken();
        return token === '' ? url : `${url}/${token}`;
    }
    secretToken() {
        return this.getAttribute(SoundCloudPlayerAttribute.SecretToken) ?? '';
    }
    trackTitle() {
        return this.getAttribute(SoundCloudPlayerAttribute.TrackTitle) ?? '';
    }
}
customElements.define(Tag.SoundCloudPlayer, SoundCloudPlayer);
//# sourceMappingURL=SoundCloudPlayer.js.map