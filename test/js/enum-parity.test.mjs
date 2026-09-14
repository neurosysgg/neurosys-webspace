/**
 * The mirrored enums against their PHP originals.
 *
 * assets/ts/model/ is a second copy of facts that live in src/NeuroSYS/Model/, which is exactly the
 * kind of duplication the rest of this codebase refuses to leave unguarded. These compare the two
 * case by case — name, backing value and the accessors the client mirrors — so a case added, removed,
 * renamed or re-valued on one side fails here rather than in a browser.
 *
 * Order is compared, not just membership: SoundCloudEmbed and SoundCloudPlayer both build the widget
 * query string by iterating the cases, so the declaration order is the rendered order.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

import { Platform, displayName } from '../../public/assets/js/model/Platform.js';
import { SoundCloudOption } from '../../public/assets/js/model/SoundCloudOption.js';
import { SoundCloudPlayerStyle, isVisual } from '../../public/assets/js/model/SoundCloudPlayerStyle.js';
import { TerminalTone } from '../../public/assets/js/model/TerminalTone.js';
import { Tag } from '../../public/assets/js/model/Tag.js';
import { SoundCloudPlayerAttribute } from '../../public/assets/js/model/SoundCloudPlayerAttribute.js';
import { EmbedAttribute } from '../../public/assets/js/model/EmbedAttribute.js';
import { TerminalAttribute } from '../../public/assets/js/model/TerminalAttribute.js';
import { CoverArtAttribute } from '../../public/assets/js/model/CoverArtAttribute.js';
import { LinkAttribute } from '../../public/assets/js/phpanta/model/LinkAttribute.js';
import { LinkRel } from '../../public/assets/js/phpanta/model/LinkRel.js';
import { RegionAttribute } from '../../public/assets/js/phpanta/model/RegionAttribute.js';
import { PasskeyAttribute } from '../../public/assets/js/phpanta/model/PasskeyAttribute.js';
import { PasskeyFormField } from '../../public/assets/js/phpanta/model/PasskeyFormField.js';
import { CeremonyType } from '../../public/assets/js/phpanta/model/CeremonyType.js';
import { HtmlTag } from '../../public/assets/js/phpanta/model/HtmlTag.js';
import { HtmlAttribute } from '../../public/assets/js/phpanta/model/HtmlAttribute.js';
import { CssClass } from '../../public/assets/js/model/CssClass.js';
import { SectionKind } from '../../public/assets/js/model/SectionKind.js';
import { ArrangementAttribute } from '../../public/assets/js/model/ArrangementAttribute.js';
import { ElementId } from '../../public/assets/js/phpanta/model/ElementId.js';
import { Language } from '../../public/assets/js/phpanta/model/Language.js';
import { RequestHeader } from '../../public/assets/js/phpanta/model/RequestHeader.js';
import { RequestedWith } from '../../public/assets/js/phpanta/model/RequestedWith.js';
import { ResponseHeader } from '../../public/assets/js/phpanta/model/ResponseHeader.js';
import { MediaType } from '../../public/assets/js/phpanta/model/MediaType.js';
import { TerminalFieldKey } from '../../public/assets/js/model/TerminalFieldKey.js';
import { WaveformAttribute } from '../../public/assets/js/model/WaveformAttribute.js';
import { WaveformBand, STRIDE } from '../../public/assets/js/model/WaveformBand.js';
import { Config } from '../../public/assets/js/Config.js';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** Runs a PHP snippet against the real autoloader and parses what it echoes as JSON. */
function php(code) {
  return JSON.parse(execFileSync(
    'php',
    ['-r', `require '${ROOT}/autoload.php'; ${code}`],
    { encoding: 'utf8' },
  ));
}

/** A TS string enum compiles to a plain object, so its entries are its cases in declaration order. */
const cases = (mirror) => Object.entries(mirror);

