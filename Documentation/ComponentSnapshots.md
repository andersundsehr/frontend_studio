# Component Snapshots: dynamic pattern matching

## Overview

Component Snapshots compare rendered fixture HTML with reviewed snapshot files.
Templates need no changes. When creating or updating a snapshot,
Frontend Studio renders each variant twice without waiting.
For snapshot creation and `--update`, the second render uses TYPO3's Context
clock advanced by one second. Normal comparisons use the same clock for both samples.
It compares HTML structure separately from attribute values and text nodes,
then replaces changing values with `{{frontend-studio:dynamic}}`.

Known patterns identify complete dates, times, IDs and tokens,
including portions that happen to stay the same between samples.
Stable surrounding text remains checked. For example,
`A2026-Oct-Tue 14:10:01.000000B` can become:

```html
A{{frontend-studio:dynamic}}B
```

If no suitable pattern explains a changing attribute,
its entire value becomes one marker. Unrecognized text changes mask complete changing words or tokens,
preserving surrounding labels and punctuation.
Markers stay within their attribute value or text node;
they never replace neighboring elements or attributes.

This is heuristic matching. Only changes observed in the two samples trigger
automatic markers. A date that stays unchanged is saved literally.
Arbitrary date formats, localized month names and random strings may use the
fallback instead. Review generated snapshots before committing them.

## Manually editing snapshot files

Snapshot files live beside the component template. For `Text.fluid.html` and
variant `Default`, the file is `Text.fluid.html-snapshots/html-Default.html`.
Run the test command with `-v` to see project-relative paths:

```bash
vendor/bin/typo3 frontend-studio:test main en-us --scope=c:element.text -v
```

Open the snapshot file and replace only the value or substring you intend to
ignore with the exact marker `{{frontend-studio:dynamic}}`.
For example, keep the ID prefix, class and label checked:

```html
<div id="card-{{frontend-studio:dynamic}}" class="card">Hello</div>
```

For an arbitrary changing attribute value, use:

```html
<div data-token="{{frontend-studio:dynamic}}" class="card">Hello</div>
```

For dates or other content that did not change during sampling,
you can manually mark the complete value while preserving its label:

```html
<p>Published: {{frontend-studio:dynamic}}; status ready</p>
```

A marker accepts any substring, including spaces or an empty value,
inside that field. It does not enforce the original date format or ID type.
Put markers inside attribute values or text, rather than around tag names,
attribute names, comments or HTML structure. Keep quotes and important text
outside the marker. Multiple markers can share one field;
the literal content between them remains checked.

Rerun without `--update` to verify your edits, then commit the reviewed files.
Normal comparisons never overwrite files or add more markers.
To regenerate the selected snapshots automatically instead,
append `--update` or `-u` to the same command:

```bash
vendor/bin/typo3 frontend-studio:test main en-us --scope=c:element.text -u
```

Updating recalculates markers and can replace your manual edits.
If any snapshot file is created or changed, the command returns exit code `2`,
so a CI job using `--update` or `-u` still fails when snapshots need committing.
An unchanged update run returns `0`; file changes combined with errors return `3`.
Review the resulting Git diff before committing. Production and its subcontexts
reject updates. Legacy whole-line markers are rejected;
use `--update` to regenerate them.

## Current time in custom PHP code

Use TYPO3's Context date API when custom ViewHelpers, transformers or services
need the current time. Inject `TYPO3\CMS\Core\Context\Context` into your service:

```php
use DateTimeImmutable;
use TYPO3\CMS\Core\Context\Context;

final readonly class CurrentDateLabel
{
    public function __construct(private Context $context)
    {
    }

    public function render(): string
    {
        /** @var DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        return $now->format('Y-m-d H:i:s');
    }
}
```

For a Unix timestamp, use
`$this->context->getPropertyFromAspect('date', 'timestamp')`.
These are TYPO3's official date aspect APIs; see the
[Context API documentation](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Context/Index.html).
`<f:format.date date="now" />` already uses this clock,
so Fluid templates need no changes.

