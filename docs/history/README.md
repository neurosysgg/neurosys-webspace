# History

How the code got the way it is. The documents one level up describe what is true **now** and why;
this directory keeps the stories they used to carry — the bug that was found, the argument that was
reversed, the count that moved, the reading that changed the next day.

A rule that exists because of one of these stories is still stated in the current document, as one
present-tense sentence with a link back here. Nothing here is required reading to change the code
safely; if it ever becomes so, the rule it carries belongs in a current document instead.

`docs/reviews/` is a different thing — the owner's own review log — and stays where it is.

## The format

One file per topic. Inside it, a `##` heading per subject and a `###` entry per event, oldest first,
headed with a date and, where one is cheap to find, the commit:

```markdown
## Coverage

### 2026-09-08 — the `/update` work did not close its own lines (`791fbc4`)

The text as it stood in the current document, moved rather than rewritten.
```

Moved text keeps its wording. Where a passage only makes sense next to the paragraph it was cut from,
a sentence of context is added in front of it rather than the passage being rephrased.

## Topics

| File | Covers |
|---|---|
| [coverage.md](coverage.md) | the coverage count and every pass that moved it; the `#[CoversClass]` trap each time it fired; what the type checkers found on their first run |
| [security.md](security.md) | the dated assessments and their fixed findings; the request-parsing and header faults; the CSP allowances that were removed |
| [api.md](api.md) | `/update` becoming `/api`; the credential moving into `Authorization`; the serial, the mirror, the webroot that emptied this repository |
| [hosting.md](hosting.md) | readings of the live host that changed, extension probes, the dev router |
| [frontend.md](frontend.md) | the bundling reversal, the mangled class name, cache versioning's first attempt, the build tools' argv |
| [performance.md](performance.md) | before-and-after figures of past changes |
| [types.md](types.md) | collections, exceptions, `Config`, `SitePath`, `File`, and the guidelines' first run |
| [markup.md](markup.md) | the markup tree: attributes, the scheme check, `RawHtml` becoming `MarkupParser` |
| [tooling.md](tooling.md) | the release tooling built phase by phase, and the folders it found wrong |
| [releases.md](releases.md) | the checklists of releases that have shipped |
