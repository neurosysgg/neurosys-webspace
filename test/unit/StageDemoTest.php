<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Support\Directory;
use NeuroSYS\Support\PasswordHash;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\StageDemo;
use NeuroSYS\Tool\Demo\DemoEntryWriter;
use NeuroSYS\Tool\Demo\DemoPreflight;
use NeuroSYS\Tool\Demo\DemoSource;
use NeuroSYS\Tool\Demo\DemoStage;
use NeuroSYS\Tool\Demo\Encoding;
use NeuroSYS\Tool\Demo\Password;
use NeuroSYS\Tool\Release\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `tools/stage-demo` — what turns a folder of bounces into a page behind a password.
 *
 * No `#[CoversClass]`, like every other test over `tools/`: that namespace is outside
 * `phpunit.xml.dist`'s coverage source, because `deploy.sh` uploads `src/` and the tooling is not
 * part of the site.
 *
 * The command is the one place in this repository that **mints a credential**, so the tests that
 * matter most here are not about the entry it prints. They are: that the password is random and
 * verifiable, that the plaintext is never written anywhere, that `--check` produces no password at
 * all, and that a file which cannot be played is refused before anything is staged — because a demo
 * is sent once, to somebody, with a password attached, and a page of dead players is not a thing you
 * get to retract.
 */
final class StageDemoTest extends TestCase
{
    private Directory $fixtures;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixtures = Directory::temporary('neurosys-stage-demo-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->fixtures->remove();
    }

    /**
     * Runs the command against in-memory streams and hands back what it wrote to each.
     *
     * @param list<string> $arguments
     * @return array{ExitCode, string, string} status, stdout, stderr
     */
    private static function invoke(array $arguments): array
    {
        $out   = fopen('php://memory', 'r+b');
        $error = fopen('php://memory', 'r+b');

        $status = Runner::execute(new StageDemo(), $arguments, new Output($out, $error));

        rewind($out);
        rewind($error);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }

    // ───────────────────────────── the password ─────────────────────────────

    /**
     * @return void
     */
    public function testAMintedPasswordVerifiesAgainstItsOwnHashAndNothingElseDoes(): void
    {
        $password = Password::mint();

        self::assertTrue($password->hash->matches($password->plaintext));
        self::assertFalse($password->hash->matches(strtolower($password->plaintext)));
        self::assertFalse($password->hash->matches(''));
        self::assertInstanceOf(PasswordHash::class, $password->hash);
    }

