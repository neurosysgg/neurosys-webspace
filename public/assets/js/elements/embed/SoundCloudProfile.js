import { Config } from '../../Config.js';
import { Tag } from '../../model/Tag.js';
import { SoundCloudWidget } from './SoundCloudWidget.js';
export class SoundCloudProfile extends SoundCloudWidget {
    resourceUrl() {
        return SoundCloudProfile.PROFILE;
    }
    subject() {
        return Config.NAME;
    }
    attributionTarget() {
        return null;
    }
}
customElements.define(Tag.SoundCloudProfile, SoundCloudProfile);
//# sourceMappingURL=SoundCloudProfile.js.map