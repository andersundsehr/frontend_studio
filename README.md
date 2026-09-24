# Frontend Studio

Backend development tooling for building, testing, previewing, and documenting TYPO3 Fluid components.

Frontend Studio turns registered Fluid component collections into a TYPO3 backend frontend workshop.
It helps frontend developers:

- browse available components
- preview fixture variants
- adjust values through controls
- inspect the rendered output
- and keep useful examples close to the component templates.

## Why Components?

Small, explicit components are easier to reuse, review, and test in isolation.
Variants keep realistic examples beside their component,
so frontend developers can inspect and adjust a component without rebuilding a complete page.

## Screenshot

![Frontend Studio backend module](user-interface-example.png)

## Features

- Browse discovered Fluid component namespaces, folders, components, and fixture variants in a TYPO3 backend module.
- Preview selected variants in an isolated render area.
- Edit fixture values through generated controls based on component argument metadata.
- Inspect rendered HTML, Fluid template source, and generated Fluid usage snippets.
- Create, rename, delete, and save component variants where the component collection and fixture file support it.
- Download component folders as ZIP archives.
- Reload previews automatically during development when watched component template or fixture files change.

## Requirements

- TYPO3 `^14.3`
- Composer-based TYPO3 installation
- Fluid component collections registered in TYPO3 and discoverable through the Fluid component infrastructure

Frontend Studio is intended for project development workflows and should be installed as a development dependency.

## Installation

Install the extension with Composer:

```bash
composer require --dev andersundsehr/frontend_studio
```

Then flush TYPO3 caches and make sure your project registers at least one Fluid component collection.

## Quick Start

1. Open the TYPO3 backend.
2. Go to `Developer > Frontend Studio`.
3. Select a component namespace and component from the navigation tree.
4. Use `Create variant` to add a variant and enter its name.
5. Preview the variant in the main render area.
6. Use `Controls` to adjust its values, then use `Save` to persist them.
7. Use the inspector tabs to review `Rendered HTML`, `Fluid Template`, and `Fluid Usage`.

The module works with component metadata provided by the registered Fluid component collection.
Components without fixture variants can still appear in the tree.
Use `Create variant` to make one previewable and editable; Frontend Studio creates the sibling fixture file when needed.

## Register a Component Collection

Frontend Studio discovers the Fluid component collections registered by the project.
TYPO3 14.1 and later can [register a collection declaratively](https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.1/Feature-108508-FluidComponentsIntegration.html), so most projects do not need a custom PHP class.

`Configuration/Fluid/ComponentCollections.php` registers a collection namespace and its template paths:
```php
<?php
return [
    'Vendor\\Site\\Components' => [
        'templatePaths' => [
            10 => 'EXT:site_package/Resources/Private/Components',
        ],
    ],
];
```

Register the same collection namespace as a Fluid namespace alias in `Configuration/Fluid/Namespaces.php`:

```php
<?php
return [
    'site' => [
        'Vendor\\Site\\Components',
    ],
];
```

For better IDE integration, you can add `xmlns:site="http://typo3.org/ns/Vendor/Site/Components"` to your templates.

## A Previewable Component

The following Card is available as `<site:card>`:

`Components/Card/Card.fluid.html`
```html
<f:argument name="title" type="string" />
<f:argument name="text" type="string" />

<article class="card">
    <h2>{title}</h2>
    <p>{text}</p>
    <f:slot />
</article>
```

Open `Developer > Frontend Studio`, select the `site` namespace and Card, then use `Create variant` to create `Default`.
Use `Controls` to change the values and `Save` to persist them.

Frontend Studio creates the file `Components/Card/Card.fixture.yaml`:
```yaml
variants:
  Default:
    title: "Project update"
    text: "The deployment completed successfully."
```

Use `Save as new` to create another variant.

## Component Fixtures

Frontend Studio creates and updates fixture files next to component templates.
Use `Create variant`, `Controls`, and `Save` instead of manually editing them.
For a template named `ContactTeaser.html` or `ContactTeaser.fluid.html`, the sibling fixture file is:

```text
ContactTeaser.fixture.yaml
```

Fixture files use a top-level `variants` map.
Each key is the variant name, and each value is a map of argument values passed to the component.

```yaml
variants:
  Default:
    name: "Matthias Vogel"
    role: "Technical Lead"
    email: "m.vogel@andersundsehr.com"
    phone: ""
    highlighted: false
  Highlighted:
    name: "Frontend Studio"
    role: "Component Workshop"
    email: "frontend@example.org"
    highlighted: true
```

## Component Slots

Declared component slots appear as HTML controls.
Their trusted raw HTML is stored separately from fixture YAML next to the component template:

```text
_slots/<VariantName>__slot__<slotName>.fluid.html
```

The filename uses the variant and slot names.
Runs of filesystem-invalid characters (`< > : " / \ | ? *` and control bytes) become one `-`; spaces remain unchanged.

Variant values are matched with the component argument definitions when metadata is available.
Missing fixture values fall back to the argument default values shown by the component definition.

## Complex Arguments and Transformers

Frontend Studio derives controls from component argument types.
Scalars use native inputs, enums use selects, and `DateTime`, `DateTimeImmutable`, and `DateTimeInterface` use a local date-time input.
Built-in transformers cover `Stringable`, `Uri`, `File`, and `TypolinkParameter` arguments.

