<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

final readonly class ComponentResolverDelegateProvider
{
    public function __construct(
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
    ) {
    }

    /**
     * @return array<string, ViewHelperResolverDelegateInterface>
     */
    public function getAll(): array
    {
        $viewHelperResolver = $this->viewHelperResolverFactory->create();
        $resolverDelegates = [];

        foreach ($viewHelperResolver->getNamespaces() as $classNamespaces) {
            if ($classNamespaces === null) {
                continue;
            }

            foreach ((array)$classNamespaces as $classNamespace) {
                if (!is_string($classNamespace) || $classNamespace === '') {
                    continue;
                }

                $resolverDelegates[$classNamespace] ??= $viewHelperResolver->getResolverDelegate($classNamespace);
            }
        }

        return $resolverDelegates;
    }
}
