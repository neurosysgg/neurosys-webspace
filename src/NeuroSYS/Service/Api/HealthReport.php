<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Api;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Exception\UpdateException;
use NeuroSYS\Http\Api\ApiHandler;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ServerVariable;
use NeuroSYS\Model\Health\HealthFact;
use NeuroSYS\Model\Health\HealthSection;
use NeuroSYS\Model\Health\PhpExtension;
use NeuroSYS\Model\Health\PhpSetting;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\File;

/**
 * The HealthReport class. What this deployment is, asked of the deployment itself.
 *
 * **It exists because every fact below is currently asserted somewhere and checked nowhere.** The
 * four extensions this site is a fatal without are named in `composer.json`, which never runs on
 * the server, and in `test/basic_test.sh`, which runs `php` from `$PATH` on a developer's machine.
 * The live host's error configuration is stated as measured fact in five separate docblocks —
 * {@link \NeuroSYS\Model\Update\UpdateReport}, {@link \NeuroSYS\Controller\ApiController},
 * {@link UpdatePatch}, {@link ApiHandler} and {@link \NeuroSYS\Service\UpdateApplier} — every one
 * of them a copy of one measurement taken by hand. Nothing has ever asked the runtime that
 * actually answers requests. This does.
 *
 * **It does not supersede {@link UpdateVersion} and must not be made to.** That one answers "did my
 * push land" in three lines you read in a second, and it is what a deploy ends with. This answers
 * "is this host still what I think it is", which is a different question asked at a different time.
 * The two overlap on `PHP_VERSION` and nothing else, and even that costs nothing: both read the
 * same constant rather than two spellings of one fact.
 *
 * **The replay serial is deliberately not here**, though a deployment section is exactly where one
 * would expect it. `update version` reports it, and a second report of it would be a second place
 * to keep in step — which `GuidelineTest`'s two-files clause said out loud when this class first
 * had the line: the word `serial` exists in {@link \NeuroSYS\Model\Api\ApiEnvelope} because it
 * is a key of the signed manifest, and writing it again here would have forced an excuse onto that
 * class for a caption of this one's.
 *
 * **Everything here is safe to say only because nothing unsigned can reach it.** A PHP version, a
 * SAPI, an extension list and a server's own uname are reconnaissance; behind
 * {@link \NeuroSYS\Service\ApiGate} they are a report to the one person holding the private key.
 * That is the same inversion every other diagnostic past the gate makes — see
 * {@link ApiHandler::handle()} — and it is why this is a service under `/api` rather than the
 * public `/health` a monitor would ping.
 *
 * **It reads no query parameter and never will.** The signature covers the method, the path, the
 * body's digest and the manifest, and it does not cover the query string; a parameter read here
 * would be the one input reaching a verified handler unsigned. There is nothing to configure, so
 * there is nothing to smuggle — see `docs/security.md`.
 *
 * A read: it writes nothing, consumes no serial, and touches only files it opens for reading.
 */