    /**
     * The shape, which is a legibility decision rather than a strength one: it gets read off a
     * screen and typed into a browser prompt, and sometimes read out loud.
     *
     * @return void
     */
    public function testAPasswordIsFourGroupsOfFiveUnambiguousCharacters(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $plaintext = self::draw();

            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{5}(-[0-9A-HJKMNP-TV-Z]{5}){3}\z/', $plaintext);

            // Crockford's four: the pairs that produce a password that "does not work".
            self::assertStringNotContainsString('I', $plaintext);
            self::assertStringNotContainsString('L', $plaintext);
            self::assertStringNotContainsString('O', $plaintext);
            self::assertStringNotContainsString('U', $plaintext);
        }
    }

    /**
     * It is the whole access control on unreleased music, so the generator has to be the
     * cryptographic one. This cannot prove that; it can prove the obvious failure, which is a
     * constant.
     *
     * @return void
     */
    public function testTwoMintedPasswordsAreNotTheSame(): void
    {
        $minted = [];

        for ($i = 0; $i < 200; $i++) {
            $minted[] = self::draw();
        }

        self::assertCount(200, array_unique($minted));
    }

    /**
     * One draw of the characters, without the bcrypt that {@link Password::mint()} pays afterwards.
     *
     * Reflection because the generator is private and should be: what the class offers is a minted
     * password, and a public way to get characters without a hash is an invitation to store one.
     * Testing the two apart is what makes a two-hundred-draw loop cost nothing — see the method.
     *
     * @return string
     */
    private static function draw(): string
    {
        return (string) new ReflectionMethod(Password::class, 'plaintext')->invoke(null);
    }

    // ───────────────────────────── labels ─────────────────────────────

    /**
     * The rule was written against the file names actually in `~/Music/neuro.SYS/demos/`, which is
     * why the awkward ones are here: an artist name beginning with V, a mastering export with no
     * `RC`, and a bounce with no version at all.
     *
     * @param string $name
     * @param string $expected
     * @return void
     */
    #[DataProvider('labelProvider')]
    public function testALabelIsDerivedFromTheVersionMarkerInAFileName(string $name, string $expected): void
    {
        self::assertSame([$expected], DemoSource::labelsFor([$name]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'a trailing version'  => ['take me away v3.flac', 'v3'];
        yield 'after a dash'        => ['Virtual Riot - Were Not Alone [neuro.SYS Bootleg] - v4.flac', 'v4'];
        yield 'underscored'         => ['V_RIOT_178_NIGHTS_ON_FIRE_LIQUID_DNB_v12.wav', 'v12'];
        yield 'a release candidate' => ['V_RIOT_178_NIGHTS_ON_FIRE_LIQUID_DNB_v12_RC.wav', 'v12-rc'];
        yield 'the last one wins'   => ['V_RIOT_178_v2_NIGHTS_v17_MASTERING_EXPORT.wav', 'v17'];
        yield 'upper case'          => ['Chaos (CrystalSine x Neuro SYS) neuroSYS_V1.flac', 'v1'];
        yield 'leading zero'        => ['bounce v03.flac', 'v3'];
        yield 'no version at all'   => ['neuro.SYS - BASSTI BATTLE SEISMIC CHARGE.mp3', 'mix'];

        // `Riot` and `VIP` must not read as versions. The pattern needs a digit after the v.
        yield 'not a version'       => ['Virtual Riot VIP.flac', 'mix'];
    }

    /**
     * A repeat is not cosmetic: the staged file is named for the label, so the second would
     * overwrite the first and the page would list one mix twice. `Demo` refuses it outright; this
     * is what stops one being written in the first place.
     *
     * @return void
     */
    public function testTwoFilesThatDeriveTheSameLabelAreDisambiguated(): void
    {
        self::assertSame(
            ['v3', 'v3-2', 'v3-3', 'mix', 'mix-2'],
            DemoSource::labelsFor([
                'alien house v3.flac',
                'alien house v3.wav',
                'other/alien house v3.flac',
                'bounce.flac',
                'another bounce.flac',
            ]),
        );
    }

    /**
     * Every derived label has to be one `DemoTrack` will accept, or the entry the tool prints does
     * not load. The two rules are written in different files, in different namespaces.
     *
     * @return void
     */
    public function testEveryDerivedLabelIsOneTheModelAccepts(): void
    {
        $labels = DemoSource::labelsFor([
            'take me away v3.flac',
            'V_RIOT_178_v12_RC.wav',
            'neuro.SYS - BASSTI BATTLE.mp3',
            'bounce.flac',
        ]);

        foreach ($labels as $label) {
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,31}\z/', $label);
        }
    }

    // ───────────────────────────── title and slug ─────────────────────────────

    /**
     * The ladder is two rungs and stops. The FLAC tag is right when it is there and is **empty on
     * at least one master in that folder**, so the file name is the fallback — with the version
     * taken back off, since `alien house v3` is a file and `alien house` is a track.
     *
     * @return void
     */
    public function testATitleFallsBackToTheFileNameWithoutItsVersion(): void
    {
        // No such file, so there are no tags to read: this is the fallback rung on its own.
        self::assertSame('alien house', DemoStage::of(['/nowhere/alien house v3.flac'])->title);
        self::assertSame('take me away', DemoStage::of(['/nowhere/take me away v12_RC.flac'])->title);
        self::assertSame('bounce', DemoStage::of(['/nowhere/bounce.flac'])->title);
    }

    /**
     * @return void
     */
    public function testTheSlugFollowsTheTitleAndBothCanBeOverridden(): void
    {
        self::assertSame('alien-house', DemoStage::of(['/nowhere/alien house v3.flac'])->slug);

        $stage = DemoStage::of(['/nowhere/x v1.flac'], title: 'Virtual Riot — We\'re Not Alone', slug: 'wna');

        self::assertSame('Virtual Riot — We\'re Not Alone', $stage->title);
        self::assertSame('wna', $stage->slug);
    }

    // ───────────────────────────── encoding ─────────────────────────────

    /**
     * Re-encoding an already-lossy file is a generation loss for nothing, so it is remuxed instead
     * — same audio, fresh container, and the metadata dropped either way.
     *
     * @param string   $name
     * @param Encoding $expected
     * @param string   $extension
     * @return void
     */
    #[DataProvider('encodingProvider')]
    public function testALosslessMasterIsEncodedAndALossyOneIsRemuxed(
        string $name,
        Encoding $expected,
        string $extension,
    ): void {
        $file = $this->fixtures->file($name);
        $file->write('x');

        self::assertSame($expected, Encoding::forSource($file));
        self::assertSame($extension, Encoding::forSource($file)->extensionFor($file));
    }

    /**
     * @return iterable<string, array{string, Encoding, string}>
     */
    public static function encodingProvider(): iterable
    {
        yield 'a FLAC master'   => ['a.flac', Encoding::Mp3, 'mp3'];
        yield 'a WAV master'    => ['a.wav', Encoding::Mp3, 'mp3'];
        yield 'an MP3 bounce'   => ['a.mp3', Encoding::Remux, 'mp3'];
        yield 'an m4a'          => ['a.m4a', Encoding::Remux, 'm4a'];
        yield 'an ogg'          => ['a.ogg', Encoding::Remux, 'ogg'];

        // Not listed: encoded, which is the safe way round — an unknown extension is likelier to be
        // a big uncompressed export than a small lossy one.
        yield 'something else'  => ['a.aiff', Encoding::Mp3, 'mp3'];
    }

    /**
     * A remux has to keep its container: MP3 audio does not go into an Ogg.
     *
     * @return void
     */
    public function testTheCodecArgumentsSayEncodeOrCopy(): void
    {
        self::assertContains('libmp3lame', Encoding::Mp3->arguments());
        self::assertSame(['-codec:a', 'copy'], Encoding::Remux->arguments());
    }

    // ───────────────────────────── preflight ─────────────────────────────

    /**
     * `~/Music/neuro.SYS/demos/alien house.flac` is zero bytes. ffprobe reads nothing out of it and
     * ffmpeg writes nothing out of it, so without this check the demo stages "successfully" and the
     * page carries a player that will not start.
     *
     * @return void
     */
    public function testAnEmptyFileIsRefusedBeforeAnythingIsStaged(): void
    {
        $file = $this->fixtures->file('stage-demo-fixture v3.flac');
        $file->write('');

        self::assertSame(
            [Level::Ok, Level::Fail],
            array_map(
                static fn($finding) => $finding->level,
                DemoPreflight::check(DemoStage::of([$file->path])),
            ),
        );
    }

    /**
     * @return void
     */
    public function testAFileThatIsNotThereIsRefused(): void
    {
        $findings = DemoPreflight::check(DemoStage::of(['/nowhere/alien house v3.flac']));

        self::assertContains(Level::Fail, array_map(static fn($f) => $f->level, $findings));
    }

    /**
     * @return void
     */
    public function testNamingNoFilesIsRefused(): void
    {
        $findings = DemoPreflight::check(DemoStage::of([]));

        self::assertCount(1, $findings);
        self::assertSame(Level::Fail, $findings[0]->level);
    }

    // ───────────────────────────── the command ─────────────────────────────

    /**
     * @return void
     */
    public function testNoArgumentsIsAUsageError(): void
    {
        [$status, $out, $error] = self::invoke([]);

        self::assertSame(ExitCode::Usage, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('usage: php tools/stage-demo.php', $error);
    }

    /**
     * The parser refuses a flag the command never declared, which is what both hand-rolled parsers
     * this layer replaced did silently.
     *
     * @return void
     */
    public function testAFlagTheCommandDoesNotDeclareIsRefused(): void
    {
        [$status, , $error] = self::invoke(['a.flac', '--public']);

        self::assertSame(ExitCode::Usage, $status);
        self::assertStringContainsString("unknown option '--public'", $error);
    }

    /**
     * **`--check` must not mint a password.** A run made only to read the report would otherwise
     * leave one on the screen that no entry in `data/demos.php` matches — a password that looks
     * real, is real, and opens nothing.
     *
     * @return void
     */
    public function testCheckPrintsAReportAndNeitherAPasswordNorAnEntry(): void
    {
        $file = $this->fixtures->file('stage-demo-fixture v3.flac');
        $file->write('not really audio');

        [, $out, $error] = self::invoke([$file->path, '--check']);

        self::assertSame('', $out);
        self::assertStringNotContainsString('password:', $error);
        self::assertStringNotContainsString('send these', $error);
    }

    /**
     * A file ffprobe cannot read never reaches the staging step, so nothing is written and no
     * password is minted.
     *
     * @return void
     */
    public function testAnUnreadableFileFailsWithoutStagingOrMintingAnything(): void
    {
        $file = $this->fixtures->file('stage-demo-fixture v3.flac');
        $file->write('not really audio');

        [$status, $out, $error] = self::invoke([$file->path]);

        self::assertSame(ExitCode::Failure, $status);
        self::assertSame('', $out);
        self::assertStringNotContainsString('send these', $error);
        self::assertStringContainsString('ffprobe cannot read this as audio', $error);
    }

    /**
     * `--rotate` on its own changes one line of an entry that already exists, so it takes no files
     * and prints no entry — restaging to change a password would rewrite every file and lose any
     * description written by hand since.
     *
     * @return void
     */
    public function testRotatePrintsOneArgumentAndTheNewPassword(): void
    {
        [$status, $out, $error] = self::invoke(['--rotate']);

        self::assertSame(ExitCode::Success, $status);
        self::assertStringContainsString('password:    new PasswordHash(', $out);
        self::assertStringNotContainsString('new Demo(', $out);
        self::assertStringContainsString('send these', $error);
    }

    /**
     * **The password goes to stderr, with the report.** The entry goes to stdout so `> entry.php`
     * works; a plaintext following it into that file would be the one place this whole arrangement
     * writes one down.
     *
     * @return void
     */
    public function testThePlaintextNeverGoesToTheStreamThatCanBeRedirectedToAFile(): void
    {
        [, $out, $error] = self::invoke(['--rotate']);

        preg_match('/password:\s+([0-9A-Z-]{23})\s/', $error, $match);

        self::assertNotEmpty($match, 'the report should show a password to send');
        self::assertStringNotContainsString($match[1], $out);

        // And what does go to stdout is the hash, which is the half that is meant to be written down.
        self::assertMatchesRegularExpression('/\$2y\$\d+\$/', $out);
    }

    // ───────────────────────────── the entry ─────────────────────────────

    /**
     * The entry has to parse against a file that imports short names, which is how every entry
     * beside it is written — so the imports are printed rather than assumed. `data/demos.php` is
     * gitignored, so unlike `data/releases.php` there is often no file to reconcile against.
     *
     * @return void
     */
    public function testTheEntryNamesEveryClassItUses(): void
    {
        $stage = DemoStage::of(['/nowhere/alien house v3.flac']);

        self::assertSame(
            [
                'NeuroSYS\Model\Demo',
                'NeuroSYS\Model\DemoTrack',
                'NeuroSYS\Support\Collection',
                'NeuroSYS\Support\PasswordHash',
            ],
            DemoEntryWriter::imports($stage, Password::mint()),
        );
    }

    /**
     * The entry is PHP this repository will later `require`, so the real test is that it parses and
     * produces the `Demo` it describes — not that it looks a certain way.
     *
     * @return void
     */
    public function testTheEntryEvaluatesToTheDemoItDescribes(): void
    {
        $stage    = DemoStage::of(['/nowhere/alien house v3.flac'], slug: 'alien-house');
        $password = Password::mint();

        $file = $this->fixtures->file('demos.php');
        $file->write(sprintf(
            "<?php\ndeclare(strict_types=1);\n%s\nreturn [\n%s\n];\n",
            implode('', array_map(
                static fn(string $class): string => "use $class;\n",
                DemoEntryWriter::imports($stage, $password),
            )),
            DemoEntryWriter::write($stage, $password),
        ));

        /** @var array<string, \NeuroSYS\Model\Demo> $demos */
        $demos = require $file->path;

        self::assertArrayHasKey('alien-house', $demos);
        self::assertSame('alien house', $demos['alien-house']->title);
        self::assertTrue($demos['alien-house']->password->matches($password->plaintext));

        // The file is named for the label, so nothing of the master's own name reaches the server.
        self::assertSame('v3', $demos['alien-house']->findTrack('v3')?->label);
        self::assertSame('v3.mp3', $demos['alien-house']->findTrack('v3')?->file);
    }

    /**
     * The description is the one field nothing can derive, written out as the null it is with a
     * comment rather than left off — a forgotten one renders as "work in progress" on the page.
     *
     * @return void
     */
    public function testTheEntryLeavesTheOneEditorialFieldWrittenOutAndEmpty(): void
    {
        $entry = DemoEntryWriter::write(DemoStage::of(['/nowhere/a v1.flac']), Password::mint());

        self::assertStringContainsString('description: null,', $entry);
        self::assertStringContainsString('what you are asking them for', $entry);
    }
}
