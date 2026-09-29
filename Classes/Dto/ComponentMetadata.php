<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentMetadata
{
    /**
     * @param list<ComponentArgumentMetadata> $arguments
     * @param list<string> $slots
     * @param list<class-string> $annotations
     * @param list<ComponentStaticVariableMetadata> $staticVariables
     * @param list<string> $errors
     */
    public function __construct(
        public string $selectedVariantIdentifier,
        public string $variantName,
        public string $variantLabel,
        public string $componentIdentifier,
        public string $componentName,
        public string $displayNamespace,
        public string $sourceNamespace,
        public bool $usesNamespaceAlias,
        public string $collectionClass,
        public array $arguments,
        public bool $additionalArgumentsAllowed,
        public array $slots,
        public array $annotations,
        public ComponentTemplateMetadata $template,
        public ?ComponentFixtureMetadata $fixture,
        public array $staticVariables,
        public array $errors,
    ) {
    }
}