test('Platform mirrors NeuroSYS\\Model\\Platform, including displayName()', () => {
  assert.deepEqual(
    cases(Platform).map(([name, value]) => [name, value, displayName(value)]),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value, $c->displayName()],
        NeuroSYS\\Model\\Platform::cases(),
    ));`),
  );
});

test('SoundCloudOption mirrors its PHP enum, in the order the query string is built', () => {
  assert.deepEqual(
    cases(SoundCloudOption),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value],
        NeuroSYS\\Model\\Embed\\SoundCloudOption::cases(),
    ));`),
  );
});

test('SoundCloudPlayerStyle mirrors its PHP enum, including isVisual()', () => {
  assert.deepEqual(
    cases(SoundCloudPlayerStyle).map(([name, value]) => [name, value, isVisual(value)]),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value, $c->isVisual()],
        NeuroSYS\\Model\\Embed\\SoundCloudPlayerStyle::cases(),
    ));`),
  );
});

test('TerminalTone mirrors NeuroSYS\\View\\Terminal\\TerminalTone', () => {
  assert.deepEqual(
    cases(TerminalTone),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value],
        NeuroSYS\\View\\Terminal\\TerminalTone::cases(),
    ));`),
  );
});

/**
 * The tag and attribute names, which are the same fact stated in two languages.
 *
 * These matter differently from the value enums above. A wrong *value* usually shows up — a broken
 * widget URL, a tone that does not colour. A wrong *name* shows up as nothing at all: getAttribute
 * returns null and the element carries on with its fallback, or the browser meets a tag it has
 * never heard of and renders an inert inline box. Neither reaches a console.
 *
 * Not covered here, and worth knowing: `tone` and `--player-height` are written by an element and
 * read only by the stylesheet, so they have no PHP side and no test can follow them. See
 * TerminalFieldAttribute and CustomProperty. `loaded` is written only by the client too, but
 * EmbedAttribute gives it a PHP case, so it is compared like any other.
 */
const MIRRORED_NAMES = [
  ['Tag', Tag, 'NeuroSYS\\View\\Html\\Tag'],
  ['SoundCloudPlayerAttribute', SoundCloudPlayerAttribute, 'NeuroSYS\\Model\\Embed\\SoundCloudPlayerAttribute'],
  ['EmbedAttribute', EmbedAttribute, 'NeuroSYS\\Model\\Embed\\EmbedAttribute'],
  ['TerminalAttribute', TerminalAttribute, 'NeuroSYS\\View\\Terminal\\TerminalAttribute'],
  ['CoverArtAttribute', CoverArtAttribute, 'NeuroSYS\\View\\Html\\CoverArtAttribute'],
  ['LinkAttribute', LinkAttribute, 'Phpanta\\View\\Html\\LinkAttribute'],
  ['LinkRel', LinkRel, 'Phpanta\\View\\Html\\LinkRel'],
  ['RegionAttribute', RegionAttribute, 'Phpanta\\View\\Html\\RegionAttribute'],
  ['PasskeyAttribute', PasskeyAttribute, 'Phpanta\\View\\Html\\PasskeyAttribute'],
  ['PasskeyFormField', PasskeyFormField, 'Phpanta\\Http\\PasskeyFormField'],
  ['CeremonyType', CeremonyType, 'Phpanta\\Model\\Passkey\\CeremonyType'],
  ['HtmlTag', HtmlTag, 'Phpanta\\View\\Html\\HtmlTag'],
  ['HtmlAttribute', HtmlAttribute, 'Phpanta\\View\\Html\\HtmlAttribute'],
  ['CssClass', CssClass, 'NeuroSYS\\View\\Html\\CssClass'],
  ['ElementId', ElementId, 'Phpanta\\View\\Html\\ElementId'],
  ['RequestHeader', RequestHeader, 'Phpanta\\Http\\RequestHeader'],
  ['RequestedWith', RequestedWith, 'Phpanta\\Http\\RequestedWith'],
  ['TerminalFieldKey', TerminalFieldKey, 'NeuroSYS\\View\\Terminal\\TerminalFieldKey'],
  ['SectionKind', SectionKind, 'NeuroSYS\\Model\\Production\\SectionKind'],
  ['ArrangementAttribute', ArrangementAttribute, 'NeuroSYS\\View\\Html\\ArrangementAttribute'],
  ['WaveformAttribute', WaveformAttribute, 'NeuroSYS\\View\\Html\\WaveformAttribute'],
  ['Language', Language, 'Phpanta\\Text\\Language'],
];