Do not use `time()`, `date()` without a supplied timestamp,
`new DateTime()` or `new DateTimeImmutable()` without an explicit date to obtain
the current time in component code. They read PHP's real clock,
which snapshot sampling cannot advance. Such values may stay identical between
samples and require manually added dynamic markers.
Creating objects for explicit fixture dates remains supported.

The sampling clock advances by one second, so formats without seconds,
such as `Y-m-d` or `H:i`, usually stay unchanged and need manual markers if dynamic.
The previous Context date aspect is restored after every render,
including when rendering throws an exception.

Inline `<f:debug>` content is included in snapshots,
but TYPO3's shared debugger stylesheet is omitted like other support assets.
That stylesheet is normally emitted only once per PHP process;
omitting it keeps both samples consistent without changing templates.
The debugger's stylesheet state is restored after rendering.

## How automatic matching works

The two samples must have the same HTML structure.
Element names, attribute names, quotes, comments and declarations remain literal.
Adding an element or attribute, or changing that structure between samples,
fails snapshot creation rather than becoming a wildcard.

Attribute values and text nodes are considered separately.
Patterns are checked in priority order: URL parameter values first,
then complete dates with optional times, standalone times,
UUIDs, ULIDs, timestamp attributes, hexadecimal tokens and numeric runs.
Overlapping smaller patterns are ignored. For example,
a timestamp becomes one marker rather than separate markers for its digits,
and a query parameter containing a UUID is treated as one parameter value.

Corresponding recognized values must have the same pattern kinds in both
samples. Unchanged recognized values remain literal. For changed values,
the complete recognized region becomes a marker.
Whitespace runs compare as one space; whitespace presence still matters.
This also applies inside attributes and whitespace-sensitive elements.

### UUIDs

Recognizes hyphenated `8-4-4-4-12` hexadecimal UUIDs and compact 32-character
hexadecimal UUIDs, case-insensitively. UUID version and variant bits are not
validated. A changed UUID is masked completely:

```html
<!-- Samples contain different UUIDs after card- -->
<div id="card-{{frontend-studio:dynamic}}" class="card"></div>
```

Compact hexadecimal values need hexadecimal boundaries so they are not taken
from the middle of a longer hex token.

### Numeric IDs and counters

Recognizes consecutive decimal digits, preserving surrounding prefixes,
suffixes and punctuation. For `component-123` and `component-987`:

```html
<div id="component-{{frontend-studio:dynamic}}"></div>
```

Numbers have the lowest priority, so digits inside a recognized date,
UUID, ULID or hexadecimal token do not get separate markers.
Changing prices, counters and other decimal runs can also match this heuristic;
review which values you intend to ignore.

### ISO dates and timestamps

Recognizes year-first numeric dates such as `2026-10-06`,
`2026/10/06` and `2026.10.06`. Dates may include a time separated by whitespace
or `T`, with optional seconds, fractions and timezone suffixes:

```text
2026-10-06T14:10:01
2026-10-06T14:10:01.123456Z
2026-10-06T14:10:01+02:00
2026-10-06 14:10:01-0500
```

A change anywhere in the recognized timestamp masks the complete timestamp:

```html
<p>A{{frontend-studio:dynamic}}B</p>
```

These patterns recognize syntax; they do not validate calendar dates.

### European and US numeric dates

Recognizes day/month/year and month/day/year shapes with dots,
slashes or hyphens, and an optional time. Examples include:

```text
06.10.2026
06/10/2026
10/06/2026
06-10-2026 14:10:01
```

Day and month order is not inferred; both forms mask the full recognized date.

### Times

Recognizes hours and minutes, optional seconds, dot or comma fractions,
AM/PM, and optional `Z` or numeric timezone offsets. Examples:

