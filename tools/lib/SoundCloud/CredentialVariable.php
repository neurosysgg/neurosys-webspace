<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The CredentialVariable enum. The environment variables an app's identity is read from.
 *
 * A `list<string>` before this, which meant the three names existed twice — once as constants to
 * read by and once as an array to print in the "not configured" message — and nothing tied the two
 * lists together. Adding a fourth credential meant remembering both.
 *
 * The failure this prevents is the usual one for a name: `getenv()` answers `false` for a variable
 * that does not exist and for one whose name is misspelled, so a typo here reads as *"no app is
 * configured"* — which is precisely the message the command would then print, naming the misspelled
 * variable as the one to set.
 *
 * `NEUROSYS_SOUNDCLOUD_HOME` is deliberately not a case: it moves where {@link TokenStore} writes
 * and is read only by the tests, so it is not part of an app's identity and nothing should list it
 * beside these.
 */
enum CredentialVariable: string
{
    /** The client id, from the app's page on soundcloud.com. */
    case ClientId = 'NEUROSYS_SOUNDCLOUD_CLIENT_ID';

    /** The client secret, from the same page. */
    case ClientSecret = 'NEUROSYS_SOUNDCLOUD_CLIENT_SECRET';

    /** The redirect URI registered with the app, byte for byte as it was registered. */
    case RedirectUri = 'NEUROSYS_SOUNDCLOUD_REDIRECT_URI';

    /**
     * What the environment holds for this variable, or null where it holds nothing usable.
     *
     * An empty value is the same as an absent one here: a variable exported as `''` is somebody
     * part-way through configuring this, not a credential.
     *
     * @return string|null
     */
    public function read(): ?string
    {
        $value = getenv($this->value);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
