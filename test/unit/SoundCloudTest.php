<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use InvalidArgumentException;
use ArrayObject;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Genre;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Http\FilePart;
use NeuroSYS\Tool\Http\FormField;
use NeuroSYS\Tool\Http\JsonBody;
use NeuroSYS\Tool\Http\OutboundHeader;
use NeuroSYS\Tool\Http\Request;
use NeuroSYS\Tool\Http\Response;
use NeuroSYS\Tool\Http\Transport;
use NeuroSYS\Tool\SoundCloud\AccessToken;
use NeuroSYS\Tool\Http\Url;
use NeuroSYS\Tool\SoundCloud\Attempt;
use NeuroSYS\Tool\SoundCloud\Authorization;
use NeuroSYS\Tool\SoundCloud\Client;
use NeuroSYS\Tool\SoundCloud\CredentialVariable;
use NeuroSYS\Tool\SoundCloud\Credentials;
use NeuroSYS\Tool\SoundCloud\Endpoint;
use NeuroSYS\Tool\SoundCloud\SoundCloudException;
use NeuroSYS\Tool\SoundCloud\TokenKey;
use NeuroSYS\Tool\SoundCloud\TokenStore;
use NeuroSYS\Tool\SoundCloud\TrackField;
use NeuroSYS\Tool\SoundCloud\TrackKey;
use NeuroSYS\Tool\SoundCloud\TrackSharing;
use NeuroSYS\Tool\SoundCloud\TrackUpload;
use NeuroSYS\Tool\SoundCloud\UploadedTrack;
use PHPUnit\Framework\TestCase;

/**
 * The SoundCloud client under `tools/lib/SoundCloud/`.
 *
 * **Every request in here is answered from an array**, which is the point of `Http\Transport` being
 * an interface: no app is registered yet, so nothing has ever been uploaded, and the half of this
 * worth pinning is the half that is decided — what goes out, under which names, with which token.
 *
 * That is the same reason `SecurityHeaders::headers()` is public beside `send()`: a method ending
 * in a socket cannot be asserted against, so everything worth asserting lives beside it.
 *
 * Two things here would be silent in production and are therefore the reason the file exists. A
 * multipart field name that is wrong uploads the file and leaves a track with something missing —
 * see {@link TrackField}. And a refresh token that is not stored the moment it is issued costs a
 * browser round trip to recover from, because SoundCloud's rotate and the old one is already dead.
 */
final class SoundCloudTest extends TestCase
{
    /** A directory for token files, so no test writes anywhere near a real one. */
    private Directory $directory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = Directory::temporary('neurosys-soundcloud-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    /**
     * A transport that answers from a list and records what it was asked.
     *
     * Anonymous, for the reason `CliTest`'s stub command is: the real class is `final`, as it should
     * be, and `phpcs` holds a test file to one named class. The recorder is an `ArrayObject` passed
     * in rather than a property read back off the interface, so what the test inspects is a value it
     * already holds.
     *
     * @param ArrayObject<int, Request> $sent
     * @param list<Response>            $responses
     * @return Transport
     */
    private function transport(ArrayObject $sent, array $responses): Transport
    {
        return new class ($sent, $responses) implements Transport {
            /**
             * @param ArrayObject<int, Request> $sent
             * @param list<Response>            $responses
             */
            public function __construct(private ArrayObject $sent, private array $responses) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function send(Request $request): Response
            {
                $this->sent->append($request);

                return array_shift($this->responses) ?? new Response(500, '{"error":"the test ran out of answers"}');
            }
        };
    }

    /**
     * A request's fields, back in the map shape a table of expectations reads well as.
     *
     * @param Request $request
     * @return array<string, string|FilePart>
     */
    private static function sent(Request $request): array
    {
        $fields = [];

        foreach ($request->fields as $field) {
            $fields[$field->name] = $field->value;
        }

        return $fields;
    }

    /**
     * @return Credentials
     */
    private function credentials(): Credentials
    {
        return new Credentials('client-id', 'client-secret', 'https://neurosys.gg/oauth/callback');
    }

    /**
     * @return TokenStore
     */
    private function store(): TokenStore
    {
        return new TokenStore($this->directory->file('soundcloud.json'));
    }

    /**
     * @param FilePart|null $audio
     * @return TrackUpload
     */
    private function upload(?FilePart $audio = null): TrackUpload
    {
        return new TrackUpload(
            'ill.',
            $audio ?? FilePart::at(new File(__FILE__), 'ill..wav'),
            TrackSharing::Private,
            'ill',
            'debut single',
            Genre::Dubstep,
        );
    }

    /**
     * The one piece of cryptography in the client, and the one with an exact definition.
     *
     * RFC 7636 spells the challenge as base64url *without* padding, and a server comparing the two
     * strings byte for byte rejects a padded one — so the `=` matters and this is where it is
     * asserted rather than assumed.
     *
     * @return void
     */
    public function testThePkceChallengeIsTheVerifiersUnpaddedBase64UrlSha256(): void
    {
        $verifier      = 'a-verifier-that-is-long-enough-to-be-one';
        $authorization = Authorization::begin($verifier);

        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $authorization->challenge(),
        );
        self::assertStringNotContainsString('=', $authorization->challenge());
    }