For another reusable type, add a method to an autoconfigured service.
Its typed inputs become controls, its return type must match the component argument, and the transformer with the highest priority is used:

```php
use Andersundsehr\FrontendStudio\Transformer\Attribute\TypeTransformer;
use TYPO3\CMS\Core\Http\Uri;

final readonly class ComponentTransformer
{
    #[TypeTransformer(priority: 100)]
    public function uri(string $url): Uri
    {
        return new Uri($url);
    }
}
```

For a transformation that only applies to one argument, create a sibling `Card.transformer.php` file.
It must return named `ArgumentTransformers` closures; container services may be typed closure parameters, while the remaining typed parameters become fixture inputs:

```php
<?php

use Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

return new ArgumentTransformers(
    websocket: fn(string $url): Uri => (new Uri($url))->withScheme('wss'),
    contextIcon: fn(ContextualFeedbackSeverity $severity): string => $severity->getIconIdentifier(),
    combineUri: fn(
        string $host,
        string $path,
        string $query = '',
        string $fragment = '',
        string $scheme = 'https',
    ): Uri => new Uri($scheme . '://' . $host . '/' . $path . '?' . $query . '#' . $fragment),
    transformerWithoutArguments: fn(): Uri => new Uri('https://example.org'),
    fileWithDefault: function (ResourceFactory $resourceFactory, string $extPath = 'EXT:frontend_studio/Resources/Public/Icons/Extension.svg'): File {
        $file = $resourceFactory->retrieveFileOrFolderObject($extPath);
        assert($file instanceof File, 'The file with the path "' . $extPath . '" could not be resolved to a File object.');
        return $file;
    },
);
```

Store transformed values as a nested map under the component argument name:

```yaml
variants:
  Default:
    combineUri:
      host: "example.org"
      path: "news"
```

## Working With Variants

Frontend Studio stores variants in the component's `.fixture.yaml` file.
Depending on the selected node and available metadata, the backend module can:

- Create a new variant for a component.
- Rename an existing variant.
- Delete an existing variant.
- Save changed control values back to the fixture file.
- Download a component folder as a ZIP archive.

New variants are initialized with practical defaults for required arguments
where Frontend Studio can infer them from the argument type.

## Assets in Previews

The preview includes CSS and JavaScript registered while rendering the component through TYPO3's `AssetCollector`.
No Frontend Studio-specific asset setup is required.

## Auto Reload In Development

Frontend Studio can watch component template roots and refresh changed component previews while you work.
File watching is enabled only when the TYPO3 application context is `Development` or a Development subcontext such as `Development/Local`.

```bash
TYPO3_CONTEXT=Development
```

In non-development contexts, the change stream endpoint is disabled.

## Troubleshooting

### The Module Is Empty

Check that your Fluid component collection is registered and exposes available components.
Frontend Studio lists component collections that TYPO3's Fluid component infrastructure can discover.

### A Component Has No Variants

Select the component and use `Create variant` to add one.
Frontend Studio creates the sibling fixture file when it does not exist.

### A Fixture File Shows An Error

Make sure the fixture file contains valid YAML and that the root value is a map with a `variants` key.
The `variants` value must also be a map.

### Changes Do Not Auto Reload

Confirm that TYPO3 runs in `Development` context or a Development subcontext.
Auto reload is intentionally disabled outside development contexts.

## Further Reading

- [Fluid components](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Fluid/UsingFluidInTypo3.html)
- [ViewHelper `f:argument`](https://docs.typo3.org/other/typo3/view-helper-reference/main/en-us/Global/Argument.html)
- [ViewHelper `f:slot`](https://docs.typo3.org/other/typo3/view-helper-reference/main/en-us/Global/Slot.html)

## Development Notes

- The backend module is registered below `Developer` as `Frontend Studio`.
- Preview rendering is handled through a dedicated preview request so the selected variant can be opened separately.
- Fixture files are written by TYPO3 when variants are created, renamed, deleted, or saved from the controls panel.
- The extension is intentionally focused on TYPO3 Fluid components and the metadata exposed by TYPO3's component APIs.

## Contributor Checks

The test runner uses Docker or Podman.
Run the checks with each supported PHP version (`8.4` and `8.5`); the CI setup covers GrumPHP, unit tests, and functional tests with MySQL, MariaDB, and PostgreSQL:

```bash
./Build/Scripts/runTests.sh -p 8.5 -s composerUpdate
./Build/Scripts/runTests.sh -p 8.5 -s grumphpRun
./Build/Scripts/runTests.sh -p 8.5 -s unit
./Build/Scripts/runTests.sh -p 8.5 -s functional -d mysql
./Build/Scripts/runTests.sh -p 8.5 -s functional -d mariadb
./Build/Scripts/runTests.sh -p 8.5 -s functional -d postgres
```

## License and Author

Frontend Studio is licensed under [GPL-2.0-or-later](LICENSE).
The package author is Matthias Vogel (`m.vogel@andersundsehr.com`).

# with ♥️ from anders und sehr GmbH

> If something did not work 😮
> or you appreciate this Extension 🥰 let us know.

> We are always looking for great people to join our team!
> https://www.andersundsehr.com/karriere/
