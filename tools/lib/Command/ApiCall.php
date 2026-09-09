<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use BackedEnum;
use NeuroSYS\Http\Api\ApiAction;
use NeuroSYS\Http\Api\ApiService;
use NeuroSYS\Http\Api\ApiVersion;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Api\PrivateKey;
use NeuroSYS\Tool\Api\SignedRequest;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Option;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\UsageException;
use NeuroSYS\Tool\Http\CurlTransport;
use NeuroSYS\Tool\Http\Transport;
use NeuroSYS\Tool\Http\TransportException;
use NeuroSYS\Tool\Http\Url;

/**
 * The ApiCall command. One signed call to `/api`, named on the command line.
 *
 * `php tools/api.php update v1 version` is the whole of it. It is the client for every action that
 * carries **no body** — which today is every action but `patch`, and that one has
 * {@link PushUpdate} because building the tree it sends is most of what that command does.
 *
 * **It refuses an action it does not recognise before sending anything**, which is not politeness:
 * `/api` answers an unrecognised address exactly as it answers a wrong signature, so a typo here
 * would come back as the same 404 as a bad key and send somebody looking at their key. The
 * vocabulary is the server's own {@link ApiService} and {@link ApiVersion}, so this cannot be wrong
 * about what exists.
 *
 * **And it refuses an action that takes a body**, for the honest reason: this command has no way to
 * produce one. A `--body-file` would be the beginning of a general-purpose HTTP client, which is
 * not what this is — an action with a body is an action whose payload somebody has to build, and
 * that is a command of its own.
 */
final readonly class ApiCall implements Command
{
    /** Which deployment a call goes to unless `--url` says otherwise. An origin, not an endpoint. */
    private const string DEFAULT_BASE = 'https://neurosys.gg';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Transport|null $transport A test seam; production sends over curl.
     */
    public function __construct(private ?Transport $transport = null) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'api';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '<service> <version> <action> [--url <origin>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Make one signed call to the owner-only API.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return ApiCallOption::cases();
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        if ($input->operandCount() !== 3) {
            $output->error(sprintf("%s: %s\n", $this->name(), $this->usage()));

            return ExitCode::Usage;
        }

        $service = ApiService::tryFrom($input->operand(0) ?? '');
        $version = ApiVersion::tryFrom($input->operand(1) ?? '');
        $action  = $service?->action($version ?? ApiVersion::V1, $input->operand(2) ?? '');

        if ($service === null || $version === null || $action === null) {
            $output->error(sprintf(
                "%s: no such action — %s/%s/%s\n",
                $this->name(),
                $input->operand(0) ?? '',
                $input->operand(1) ?? '',
                $input->operand(2) ?? '',
            ));

            return ExitCode::Usage;
        }

        if (!$action instanceof BackedEnum || $action->method() !== HttpMethod::Get) {
            $output->error(sprintf(
                "%s: %s carries a body, so it has a command of its own.\n",
                $this->name(),
                $input->operand(2) ?? '',
            ));

            return ExitCode::Usage;
        }

        try {
            $request = SignedRequest::build(
                new Url($input->value(ApiCallOption::Url) ?? self::DEFAULT_BASE),
                $service,
                $version,
                $action,
                '',
                [],
                PrivateKey::fromFile($this->key($input)),
            );

            $response = ($this->transport ?? new CurlTransport())->send($request);
        } catch (UsageException | TransportException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return $exception instanceof UsageException ? ExitCode::Usage : ExitCode::Failure;
        }

        $output->out($response->body);

        if (!$response->isOk()) {
            // A read that is not verified gets the rendered 404 an address that is not there gets,
            // which is a whole HTML page — so say what it means rather than leaving the operator to
            // read markup. Same three causes a refused push has, in the same order.
            $output->error(sprintf(
                "\nrefused with %d.%s\n",
                $response->status,
                $response->status === 404
                    ? "\n  That is what /api answers to anything it will not verify — it does not"
                    . " say which check failed, by design. In order of likelihood:\n"
                    . "    1. data/update.pub on the server does not match this private key\n"
                    . "    2. this machine's clock is more than five minutes from the server's\n"
                    . "    3. the server is older than /api"
                    : '',
            ));

            return ExitCode::Failure;
        }

        return ExitCode::Success;
    }

    /**
     * The private key, from `--key` or from the default under `$HOME`.
     *
     * @param Input $input
     * @return File
     *
     * @throws UsageException if `--key` was not given and `$HOME` is not set.
     */
    private function key(Input $input): File
    {
        $given = $input->value(ApiCallOption::Key);
        if ($given !== null) {
            return new File($given);
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new UsageException('HOME is not set, so --key must name the private key.');
        }

        return new File($home . '/' . PrivateKey::DEFAULT_PATH);
    }
}