    /**
     * The verifier is the secret; the URL is the public half. They must not be the same string.
     *
     * @return void
     */
    public function testTheAuthorizeUrlCarriesTheChallengeAndNeverTheVerifier(): void
    {
        $authorization = Authorization::begin('the-verifier-nobody-else-may-see', 'the-state');
        $url           = $authorization->url($this->credentials())->render();

        self::assertStringStartsWith(Endpoint::Authorize->value . '?', $url);
        self::assertStringContainsString('code_challenge=' . $authorization->challenge(), $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertStringContainsString('state=the-state', $url);
        self::assertStringNotContainsString('the-verifier-nobody-else-may-see', $url);
    }

    /**
     * @return void
     */
    public function testTheCodeIsTakenOutOfTheAddressTheBrowserEndedOn(): void
    {
        $authorization = Authorization::begin('verifier', 'the-state');

        self::assertSame(
            'the-code',
            $authorization->code('https://neurosys.gg/oauth/callback?code=the-code&state=the-state'),
        );
    }

    /**
     * A redirect whose state is not this attempt's is an answer to somebody else's question.
     *
     * @return void
     */
    public function testARedirectCarryingADifferentStateIsRefused(): void
    {
        $authorization = Authorization::begin('verifier', 'the-state');

        $this->expectException(SoundCloudException::class);
        $this->expectExceptionMessageMatches('/different state/');

        $authorization->code('https://neurosys.gg/oauth/callback?code=the-code&state=somebody-elses');
    }

    /**
     * @return void
     */
    public function testARefusedAuthorizationIsReportedInTheProvidersOwnWords(): void
    {
        $authorization = Authorization::begin('verifier', 'the-state');

        $this->expectException(SoundCloudException::class);
        $this->expectExceptionMessageMatches('/access_denied/');

        $authorization->code('https://neurosys.gg/oauth/callback?error=access_denied&state=the-state');
    }

    /**
     * The address bar copied after the browser trimmed the query, which looks like an answer.
     *
     * @return void
     */
    public function testAnAddressWithNoQueryIsNotMistakenForACode(): void
    {
        $authorization = Authorization::begin('verifier', 'the-state');

        $this->expectException(SoundCloudException::class);
        $this->expectExceptionMessageMatches('/carries no query/');

        $authorization->code('https://neurosys.gg/oauth/callback');
    }

    /**
     * @return void
     */
    public function testACodePastedOnItsOwnIsTakenAsOne(): void
    {
        self::assertSame('the-code', Authorization::begin('verifier', 'x')->code('  the-code  '));
    }

    /**
     * The upload, field by field, under the names {@link TrackField} declares.
     *
     * The assertion is on the keys and not only the values, because a key is the half that fails
     * silently: SoundCloud drops a field it does not recognise and answers 201 regardless.
     *
     * @return void
     */
    public function testTheUploadSendsEveryFieldUnderItsDeclaredName(): void
    {
        $sent = new ArrayObject();

        $this->store()->write(new AccessToken('the-token', time() + 3600, 'refresh'));

        $client = new Client(
            $this->transport($sent, [new Response(201, (string) json_encode([
                'id'            => 2394077313,
                'permalink'     => 'ill',
                'secret_token'  => 's-dIMAqki109G',
                'permalink_url' => 'https://soundcloud.com/neurosysgg/ill',
                'sharing'       => 'private',
            ]))]),
            $this->credentials(),
            $this->store(),
        );

        $client->upload($this->upload());

        /** @var Request $request */
        $request = $sent[0];

        $fields = self::sent($request);

        self::assertSame(Endpoint::Tracks->value, $request->url->render());
        self::assertSame('POST', $request->method->value);
        self::assertTrue($request->multipart);
        self::assertSame(
            'OAuth the-token',
            $request->header(OutboundHeader::Authorization)?->value->render(),
        );
        self::assertSame(
            'application/json; charset=utf-8',
            $request->header(OutboundHeader::Accept)?->value->render(),
        );
        self::assertSame([
            TrackField::Title->value       => 'ill.',
            TrackField::AssetData->value   => $fields[TrackField::AssetData->value],
            TrackField::Sharing->value     => 'private',
            TrackField::Permalink->value   => 'ill',
            TrackField::Description->value => 'debut single',
            TrackField::Genre->value       => 'Dubstep',
        ], $fields);
        self::assertInstanceOf(FilePart::class, $fields[TrackField::AssetData->value]);
    }

    /**
     * Private on the way up, always, and with no flag anywhere that says otherwise.
     *
     * @return void
     */
    public function testAnUploadIsPrivateUnlessSomethingSaidOtherwise(): void
    {
        $sharing = $this->upload()->fields()->all()[2];

        self::assertSame(TrackSharing::Private, $this->upload()->sharing);
        self::assertSame(TrackField::Sharing->value, $sharing->name);
        self::assertSame('private', $sharing->value);
    }

    /**
     * A field with nothing in it is left out rather than sent empty — `Element::attr()`'s rule.
     *
     * @return void
     */
    public function testAnEmptyFieldIsLeftOutRatherThanSentEmpty(): void
    {
        $names = array_map(
            static fn(FormField $field): string => $field->name,
            new TrackUpload('ill.', FilePart::at(new File(__FILE__)))->fields()->all(),
        );

        self::assertSame(
            [TrackField::Title->value, TrackField::AssetData->value, TrackField::Sharing->value],
            $names,
        );
    }

    /**
     * @return void
     */
    public function testARefusalCarriesTheStatusAndWhatTheApiSaidAboutIt(): void
    {
        $this->store()->write(new AccessToken('the-token', time() + 3600, 'refresh'));

        $refusal = new Response(422, '{"errors":[{"error_message":"title is required"}]}');
        $client  = new Client(
            $this->transport(new ArrayObject(), [$refusal]),
            $this->credentials(),
            $this->store(),
        );

        try {
            $client->upload($this->upload());
            self::fail('a 422 should not have been read as a track');
        } catch (SoundCloudException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('title is required', $exception->getMessage());
            self::assertStringContainsString('UnprocessableContent', $exception->getMessage());
        }
    }

    /**
     * The one sequence that costs a browser round trip when it goes wrong.
     *
     * A refresh token is single-use: the response that carries the next one has already voided the
     * one that was spent. So the store has to hold the new one *before* the upload goes out, and
     * this asserts the order rather than only the outcome.
     *
     * @return void
     */
    public function testAnExpiredTokenIsRefreshedAndTheNewRefreshTokenIsStoredBeforeAnythingElse(): void
    {
        $sent = new ArrayObject();

        $this->store()->write(new AccessToken('stale', time() - 10, 'the-old-refresh'));

        $client = new Client(
            $this->transport($sent, [
                new Response(200, (string) json_encode([
                    'access_token'  => 'the-fresh-token',
                    'expires_in'    => 3600,
                    'refresh_token' => 'the-new-refresh',
                ])),
                new Response(201, (string) json_encode([
                    'id'            => 1,
                    'permalink'     => 'ill',
                    'secret_token'  => 's-token',
                    'permalink_url' => 'https://soundcloud.com/neurosysgg/ill',
                    'sharing'       => 'private',
                ])),
            ]),
            $this->credentials(),
            $this->store(),
        );

        $client->upload($this->upload());

        /** @var Request $refresh */
        $refresh = $sent[0];
        /** @var Request $upload */
        $upload = $sent[1];

        self::assertSame(Endpoint::Token->value, $refresh->url->render());
        self::assertSame('refresh_token', self::sent($refresh)['grant_type']);
        self::assertSame('the-old-refresh', self::sent($refresh)['refresh_token']);
        self::assertSame(
            'OAuth the-fresh-token',
            $upload->header(OutboundHeader::Authorization)?->value->render(),
        );
        self::assertSame('the-new-refresh', $this->store()->read()?->refreshToken);
    }

    /**
     * @return void
     */
    public function testAnUnexpiredTokenIsUsedWithoutAskingForAnother(): void
    {
        $sent = new ArrayObject();

        $this->store()->write(new AccessToken('still-good', time() + 3600, 'refresh'));

        new Client(
            $this->transport($sent, [new Response(201, (string) json_encode([
                'id'           => 1,
                'permalink'    => 'ill',
                'secret_token' => 's-token',
                'sharing'      => 'private',
            ]))]),
            $this->credentials(),
            $this->store(),
        )->upload($this->upload());

        self::assertCount(1, $sent);
        self::assertSame(Endpoint::Tracks->value, $sent[0]->url->render());
    }

    /**
     * @return void
     */
    public function testWithoutAStoredTokenTheClientSaysWhichCommandGetsOne(): void
    {
        $client = new Client($this->transport(new ArrayObject(), []), $this->credentials(), $this->store());

        $this->expectException(SoundCloudException::class);
        $this->expectExceptionMessageMatches('/--authorize/');

        $client->upload($this->upload());
    }

    /**
     * Whether a creation response carries the token is a live-account question; this is the answer
     * to it being no.
     *
     * @return void
     */
    public function testACreatedTrackWithNoSecretTokenIsReadBackRatherThanPublishedWithout(): void
    {
        $sent = new ArrayObject();

        $this->store()->write(new AccessToken('the-token', time() + 3600, 'refresh'));

        $track = new Client(
            $this->transport($sent, [
                new Response(201, (string) json_encode([
                    'id'        => 2394077313,
                    'permalink' => 'ill',
                    'sharing'   => 'private',
                ])),
                new Response(200, (string) json_encode([
                    'id'           => 2394077313,
                    'permalink'    => 'ill',
                    'secret_token' => 's-dIMAqki109G',
                    'sharing'      => 'private',
                ])),
            ]),
            $this->credentials(),
            $this->store(),
        )->upload($this->upload());

        self::assertSame(Endpoint::track(2394077313)->render(), $sent[1]->url->render());
        self::assertSame('s-dIMAqki109G', $track->secretToken);
    }

    /**
     * The three ids the release page has been carrying a placeholder for.
     *
     * @return void
     */
    public function testAnUploadedTrackBecomesTheSitesOwnEmbed(): void
    {
        $embed = UploadedTrack::fromResponse(new JsonBody([
            'id'           => 2394077313,
            'permalink'    => 'ill',
            'secret_token' => 's-dIMAqki109G',
            'sharing'      => 'private',
        ]))->embed();

        self::assertSame(2394077313, $embed->trackId);
        self::assertSame('ill', $embed->permalink);
        self::assertSame('s-dIMAqki109G', $embed->secretToken);
    }

    /**
     * A response read wrongly is silent; the site's own model is what makes it loud.
     *
     * @return void
     */
    public function testAResponseThatDescribesNoTrackIsRefusedByTheModel(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        UploadedTrack::fromResponse(new JsonBody(['permalink' => 'ill']))->embed();
    }

    /**
     * @return void
     */
    public function testTheTokenFileIsWrittenForItsOwnerOnlyAndReadBack(): void
    {
        // Nested, because the directory is created too: on a fresh machine ~/.config/neurosys
        // does not exist, and a store that could not make it would send you to a browser.
        $store = new TokenStore($this->directory->directory('nested')->file('soundcloud.json'));
        $token = new AccessToken('value', 1_800_000_000, 'refresh', 'non-expiring');

        $store->write($token);

        self::assertSame('0600', substr(sprintf('%o', fileperms($store->file->path)), -4));
        self::assertSame('value', $store->read()?->value);
        self::assertSame('refresh', $store->read()?->refreshToken);
        self::assertSame(1_800_000_000, $store->read()?->expiresAt);

        $store->file->directory()->remove();
    }

    /**
     * @return void
     */
    public function testAnUnreadableTokenFileIsLoudRatherThanTreatedAsAbsent(): void
    {
        $store = $this->store();

        $store->file->write('not json at all');

        $this->expectException(SoundCloudException::class);
        $this->expectExceptionMessageMatches('/not readable as JSON/');

        $store->read();
    }

    /**
     * @return void
     */
    public function testCredentialsAreAllThreeOrNothing(): void
    {
        putenv(CredentialVariable::ClientId->value . '=an-id');
        putenv(CredentialVariable::ClientSecret->value . '=a-secret');

        self::assertNull(Credentials::fromEnvironment(), 'two thirds of a credential is not one');

        putenv(CredentialVariable::RedirectUri->value . '=https://neurosys.gg/oauth/callback');

        self::assertSame('an-id', Credentials::fromEnvironment()?->clientId);

        // An exported-but-empty variable is somebody part-way through configuring this.
        putenv(CredentialVariable::ClientId->value . '=');

        self::assertNull(CredentialVariable::ClientId->read());
        self::assertNull(Credentials::fromEnvironment());

        foreach (CredentialVariable::cases() as $variable) {
            putenv($variable->value);
        }

        self::assertNull(Credentials::fromEnvironment());
    }

    /**
     * A token endpoint that answers without a token is a failure, not an empty token.
     *
     * @return void
     */
    public function testATokenResponseWithNoTokenIsRefused(): void
    {
        $this->expectException(SoundCloudException::class);

        AccessToken::fromResponse(new JsonBody(['expires_in' => 3600]), 1_800_000_000);
    }

    /**
     * The margin exists so that a token valid when a 50 MB upload starts is still valid when it
     * ends.
     *
     * @return void
     */
    public function testATokenAboutToExpireCountsAsExpired(): void
    {
        $token = new AccessToken('value', 1_000, 'refresh');

        self::assertFalse($token->isExpired(900));
        self::assertTrue($token->isExpired(950));
        self::assertTrue($token->isExpired(1_000));
    }

    // ─────────────────── The names a response is read under ───────────────────

    /**
     * A key that is absent, null, or carries the wrong type all read as the same absence.
     *
     * That is not new behaviour: it is what each of the nine hand-written
     * `is_string($body['x'] ?? null) ? … : ''` reads collapsed to before {@link JsonBody} existed.
     * Pinned because the whole value of moving them into one class is that they now fail together.
     *
     * @return void
     */
    public function testAnUnreadableKeyAnswersWithTheEmptyValueForItsType(): void
    {
        $body = new JsonBody(['permalink' => null, 'secret_token' => 42, 'id' => 'not a number']);

        self::assertSame('', $body->string(TrackKey::Permalink));
        self::assertSame('', $body->string(TrackKey::SecretToken));
        self::assertSame('', $body->string(TrackKey::PermalinkUrl));
        self::assertSame(0, $body->int(TrackKey::Id));
    }

    /**
     * A JSON number that arrived quoted is still the number the provider meant.
     *
     * @return void
     */
    public function testANumberIsReadWhicheverWayItArrived(): void
    {
        self::assertSame(7, new JsonBody(['id' => 7])->int(TrackKey::Id));
        self::assertSame(7, new JsonBody(['id' => '7'])->int(TrackKey::Id));
    }

    /**
     * The store's keys are {@link TokenKey}'s, so a token written by one run is readable by the next.
     *
     * The round trip is the assertion rather than the literal spellings: what would break this is
     * {@link AccessToken::toArray()} and {@link AccessToken::fromArray()} disagreeing, which is
     * exactly what happened while the four keys were written out twice.
     *
     * @return void
     */
    public function testATokenSurvivesTheRoundTripThroughItsStoredForm(): void
    {
        $token  = new AccessToken('the-value', 1_800_000_000, 'the-refresh', 'non-expiring');
        $stored = $token->toArray();

        self::assertSame(
            ['access_token', 'expires_at', 'refresh_token', 'scope'],
            array_keys($stored),
        );

        $read = AccessToken::fromArray(new JsonBody($stored));

        self::assertSame('the-value', $read?->value);
        self::assertSame(1_800_000_000, $read?->expiresAt);
        self::assertSame('the-refresh', $read?->refreshToken);
        self::assertSame('non-expiring', $read?->scope);
    }

    /**
     * The wire says how long the token lasts; the store says when it stops. Both are
     * {@link TokenKey} cases, and this is the line that turns one into the other.
     *
     * @return void
     */
    public function testTheWiresDurationBecomesTheStoresInstant(): void
    {
        $token = AccessToken::fromResponse(
            new JsonBody([TokenKey::ExpiresIn->value => 3_600, TokenKey::AccessToken->value => 'v']),
            1_800_000_000,
        );

        self::assertSame(1_800_003_600, $token->expiresAt);
    }

    /**
     * Each attempt reads back as the phrase its failure message needs.
     *
     * @return void
     */
    public function testARefusalNamesWhatWasBeingAttempted(): void
    {
        $refused = SoundCloudException::refused(Attempt::Upload, new Response(422, 'no'));

        self::assertStringContainsString('refused the upload with 422', $refused->getMessage());
        self::assertSame(422, $refused->status);
    }

    // ──────────────────────── The addresses it sends to ────────────────────────

    /**
     * Every address this tooling aims a request at is absolute and https.
     *
     * The one address on the site with nothing looking at it, before {@link Url}: the target of a
     * request carrying a client secret and a rotating refresh token.
     *
     * @return void
     */
    public function testEveryEndpointIsAnAbsoluteHttpsAddress(): void
    {
        foreach (Endpoint::cases() as $endpoint) {
            self::assertSame($endpoint->value, $endpoint->url()->render());
        }

        self::assertSame(
            Endpoint::Tracks->value . '/2394077313',
            Endpoint::track(2394077313)->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnAddressThatIsNotAbsoluteHttpsIsRefused(): void
    {
        foreach (['http://api.soundcloud.com/tracks', '/tracks', 'not a url', 'https://', ''] as $bad) {
            try {
                new Url($bad);
                self::fail(sprintf("Url accepted '%s'", $bad));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('absolute https://', $exception->getMessage());
            }
        }
    }
}