final readonly class HealthReport implements ApiHandler
{
    /** What an extension, or a file that ought to be there, says when it is. */
    private const string PRESENT = 'present';

    /** And when it is not. Upper case because an absence is the only thing here worth catching an eye. */
    private const string MISSING = 'MISSING';

    /** What a file this site reads says when it is not there. Whether that is a fault is the next column. */
    private const string ABSENT = 'absent';

    /** The repository carries this file, so every clone has it and an absence is a fault. */
    private const string TRACKED = '  (tracked)';

    /** It does not, so an absence is a state: no demos staged, no gate, no key. */
    private const string UNTRACKED = '  (untracked)';

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
        $body = $this->sections()
            ->map(static fn(HealthSection $section): string => $section->render())
            ->join("\n\n");

        return new PlainTextResponse(HttpStatusCode::Ok, $body . "\n");
    }

    /**
     * Every section, in the order they are worth reading.
     *
     * The error log's is last and is the only one that may be absent — a deployment whose
     * `error_log` is empty has no file to quote, which is itself the answer and is already said in
     * the `errors` section above it.
     *
     * @return Collection<HealthSection>
     */
    private function sections(): Collection
    {
        $sections = new Collection(HealthSection::class)->with(
            $this->php(),
            $this->extensions(),
            $this->errors(),
            $this->host(),
            $this->deployment(),
        );

        $log = $this->log();

        return $log === null ? $sections : $sections->with($log);
    }

    /**
     * The runtime: what it is, and what it will let a request do.
     *
     * @return HealthSection
     */
    private function php(): HealthSection
    {
        return HealthSection::facts('php', new Collection(HealthFact::class)
            ->with(
                new HealthFact('version', PHP_VERSION),
                new HealthFact('sapi', PHP_SAPI),
                new HealthFact('os', PHP_OS_FAMILY),
            )
            ->with(...self::settings(false)));
    }

    /**
     * The four extensions, each asked by being used.
     *
     * @return HealthSection
     */
    private function extensions(): HealthSection
    {
        return HealthSection::facts('extensions', new Collection(PhpExtension::class)
            ->with(...PhpExtension::cases())
            ->map(static fn(PhpExtension $extension): HealthFact => new HealthFact(
                $extension->value,
                $extension->isPresent() ? self::PRESENT : self::MISSING,
            )));
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
            ->with(...self::settings(true))
            ->with(new HealthFact('last', self::lastDiagnostic())));
    }

    /**
     * The machine, and its clock.
     *
     * The time is not decoration: {@link \NeuroSYS\Service\ApiGate} refuses a credential whose
     * serial sits more than five minutes from this clock, and that is cause number two in the list
     * {@link \NeuroSYS\Tool\Command\ApiCall} prints when a call comes back refused. There is a
     * chicken and an egg in it — a clock far enough out refuses the very call that would report it
     * — but the interesting case is the one that is drifting rather than the one that has already
     * gone, and that is the case this catches.
     *
     * @return HealthSection
     */
    private function host(): HealthSection
    {
        return HealthSection::facts('host', new Collection(HealthFact::class)->with(
            new HealthFact('server', ServerVariable::ServerSoftware->string() ?? ''),
            new HealthFact('protocol', ServerVariable::ServerProtocol->string() ?? ''),
            new HealthFact('system', php_uname()),
            new HealthFact('clock', date(DATE_ATOM)),
        ));
    }

    /**
     * This deployment: where it serves from, and which of the files it reads are there.
     *
     * **{@link DataFile::UpdateKey} can never be reported absent**, and that is worth knowing
     * rather than confusing: a report anybody is reading verified against it. Its line is always
     * `present`, and the size beside it is what tells a whole key from a truncated paste.
     *
     * What is *not* here is the replay serial — see the note on this class.
     *
     * @return HealthSection
     */
    private function deployment(): HealthSection
    {
        return HealthSection::facts('deployment', new Collection(HealthFact::class)
            ->with(new HealthFact('webroot', self::webroot()))
            ->with(...new Collection(DataFile::class)
                ->with(...DataFile::cases())
                ->map(static fn(DataFile $file): HealthFact => new HealthFact(
                    $file->value,
                    self::state($file),
                ))));
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
            return HealthSection::lines('error log', $path . ' — no file there to read');
        }

        if ($file->size() > self::MAX_LOG) {
            return HealthSection::lines('error log', sprintf(
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
            'error log',
            sprintf('%s — %d bytes, last %d of %d lines', $path, $file->size(), count($tail), count($lines)),
            ...$tail,
        );
    }

    /**
     * The php.ini directives on one side or the other of {@link PhpSetting::isAboutErrors()}.
     *
     * Filtered from `cases()` rather than listed per section, the way
     * {@link \NeuroSYS\Http\Allow::readOnly()} filters the methods: a directive added to that enum
     * lands in a section without anybody remembering to put it there.
     *
     * @param bool $aboutErrors
     * @return Collection<HealthFact>
     */
    private static function settings(bool $aboutErrors): Collection
    {
        return new Collection(PhpSetting::class)
            ->with(...PhpSetting::cases())
            ->where(static fn(PhpSetting $setting): bool => $setting->isAboutErrors() === $aboutErrors)
            ->map(static fn(PhpSetting $setting): HealthFact => new HealthFact(
                $setting->value,
                $setting->configured(),
            ));
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

    /**
     * Where this deployment serves from, or why it cannot say.
     *
     * **Caught rather than allowed to propagate**, which is the one place this handler differs from
     * every other action past the gate. {@link Config::webroot()} refuses a `DOCUMENT_ROOT` it
     * cannot vouch for, and that refusal is an {@link UpdateException} — so left alone it would
     * reach {@link \NeuroSYS\Controller\ApiController}'s catch and turn the whole report into a
     * 422. A health check that will not report because something is unhealthy is not a health
     * check; the refusal's own sentence is the most useful thing on this line.
     *
     * @return string
     */
    private static function webroot(): string
    {
        try {
            return Config::webroot()->path;
        } catch (UpdateException $refusal) {
            return $refusal->getMessage();
        }
    }

    /**
     * Whether one of the files this site reads is there, and whether its absence would be a fault.
     *
     * **Both halves are always reported, rather than an absence being labelled a fault.** The
     * tracked flag is a fact about the repository and the file's presence is a fact about this
     * machine; printing them side by side lets `absent  (tracked)` read as the fault it is without
     * this class inventing a severity word for it — and it means neither branch here can be one a
     * real deployment never reaches.
     *
     * @param DataFile $file
     * @return string
     */
    private static function state(DataFile $file): string
    {
        $handle = Config::dataFile($file);
        $state  = $handle->exists() ? self::PRESENT . '  ' . $handle->size() : self::ABSENT;

        return $state . ($file->isTracked() ? self::TRACKED : self::UNTRACKED);
    }
}
