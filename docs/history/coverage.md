# History — coverage and the checks' first runs

The current rules are in [../testing.md](../testing.md#coverage). This file keeps how the count moved,
each time the `#[CoversClass]` trap fired, and what each checker found the first time it ran.

## The coverage count

Most of these entries were one long paragraph in CLAUDE.md's Tests section, which stated the figure
and then recorded, pass by pass, that each new piece of work had left the count of uncovered lines at
twenty-six. They are split into one entry per pass here, in the order they happened.

### 2026-09-05 — 53 of 161 uncovered statements were unattributed (`156de9d`)

From testing.md's *A number is not a measurement*:

> PHPUnit restricts recorded coverage to what `#[CoversClass]` names, so a class no test file declares
> reads as 0% however thoroughly the suite exercises it. Before this was noticed, 53 of the 161
> uncovered statements were in that state — covered, unattributed.

### 2026-09-05 — the header-value gap arrives (`08f55ab`)

From testing.md's *Ten more, which are a gap rather than a decision*:

> These arrived with the header-value classes in `867372f` and nothing has exercised them since, so
> they are listed here to be closed rather than justified:

### 2026-09-06 — `DownloadLogger::log()` was thirteen lines (`7f52764`)

> It used to be thirteen: the locked append moved into `Support\File::append()`, which is tested
> directly, so what is left behind the switch is the switch and the entry it does not build.

### 2026-09-06 — `CacheControl::doNotStore()` was misfiled as unused (`31e8d7f`)

> `CacheControl::doNotStore()` used to be listed here as *"a factory no call site uses yet"*, which
> was already wrong when it was written (`StatsController` calls it) and is now doubly so: it is what
> every demo response says.

### 2026-09-06 — the demo work closes its own two

From the same testing.md section, and from CLAUDE.md:

> `DemoTest` closed the two of the same kind that the demo work added — `ContentLength`'s negative
> length and `RobotsPolicy::of()`'s empty list — so the pattern is now written down twice.

> The demo work added two of the same kind and closed both, so the pattern for closing the rest is
> written down in `DemoTest`.

testing.md also said, of its own claim that every uncovered line was deliberate:

> The rest of this document's claim — that every uncovered line is deliberate — held when it was
> written and does not now.

### 2026-09-08 — the `/update` work did not close its own lines (`1f9bf5e`)

> **The `/update` work is the one that did not manage it in the same pass, and that is recorded rather
> than tidied away.** It landed 436 lines with 57 of its own uncovered, and this paragraph stood for
> one commit asserting 98.42% while the truth was 95.96% — which is the exact failure the paragraph is
> written to prevent, so the number is worth re-deriving rather than carrying. Closing them was
> ordinary test-writing and two things worth keeping:
>
> - **Two guards were unreachable rather than untested, and the answer was to delete the branch, not
>   to cover it.** `UpdateRoot::of()` answers `?UpdateRoot`, which is honest about an arbitrary
>   string — but by the time the applier writes, every name has been through `check()`, which throws
>   for a name under no root. Asking again produced a null that could not happen, answered by a
>   `continue` reading "this member is quietly skipped", which is the one behaviour a mirroring
>   updater must never have. `check()` returns the root now and `UpdateFile` carries it; the mirror's
>   root loop moved up out of `surplusIn()` for the same reason. Dead defensive code is worse than
>   none here, because a reader cannot tell it apart from live code.
> - **The `#[CoversClass]` trap fired, and it is the one this file already warns about** — the front
>   end runs `--test-coverage-include-all` precisely so a module nothing imports is reported as
>   uncovered rather than not reported at all. PHP's half has no such switch: a test class that
>   declares any `#[CoversClass]` records coverage for *only* those classes, so `UpdateFile` read as
>   0% while being constructed on every push in the suite. A new class needs its line in that list or
>   it is invisible in both directions.

Writing those tests also turned up two live faults in `Config::webroot()` — a blank `DOCUMENT_ROOT`
and a nonexistent one. They are the API's story and are told once, in
[api.md](api.md#2026-09-08--two-live-faults-found-by-writing-the-tests-1f9bf5e).

### 2026-09-08 — the lazy-collection work: the same twenty-six (`fbb04d3`)

> **The lazy-collection work is the same twenty-six lines and not one more** — it added two
> of its own (`SectionPosition`'s range guard, and `first()`'s answer when a *pending chain* runs out,
> which is a different loop from the fast path's `array_find`) and closed both in the same pass, which
> is what that pattern is for.

### 2026-09-08 — the attribute-value work (`2405b8f`)

> **So is the attribute-value work**, which added three — `SitePath`'s
> arity guard both ways, and the `Accept-Language` parameter that is not a weight — and closed all
> three in the same pass.

### 2026-09-08 — the guideline work (`15a10f1`)

> **So is the guideline work**: `BareArray` and `BareString` are fifteen
> lines of guard between them and `GuidelineTest` carries `#[CoversClass]` for exactly those two,
> because it constructs both — which is the honest difference between it and `NoDiscardTest`, the
> other test that reads the codebase rather than running it.

### 2026-09-08 — the security pass over `/update`, and the hidden twenty-seventh line (`91b0e2e`)

> **So is the security pass over
> `/update`**: it added three guards — `Config::webroot()`'s absolute-`DOCUMENT_ROOT` refusal and the
> `is_link` check the mirror's `walk()` and `sweep()` now make before descending — and closed all
> three in the same commit, the last by a symlink a test plants because an archive is forbidden to
> carry one; bounding `File::read()` to the caller's cap, that pass's main fix, added no branch to
> close. It also made the twenty-six *true* rather than asserted — the count had quietly been
> twenty-seven since `/update` landed, the extra a private `TarArchive` constructor guarding a
> stateless reader against instances, which is ceremony no other static class here carries and which
> `new` would find harmless, so it was deleted the way an unreachable branch is rather than covered by
> a reflection call that would prove nothing.

### 2026-09-09 — the reflected-realm pass: the trap fires a second time (`e1f08a5`)

> **So is the reflected-realm pass**, which added three
> guards — `BasicChallenge`'s `qdtext` check and `UpdateApplier::check()`'s two refusals of a regular
> file named as, or in place of, a directory — and closed all three; `Request::unparsedPath()` is a
> statement rather than a branch. That pass is also where **the `#[CoversClass]` trap fired a second
> time**, and it is worth knowing it fires for an *existing* file too, not only a new class:
> `SecurityPolicyTest` names ten classes, `BasicChallenge` was not among them, and eight data rows
> drove the new guard while it read as 0%. The count went 26 → 31 → 26 in one sitting, and the middle
> number is the only reason anybody looked.

### 2026-09-09 — the markup-parser work (`17cca79`)

> **So is the markup-parser work**, which is the largest of
> them by some way — `MarkupParser` is 70 lines and every one of them is covered, because a class made
> of refusals is a class where each refusal is a row in a data provider. It moved the denominator from
> 2110 to 2179 and left the numerator's gap exactly where it was. The twenty-six are a property of what
> is *deliberately* untested, not a budget that grows with the code.

### 2026-09-09 — the three-guidelines work, and the figures that had drifted (`bd07a6a`)

> **So is the three-guidelines work**, which moved the denominator again — 2179 to 2230 — and is worth
> reading for the one line it *nearly* added. `Diagnostics::watched()`'s handler had an early
> `return false` for a severity it does not claim, and that branch cannot be reached from a test: the
> only non-fatal severities PHP lets userland raise are all on the muted list, and returning false for
> an `E_USER_ERROR` hands it to the standard handler, which ends the process. So the choice was a line
> excused as unreachable or a shape with no unreachable line, and the second turned out to be the
> better code as well: the decision is `Diagnostics::handles()` now, named once and called by both
> members, which is what stops the two handlers ever disagreeing about what "handled" means. A branch
> a test cannot reach is usually saying something about the code rather than about the test.

The same pass corrected testing.md's own figures:

> **The figures above had drifted and were corrected in the three-guidelines pass**, which is worth a
> sentence because it is this document's own subject. Two of them disagreed with CLAUDE.md *and with
> each other* — 97.84% in the code block, 98.75% and "sixteen lines" further down — while the clover
> XML said neither. Nobody had written anything false; each number had been true when it was typed,
> and a figure quoted in three places is a figure that only stays right by accident.

testing.md then read 98.83% (2204/2230) in two places, which the health work's 98.92% (2391/2417) in
CLAUDE.md overtook a day later — the same drift again. The figure is now stated in testing.md alone.

### 2026-09-09 — the API work: the trap fires a third time (`5baabd4`)

> **So is the API work**, which moved the denominator 2230 → 2308 and added none of its own — but it
> is worth reading for *how* it nearly did, because the `#[CoversClass]` trap fired for the third
> time and in its subtlest form yet. Neither `UpdatePatch::isWrite()` nor `Allow::of()` was untested:
> both are called on every controller test in `ApiTest`, which named neither. A test class declaring
> any `#[CoversClass]` records coverage for *only* those classes, so both read as 0% while running
> constantly, and the count said 28. The two lines are there in `ApiTest`'s attribute list now with a
> sentence saying why a file names classes that are not its subject. Twice this trap has been a new
> class nobody added; once it was an existing class a test forgot to name; this time it was a method
> whose *only* caller lives in a file that covers something else.

### 2026-09-10 — the health work, and `max_execution_time` (`8f766f0`)

> **So is the health work**, which moved the denominator 2308 → 2417 — the largest single jump since
> the markup parser — and added none of its own. Worth reading for *how* it managed it, because two
> of the three ways it could have failed were decided in the code rather than in the test:
>
> - **A guard for a case that cannot happen was not written.** `ini_get()` answers `string|false`, and
>   for every directive `PhpSetting` names the `false` cannot occur — the names are real, which is
>   what the enum is for — so `configured()` is a **cast** rather than a branch. A guard there would
>   have been a line no test could reach, which is the thing the three-guidelines work already
>   established is usually saying something about the code.
> - **A verdict that needed an unreachable arm became two columns instead.** A data file's line was
>   going to be `present` / `MISSING` / `absent`, and `MISSING` — tracked but not there — is a state
>   no real checkout is in, so its arm would have sat uncovered forever. Reporting presence and
>   tracking side by side removed the arm *and* produced the better report: `absent  (tracked)` reads
>   as the fault it is without the class inventing a severity word.
> - **The rest is `?:` on one line rather than `if` on four**, which is the difference between a
>   branch and a statement under line coverage — the same shape `UpdateVersion`'s `?: '-'` already
>   had. It is not a trick: both arms of every one of them are exercised, and the one place it would
>   have been a trick — `error_get_last()`, whose null branch is hard to force under PHPUnit's own
>   handler — is a genuine two-liner where both lines run whichever way it goes.
>
> The first run of the report found a bug none of that would have caught, and it is the one to
> remember: `max_execution_time` is **`'0'`** on a runtime with no limit, `'0'` is falsy, and `?:`
> printed the most interesting answer that directive has as "nothing to say". `HealthFact` asks
> `=== ''` now, and `HealthTest` has the row.

## What the checks found on their first run

### 2026-09-05 — the admin credential comparison had never run (`156de9d`)

From testing.md:

> **A wrong admin password is refused.** This one had never run. `data/admin.php` ships with an empty
> `pass_hash`, so `Auth::accepts()` short-circuits on its first operand and neither `hash_equals()`
> nor `password_verify()` is reached — which means the verify script's two `/admin/stats → 401`
> checks prove the route is gated without ever comparing a credential.

And from CLAUDE.md:

> That split is why `AdminTest` exists — before it, the credential comparison
> had never executed under either suite, because the placeholder `data/admin.php` short-circuits it.

### 2026-09-06 — the 100% gate refuses `?? 0` in `DemoWaveform` (`aa68acb`)

> It also has teeth the other way: `?? 0` on a typed-array index inside `DemoWaveform` was a branch
> nothing could reach, and the gate refusing it is what turned that code into a `charCodeAt` that
> needs no fallback at all.

### 2026-09-06 — a negative event size sailed through `FlpFile` (`15397cd`)

> an event that overruns the chunk, a length prefix that runs past it, and
> one long enough to overflow into a *negative* size — which used to sail through the overrun
> check, because a negative size is always within bounds.

### 2026-09-09 — `dom.mjs` was being run as a suite (`7e7696b`)

> **Both test commands name their files.** `node --test` with no argument matches
> `**/test/**/*.?(c|m)js` among its default patterns, which is *everything* under `test/` — so
> `test/js/dom.mjs`, the jsdom helper the ten suites import, was being executed as a suite of its own
> with no tests in it. Harmless and misleading in equal measure: it cost 730 ms and made the run
> report eleven files where there are ten.

### 2026-09-09 — `tsc` over the tools: two faults in `build-prod.mjs` (`7e7696b`)

> Turning the checker on found two of those in the first
> run, both in `build-prod.mjs`: `graph.outputFiles[0]` was read with nothing saying it was there,
> and the minifier's failure message quoted `result.error`, **a property terser removed in version 5**
> — so for as long as that line had existed it had been printing the fallback half of a `??`. The
> real failure was never silent (terser rejects, and an unhandled rejection exits non-zero), but the
> sentence written to explain it could not have been right.

### 2026-09-09 — `tsc` over the tests: `card()` checked nothing (`7e7696b`)

> What is left is drift between a helper and its callers, which
> is what the first run found: `card()` in the waveform suite takes a destructured parameter with a
> `= {}` default, a shape that drops every property without a default of its own, so `peaks` was not
> in the inferred type and **twenty-six call sites passing it were checking nothing**. Same kind of
> gap as `--test-coverage-include-all` closes, one layer down — not a wrong answer, an unasked
> question.

## Smaller

### 2026-09-04 — the last phpcs warning (`ee0a7dd`)

> `phpcs` reports no warnings either, as of the markup tree — the last one was a 193-character line in
> `Layout.php`, HTML inside a heredoc that couldn't wrap without changing the output. There is no
> heredoc left to be long.
