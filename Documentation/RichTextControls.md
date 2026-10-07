# Rich-text arguments

This feature emulates TYPO3's `f:render.text` for component fixtures:
plain string arguments retain Fluid escaping,
while rich-text unions produce sanitized HTML that Fluid can render directly.
It does not resolve database records or TCA field configuration like `f:render.text`.

Arguments declared `string|Stringable` or
`string|TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML` use TYPO3's CKEditor 5.
Union member order does not matter. Plain `string` remains a normal input,
and component-specific transformers retain precedence.
The integration uses the custom-control registry and needs `typo3/cms-rte-ckeditor`.

Fixtures retain the previous string transformer input:

```yaml
variants:
  Default:
    body:
      string: '<p><strong>Welcome</strong></p>'
```

The editor provides paragraphs, bold/italic, links, ordered/unordered lists,
and undo/redo. Reset, live preview and saving use its current HTML.
Existing authentication and Production write restrictions apply.

Saved HTML excludes CKEditor's internal `data-list-item-id` attributes and
`ck-list-marker-bold` / `ck-list-marker-italic` classes.
Bold and italic formatting inside list items is preserved.
Editor text follows TYPO3's backend theme colors in light and dark mode.

Rendering passes the stored HTML through the TYPO3 HTML sanitizer's `CommonBuilder`
profile before wrapping it as `UnsafeHTML`. Supported formatting survives;
unsafe tags are encoded and unsafe attributes/URI schemes are removed.
The fixture retains editor HTML; sanitization is performed on every transformation,
including manually edited fixtures. Plain string arguments retain Fluid escaping.
This intentionally does not run TYPO3 database RTE transformations or expose record link browsers.

Rich-text transformers target these union types. Plain `Stringable` arguments
keep the default transformer and escaped output.
