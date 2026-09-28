<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer\Defaults;

use RuntimeException;
use Andersundsehr\FrontendStudio\Transformer\Attribute\TypeTransformer;
use Stringable;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\LinkHandling\TypolinkParameter;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final readonly class DefaultTransformer
{
    private const string DEFAULT_URL = 'https://extensions.typo3.org/extension/frontend_studio';

    private const string DEFAULT_FILE = 'EXT:frontend_studio/Resources/Public/Image/FrontendStudioDeveloper.png';

    public function __construct(private ResourceFactory $resourceFactory)
    {
    }

    #[TypeTransformer(priority: 100)]
    public function stringable(string $string): Stringable
    {
        return new readonly class ($string) implements Stringable {
            public function __construct(private string $string)
            {
            }

            public function __toString(): string
            {
                return $this->string;
            }
        };
    }

    #[TypeTransformer(priority: 100)]
    public function uri(string $url = self::DEFAULT_URL): Uri
    {
        return new Uri($url);
    }

    // Transformer also matches to FileInterface or AbstractFile
    #[TypeTransformer(priority: 100)]
    public function file(string $extPath = self::DEFAULT_FILE): File
    {
        $file = $this->resourceFactory->retrieveFileOrFolderObject($extPath);
        if (!$file instanceof File) {
            throw new RuntimeException('The file with the path "' . $extPath . '" could not be resolved to a File object.', 7836790419);
        }

        return $file;
    }

    #[TypeTransformer(priority: 100)]
    public function typolinkParameter(
        string $url = self::DEFAULT_URL,
        TypolinkTargetEnum $target = TypolinkTargetEnum::none,
        string $class = '',
        string $title = '',
        string $additionalParams = '',
    ): TypolinkParameter {
        return TypolinkParameter::createFromTypolinkParts(
            [
                'url' => $url,
                'target' => $target->value,
                'class' => $class,
                'title' => $title,
                'additionalParams' => $additionalParams,
            ]
        );
    }

    #[TypeTransformer(priority: 100)]
    public function stringableString(string $string): Stringable
    {
        return new readonly class ($string) implements Stringable {
            public function __construct(private string $string)
            {
            }

            public function __toString(): string
            {
                return $this->string;
            }
        };
    }
}
