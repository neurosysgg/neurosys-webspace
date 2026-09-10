<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Health;

/**
 * The PhpSetting enum. The php.ini directives {@link \NeuroSYS\Service\Api\HealthReport} reads.
 *
 * **A directive name is typed here for the reason every name in this codebase is: getting one
 * wrong is silent.** `ini_get()` answers `false` for a directive that does not exist, which is
 * exactly what it answers for one that exists and is unset — so `ini_get('memory_limmit')` is not
 * an error anywhere, it is a report saying the limit is not configured. That is the same shape of
 * failure {@link \NeuroSYS\Http\ServerVariable} was written against, one layer down: a misspelling
 * indistinguishable from an absence.
 *
 * These are not settings this repository owns, which is the whole point of reading them. Two are
 * named in argument elsewhere and have never been checked: {@link \NeuroSYS\Support\File::read()}
 * calls `post_max_size` "a php.ini value nobody in this repository controls", and five docblocks
 * across `Http\Api` and `Service` assert that `display_errors` is off on the live host and
 * `error_log` is empty — measured once, by hand, and copied.
 *
 * Deliberately not exhaustive, and not a `phpinfo()`. What earns a case is a directive that
 * changes what this site can do: what it may allocate, how large a payload it may be sent, how
 * long it may run, and where a diagnostic goes when something has already gone wrong.
 */
enum PhpSetting: string
{
    /** What one request may allocate. A push holds the whole payload and its expansion at once. */
    case MemoryLimit = 'memory_limit';

    /**
     * The most a request body may weigh before PHP discards it.
     *
     * The ceiling {@link \NeuroSYS\Service\ApiGate::MAX_BODY} exists to sit under: a bound the
     * application states is worth more than one inherited from a php.ini nobody here owns — but
     * the inherited one still decides whether a push arrives at all, and it is the value that
     * would silently truncate one.
     */
    case PostMaxSize = 'post_max_size';

    /** Irrelevant to this site, which has no form and no upload, and reported for the same reason. */
    case UploadMaxFilesize = 'upload_max_filesize';

    /** How long a request may run. A push writes a few hundred files inside one. */
    case MaxExecutionTime = 'max_execution_time';

    /**
     * The clock's zone.
     *
     * Not decoration: `/api` refuses a credential whose serial sits more than
     * {@link \NeuroSYS\Service\ApiGate::MAX_SKEW} seconds from this clock, and a report that gives
     * a server time without saying which zone it is in cannot settle that question.
     */
    case Timezone = 'date.timezone';

    /**
     * Whether the opcode cache is on.
     *
     * The one directive here that can genuinely be absent rather than merely unset — a PHP built
     * without the extension has no such name at all — which is what {@link self::configured()}'s
     * cast is written for.
     */
    case OpcacheEnable = 'opcache.enable';

    /**
     * Whether a diagnostic is printed into the response.
     *
     * The first half of the pair five docblocks in this repository assert about the live host. It
     * has to be off there: `SecurityHeaders::send()` and the doctype have both gone out long
     * before most of what could warn, so a printed warning lands inside a page that is already
     * being written.
     */
    case DisplayErrors = 'display_errors';

    /** Whether a diagnostic is recorded at all. Off, and errors go nowhere in either direction. */
    case LogErrors = 'log_errors';

    /**
     * Where a recorded diagnostic goes, and the other half of that pair.
     *
     * Empty is a real answer rather than a missing one: it means the SAPI's own destination, which
     * under `cgi-fcgi` on shared hosting is a log this repository has no path to. That is the
     * measured claim underneath "it is the only account of the run there will be" on
     * {@link \NeuroSYS\Model\Update\UpdateReport} — and where it is *not* empty, it names the one
     * file worth reading when something has gone wrong.
     */
    case ErrorLog = 'error_log';

    /**
     * This directive's configured value, or `''` where there is none.
     *
     * **Cast rather than branched, deliberately.** `ini_get()` answers `string|false`, and for
     * every case above but {@link self::OpcacheEnable} the `false` cannot happen — the names are
     * real, which is what this enum is for — so a guard would be a line no test could reach.
     * `false` casts to `''`, which is already this report's word for "nothing to say" and which
     * {@link HealthFact} renders as a dash.
     *
     * @return string
     */
    public function configured(): string
    {
        return (string) ini_get($this->value);
    }

    /**
     * Whether this directive belongs to the report's `errors` section rather than its `php` one.
     *
     * Derived rather than listed twice, the way {@link \NeuroSYS\Http\Allow::readOnly()} filters
     * {@link \NeuroSYS\Http\HttpMethod::cases()} instead of writing the set out: the report builds
     * both sections from `cases()` and this predicate, so a directive added above cannot be
     * forgotten in the one place that shows it. It lands in a section either way.
     *
     * @return bool
     */
    public function isAboutErrors(): bool
    {
        return match ($this) {
            self::DisplayErrors, self::LogErrors, self::ErrorLog => true,
            self::MemoryLimit, self::PostMaxSize, self::UploadMaxFilesize,
            self::MaxExecutionTime, self::Timezone, self::OpcacheEnable => false,
        };
    }
}
