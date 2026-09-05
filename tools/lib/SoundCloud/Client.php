<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use JsonException;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\TopLevelType;
use NeuroSYS\Support\Charset;
use NeuroSYS\Support\Collection;
use NeuroSYS\Tool\Http\FormField;
use NeuroSYS\Tool\Http\OutboundHeader;
use NeuroSYS\Tool\Http\Request;
use NeuroSYS\Tool\Http\Response;
use NeuroSYS\Tool\Http\Transport;

/**
 * The Client class. Everything this repo knows how to ask SoundCloud.
 *
 * Three verbs — authorize once, upload a track, read one back — and the token handling underneath
 * them, which is most of the code and all of the care.
 *
 * **The transport is a constructor argument**, so every request this makes can be asserted against
 * without a network: `test/unit/SoundCloudTest.php` hands it one that answers from an array and
 * records what it was sent. That is the same reason `Cli\Output` takes its two streams, and the
 * same reason `SecurityHeaders::headers()` is public beside `send()` — the interesting half of
 * anything that ends in a socket is what it was about to say.
 *
 * **Uploading needs a user, so `client_credentials` is not an option.** A token issued to the app
 * alone belongs to nobody, and `POST /tracks` answers a token with no user behind it with a 401.
 * So this client authorizes against the account once, in a browser, and lives on refresh tokens
 * after that — which is why {@link TokenStore} is written the way it is.
 */
final readonly class Client
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Transport   $transport
     * @param Credentials $credentials
     * @param TokenStore  $tokens
     */
    public function __construct(
        private Transport   $transport,
        private Credentials $credentials,
        private TokenStore  $tokens,
    ) {}

    /**
     * Redeems the code a redirect carried, and stores the token it bought.
     *
     * @param Authorization $authorization The attempt that produced the URL the browser opened.
     * @param string        $redirected    The address the browser ended on, or the code alone.
     * @return AccessToken
     * @throws SoundCloudException if the redirect is not this attempt's, or the exchange fails.
     */
    public function exchange(Authorization $authorization, string $redirected): AccessToken
    {
        return $this->tokenRequest('the authorization', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'redirect_uri'  => $this->credentials->redirectUri,
            'code_verifier' => $authorization->verifier,
            'code'          => $authorization->code($redirected),
        ]);
    }

    /**
     * Uploads a track and answers with what now exists.
     *
     * The secret token is what the release page's player needs for a track that is not public yet,
     * and it is **read back where the creation response does not carry one**. Whether it does is
     * the sort of thing only a live account settles; asking for the track costs one request and
     * removes the question.
     *
     * @param TrackUpload $upload
     * @return UploadedTrack
     * @throws SoundCloudException if there is no usable token, or the API refuses.
     */
    public function upload(TrackUpload $upload): UploadedTrack
    {
        $track = UploadedTrack::fromResponse($this->json(
            'the upload',
            $this->transport->send(Request::multipart(
                Endpoint::Tracks->value,
                $upload->fields(),
                ...$this->headers($this->authorized()),
            )),
        ));

        if ($track->secretToken !== '' || $track->sharing === TrackSharing::Public || $track->id <= 0) {
            return $track;
        }

        return $this->track($track->id);
    }

    /**
     * Reads a track this account owns.
     *
     * @param int $id
     * @return UploadedTrack
     * @throws SoundCloudException if there is no usable token, or the API refuses.
     */
    public function track(int $id): UploadedTrack
    {
        return UploadedTrack::fromResponse($this->json(
            'the track',
            $this->transport->send(Request::get(
                Endpoint::track($id),
                ...$this->headers($this->authorized()),
            )),
        ));
    }

    /**
     * The token to use, refreshed if it is spent.
     *
     * @return AccessToken
     * @throws SoundCloudException if there is no token, or it cannot be renewed.
     */
    private function authorized(): AccessToken
    {
        $token = $this->tokens->read();

        if ($token === null) {
            throw new SoundCloudException(sprintf(
                'No token in %s. Run the command again with --authorize, which opens the one '
                . 'browser round trip this needs.',
                $this->tokens->file->path,
            ));
        }

        if (!$token->isExpired(time())) {
            return $token;
        }

        if (!$token->isRenewable()) {
            throw new SoundCloudException(
                'The stored token has expired and carries no refresh token, so there is nothing to '
                . 'renew it with. Run --authorize again.',
            );
        }

        return $this->tokenRequest('the refresh', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'refresh_token' => $token->refreshToken,
        ]);
    }

    /**
     * Asks the token endpoint for a token and writes what comes back.
     *
     * **The write happens here rather than at the call sites**, and immediately: SoundCloud's
     * refresh tokens are single-use, so the response to this request has already voided the one
     * that was spent. Anything between reading the answer and storing it is a window in which the
     * account has to be authorized by hand again.
     *
     * @param string                $what   What is being attempted, for the failure message.
     * @param array<string, string> $fields
     * @return AccessToken
     * @throws SoundCloudException if the endpoint refuses, or answers with no token.
     */
    private function tokenRequest(string $what, array $fields): AccessToken
    {
        $form = new Collection(FormField::class);

        foreach ($fields as $name => $value) {
            $form = $form->with(new FormField($name, $value));
        }

        $token = AccessToken::fromResponse(
            $this->json($what, $this->transport->send(Request::form(
                Endpoint::Token->value,
                $form,
                new Header(OutboundHeader::Accept, self::accept()),
            ))),
            time(),
        );

        $this->tokens->write($token);

        return $token;
    }

    /**
     * A response's body, or the exception for a response that failed.
     *
     * @param string   $what
     * @param Response $response
     * @return array<string, mixed>
     * @throws SoundCloudException if the status is not a success, or the body is not JSON.
     */
    private function json(string $what, Response $response): array
    {
        if (!$response->isOk()) {
            throw SoundCloudException::refused($what, $response);
        }

        try {
            return $response->json();
        } catch (JsonException $exception) {
            throw new SoundCloudException(
                sprintf('SoundCloud answered %s with something that is not JSON: %s', $what, $exception->getMessage()),
                $response->status,
                $response->body,
            );
        }
    }

    /**
     * The two headers every authenticated request carries.
     *
     * @param AccessToken $token
     * @return list<Header>
     */
    private function headers(AccessToken $token): array
    {
        return [
            new Header(OutboundHeader::Accept, self::accept()),
            new Header(OutboundHeader::Authorization, new OAuthCredential($token)),
        ];
    }

    /**
     * What the API is asked to answer with.
     *
     * SoundCloud's own examples send `application/json; charset=utf-8`, which is a
     * {@link MimeType} exactly: a type, a subtype and an encoding. It is built rather than written
     * out for the reason that class exists — the `; charset=` is a parameter with a grammar, and
     * this is the one place the site's own type would have been stapled together by hand.
     *
     * @return MimeType
     */
    private static function accept(): MimeType
    {
        return new MimeType(TopLevelType::Application, 'json', Charset::Utf8);
    }
}