```text
14:10
14:10:01.123456
14:10:01,123
02:10:01 PM
14:10:01+02:00
```

For changing times beside a fixed label:

```html
<p>Generated at {{frontend-studio:dynamic}}; status ready</p>
```

### Named-month dates

Recognizes English abbreviated and full month names, case-insensitively,
in these arrangements:

```text
06 Oct 2026
06 October 2026
October 6, 2026
October 6th, 2026
2026-Oct-06
2026-Oct-Tue
2026-October-Tuesday 14:10:01.123456
```

The year-first form also recognizes English abbreviated or full weekday names.
This covers the `Y-M-D H:m:s.u` example without changing its Fluid template.
Other languages and arbitrary arrangements use the fallback.

### Unix timestamps

Recognizes 10-digit seconds and 13-digit milliseconds in attributes whose names
contain `time` or `timestamp`, case-insensitively:

```html
<div data-timestamp="{{frontend-studio:dynamic}}"></div>
<div data-time="prefix-{{frontend-studio:dynamic}}"></div>
```

Elsewhere, changing decimal digits are handled by the numeric-run pattern.
The matcher does not check whether the value represents a plausible date.

### ULIDs

Recognizes 26-character Crockford Base32 ULIDs beginning with `0` through `7`,
case-insensitively. The complete value is masked,
rather than only the changing timestamp or random suffix:

```html
<p>Request: {{frontend-studio:dynamic}}</p>
```

Example input: `01ARZ3NDEKTSV4RRFFQ69G5FAV`.

### Random hexadecimal tokens

Recognizes hexadecimal runs of at least 16 characters,
with boundaries preventing partial matches inside longer hexadecimal runs.
Changed tokens are masked completely, including coincidentally shared digits:

```html
<p>Hash: {{frontend-studio:dynamic}}</p>
```

Example inputs include 16-character random tokens and longer hashes.
Compact 32-character values are recognized by the higher-priority UUID pattern;
the resulting marker is the same.

### URL parameter values

Recognizes query parameter values after `?`, `&` or HTML-encoded `&amp;`.
Parameter names can contain letters, digits, underscores, dots and hyphens.
A changed parameter value is masked while the path,
parameter name, unchanged parameters and fragment remain checked:

```html
<a href="/path?token={{frontend-studio:dynamic}}&amp;page=1#top">Link</a>
```

Empty values are supported. Parameter markers cannot consume another `&`
parameter or a `#` fragment. This pattern does not decode percent escapes;
encoded values are compared as rendered.

### Fallback for changing attribute values

If recognized patterns cannot account for all changes in an attribute,
its entire value becomes one marker. For example,
`abc-Xq-zz` and `abc-Kz-ww` produce:

```html
<div data-token="{{frontend-studio:dynamic}}" class="card">Hello</div>
```

This also applies when a value changes from empty to nonempty,
or mixes recognized and unrecognized changes.
The attribute must still exist. Its name, quoting, neighboring attributes,
element and text remain checked. For unquoted attributes,
HTML whitespace and closing syntax still delimit the value.

### Fallback for changing text

Unrecognized text uses character differences to locate changes,
then expands them to complete words or tokens. Letters, Unicode combining marks,
digits, underscores and hyphens belong to a word; surrounding whitespace and
punctuation remain checked. Several changes inside one word share one marker.
For `Token: abcXdef; status ready` and `Token: abcYdef; status ready`:

```html
<p>Token: {{frontend-studio:dynamic}}; status ready</p>
```

Several changing words can produce several markers. Shared characters inside a
changing word are ignored, avoiding fixed accidental prefixes or suffixes.
Unchanged neighboring words remain literal. Tokens containing other punctuation
can be split into several words; manual editing can widen the marker when needed.
Fallback changes spanning inserted, removed or moved lines fail for review.
Markers cannot absorb another element or comment,
because text nodes are matched separately from HTML structure.
