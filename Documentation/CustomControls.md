# Custom controls

Controls change the inspector input, while transformers convert fixture data into component values.
Register a public instance method on an autoconfigured service:

```php
#[TypeControl(id: 'acme.html', type: 'string|Stringable', priority: 100)]
public function html(ControlContext $context): ?ControlDefinition
{
    if ($context->input?->getName() !== 'string') {
        return null;
    }
    return new ControlDefinition(
        'EXT:acme/Resources/Private/Controls/Html.html',
        '@acme/controls/html.js',
        ['toolbar' => ['bold', 'italic']],
    );
}
```

Import the three control classes from `Andersundsehr\FrontendStudio\Control`
and `TypeControl` from its `Attribute` namespace.
The context contains the original component argument, current fixture value,
fixture presence, transformer input definition (including required/default state),
and transformer source. Metadata resolution never executes the transformer.

Union order, leading namespace separators and nullable shorthand are normalized.
Exact argument types precede `*` providers, then higher priority and alphabetical registration ID decide order.
A provider can return null to decline. IDs must be unique.
Wildcard providers can inspect the full context for more specialized matching.
A transformer parameter can select a registered provider explicitly with
`#[Control('acme.html')]` (same attribute namespace).
Unknown IDs or a declined explicit selection raise configuration errors.
Existing built-in controls remain the fallback.

The template receives `variantValue` and must emit one native fixture input
with `data-frontend-studio-variant-value`, `data-fixture-name`,
`data-fixture-type`, and `data-fixture-value-defined`.
Preserve its value, required/null state, name and accessible label.
Keep the native input available as the editor's accessible fallback.
Only service-provided `EXT:` template paths and import-map module names are accepted;
fixture/request values never select templates or modules.
Declare the module and any dependencies in your extension's `Configuration/JavaScriptModules.php`.
The inspector includes it using TYPO3's asset module mechanism.

The module exports an async default mount function:

```js
export default async function mount({ host, field, options, signal, changed }) {
  // Initialize the editor from field.value; await editor startup here.
  return {
    getValue: () => field.value,
    setValue: (value) => { field.value = value ?? ''; },
    validate: () => '', // Empty string means valid; otherwise a user-facing error.
    destroy: () => {},
  };
}
```

After mount, value and validation methods must be synchronous.
Call `changed()` on editor changes, use `signal` for event listeners,
and release editor resources in `destroy()`.
Mounting blocks Save, Save as new and Reset; initialization failures remain blocked and show an error.
The initial snapshot is recorded after mounting.
Adapters participate in dirty tracking, Reset, Save, copy and preview URLs.
A late mount result is destroyed if navigation already removed the inspector.
Native controls retain their existing behavior.

The `preview_site_set` functional fixture demonstrates an extension-owned provider,
attributed transformer parameter, Fluid template and asynchronous JavaScript adapter.
Its nested `body.text` fixture shape is unchanged by control registration.
