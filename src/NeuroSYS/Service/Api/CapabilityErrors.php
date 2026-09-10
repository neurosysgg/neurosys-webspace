<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Api;

use NeuroSYS\Http\Api\ApiHandler;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Response;
use NeuroSYS\Model\Health\HealthFact;
use NeuroSYS\Model\Health\HealthSection;
use NeuroSYS\Model\Health\PhpSetting;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\File;

/**
 * The CapabilityErrors class. Where a diagnostic goes on this host, the last one that got there,
 * and the tail of the log it was written to.
 *
 * **Evidence rather than a verdict**, which is why it is here and not under `health`. That
 * `display_errors` is off and `log_errors` on is a requirement, and `health v1 settings` checks it;
 * what the log says is what a reader goes looking for once something has gone wrong, and no floor
 * applies to it.
 *
 * It exists because the live host's error configuration was once stated as measured fact in five
 * separate docblocks, every one a copy of one measurement taken by hand. This re-takes it.
 *
 * A read: it writes nothing and consumes no serial, and opens only the log, only for reading, and
 * only when it is small enough to quote.
 */
final readonly class CapabilityErrors implements ApiHandler
{
    /**
     * The most of an error log this will read.
     *
     * A quarter of a megabyte, which is generous for a log that should be empty and small enough
     * that a host quietly writing to one for a year cannot be pulled through a response. Over it,
     * the size is reported and the file is not opened — see {@link self::log()}.
     */
    private const int MAX_LOG = 262_144;

    /** How many of the log's last lines to quote. Enough to see what happened, not enough to bury it. */
    private const int TAIL = 20;

    /** The caption of the log's section, which is present only where there is a log to speak of. */
    private const string LOG = 'error log';

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @return Response
     */
    public function handle(): Response
    {
        $errors = $this->errors();
        $log    = $this->log();

        return new PlainTextResponse(
            HttpStatusCode::Ok,
            $log === null ? HealthSection::document($errors) : HealthSection::document($errors, $log),
        );
    }

    /**
     * Where a diagnostic goes, and the last one that got there.
     *
     * **`error_get_last()` is the right tool here, and this codebase argues against it elsewhere.**
     * {@link \NeuroSYS\Support\Diagnostics} rejects it for being process-global and sticky, which
     * is true and is beside the point: that objection is about attributing a diagnostic to one
     * call, and this asks the process-global question on purpose. Better than that — a diagnostic
     * a userland handler takes never populates it at all, and every `@` in this repository is now
     * a `Diagnostics::muted()`. So what this line reports is precisely the diagnostics **nothing
     * here handled**, which is exactly the set worth knowing about.
     *
     * `reporting` comes from `error_reporting()` rather than from a {@link PhpSetting} case,
     * because it is the mask in force rather than a directive read — and because the number alone
     * is unreadable, so the one comparison worth making is made here.
     *
     * @return HealthSection
     */
    private function errors(): HealthSection
    {
        $mask = error_reporting();

        return HealthSection::facts('errors', new Collection(HealthFact::class)
            ->with(new HealthFact('reporting', $mask . ($mask === E_ALL ? ' (E_ALL)' : '')))
            ->with(...new Collection(PhpSetting::class)
                ->with(PhpSetting::DisplayErrors, PhpSetting::LogErrors, PhpSetting::ErrorLog)
                ->map(static fn(PhpSetting $setting): HealthFact => new HealthFact(
                    $setting->value,
                    $setting->configured(),
                ))
                ->toValues())
            ->with(new HealthFact('last', self::lastDiagnostic())));
    }

    /**
     * The error log's last lines, or null where there is no log to read.
     *
     * Three answers rather than one, because "there is no tail" has three quite different causes
     * and the difference is the whole diagnostic: no destination configured at all, a destination
     * naming nothing, and a file too large to quote. The first is the live host's own state — see
     * {@link PhpSetting::ErrorLog} — and the section is simply absent for it, since the `errors`
     * section above has already said the destination is empty.
     *
     * The path is whatever the directive holds, which need not be a file at all: `syslog` is a
     * legal value and reads here as a destination that is not there. That is honest rather than
     * wrong — this cannot quote a syslog either.
     *
     * @return HealthSection|null
     */
    private function log(): ?HealthSection
    {
        $path = PhpSetting::ErrorLog->configured();

        if ($path === '') {
            return null;
        }

        $file = new File($path);

        if (!$file->exists()) {
            return HealthSection::lines(self::LOG, $path . ' — no file there to read');
        }

        if ($file->size() > self::MAX_LOG) {
            return HealthSection::lines(self::LOG, sprintf(
                '%s — %d bytes, too large to quote here',
                $path,
                $file->size(),
            ));
        }

        $lines = $file->lines();
        $tail  = array_slice($lines, -self::TAIL);

        // Both counts, because either alone is misleading: the total says whether the log is busy,
        // and the shown count says how much of it is below — and for an empty log, which is what
        // the live host's ought to be, they agree at zero and the section says so rather than
        // promising twenty lines and printing none.
        return HealthSection::lines(
            self::LOG,
            sprintf('%s — %d bytes, last %d of %d lines', $path, $file->size(), count($tail), count($lines)),
            ...$tail,
        );
    }

    /**
     * The last diagnostic this worker recorded, or a sentence saying there is none.
     *
     * @return string
     */
    private static function lastDiagnostic(): string
    {
        $last = error_get_last();

        return $last === null
            ? 'nothing this worker recorded'
            : sprintf('%s at %s:%d', $last['message'], $last['file'], $last['line']);
    }
}