for (const [name, mirror, phpEnum] of MIRRORED_NAMES) {
  test(`${name} mirrors ${phpEnum.replaceAll('\\\\', '\\')}`, () => {
    assert.deepEqual(
      cases(mirror),
      php(`echo json_encode(array_map(fn ($c) => [$c->name, $c->value], ${phpEnum}::cases()));`),
    );
  });
}

/**
 * WaveformBand, which is the one numeric mirror and so cannot use `cases()` above.
 *
 * A TypeScript numeric enum compiles to an object carrying the reverse mapping as well — `{0:
 * 'Level', Level: 0, …}` — so Object.entries answers twice as many pairs as there are cases. The
 * forward half is the half whose values are numbers.
 *
 * It matters more than most of the list above, because these values are byte offsets rather than
 * names. A name that drifts makes an element fall back to nothing; an offset that drifts makes the
 * waveform draw its lows in the colour of its highs, which is a picture that looks deliberate.
 */
test('WaveformBand mirrors NeuroSYS\\Model\\WaveformBand, offsets included', () => {
  const forward = Object.entries(WaveformBand).filter(([, value]) => typeof value === 'number');

  assert.deepEqual(
    forward,
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value],
        NeuroSYS\\Model\\WaveformBand::cases(),
    ));`),
  );

  assert.equal(STRIDE, php('echo json_encode(NeuroSYS\\Model\\WaveformBand::stride());'));
});

/**
 * Config, which is not an enum but is the same problem: a fact stated on both sides.
 *
 * PLAYER_HOST is the one with teeth — it is also the CSP's whole frame-src, so a drift here means
 * the player is blocked by our own policy, in the console, with nothing in the page to say why.
 */
test('Config mirrors the part of NeuroSYS\\Site the client reads', () => {
  assert.deepEqual(
    {
      NAME: Config.NAME,
      HANDLE: Config.HANDLE,
      PLAYER_HOST: Config.PLAYER_HOST,
      LANGUAGES: [...Config.LANGUAGES],
    },
    php(`echo json_encode([
        'NAME'        => NeuroSYS\\Site::NAME,
        'HANDLE'      => NeuroSYS\\Site::HANDLE,
        'PLAYER_HOST' => NeuroSYS\\Site::PLAYER_HOST,
        'LANGUAGES'   => array_map(
            fn ($l) => $l->value,
            NeuroSYS\\Site::current()->languages()->offered()->toValues(),
        ),
    ]);`),
  );
});

/**
 * ResponseHeader, mirrored in part: the client reads one response header, and carrying the other
 * thirteen would be a list nothing uses. So the direction is one way — every case the mirror has is
 * a PHP case of the same name and value — and a PHP case the client does not read is not a drift.
 */
test('ResponseHeader mirrors the part of Phpanta\\Http\\ResponseHeader the client reads', () => {
  const php_cases = new Map(php(`echo json_encode(array_map(
      fn ($c) => [$c->name, $c->value],
      Phpanta\\Http\\ResponseHeader::cases(),
  ));`));

  assert.deepEqual(
    cases(ResponseHeader),
    Object.keys(ResponseHeader).map((name) => [name, php_cases.get(name)]),
  );
});

/**
 * MediaType, which has no PHP enum to mirror: MimeType is a class, because it carries a charset.
 * What Navigation compares a response with is the essence of the type ViewResponse sends it.
 */
test('MediaType mirrors the essence of Phpanta\\Http\\MimeType::html()', () => {
  assert.deepEqual(
    cases(MediaType),
    [['Html', php('echo json_encode(Phpanta\\Http\\MimeType::html()->essence());')]],
  );
});
