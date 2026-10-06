<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Error;
use Andersundsehr\FrontendStudio\Dto\ComponentTemplateMetadata;
use Throwable;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3Fluid\Fluid\Core\TemplateLocationException;
use TYPO3Fluid\Fluid\Core\Parser\Exception as ParserException;
use TYPO3Fluid\Fluid\Validation\TemplateValidator;
use TYPO3Fluid\Fluid\Validation\TemplateValidatorResult;

/** Isolates the internal Fluid validation API used by TYPO3's fluid:analyze command. */
final readonly class FluidTemplateAnalyzer
{
    public function __construct(private RenderingContextFactory $renderingContextFactory, private HtmlSourceHighlighter $highlighter)
    {
    }

    /**
     * @return array{source: string, errorCount: int, status: string}
     */
    public function analyze(?ComponentTemplateMetadata $template): array
    {
        if ($template?->content === null || $template->absolutePath === null || $template->error !== null) {
            return ['source' => '', 'errorCount' => 1, 'status' => 'The Fluid template could not be loaded. ' . ($template->error ?? 'Template source is unavailable.')];
        }

        try {
            $context = $this->renderingContextFactory->create();
            // Validate the exact snapshot shown in the UI, even if the file changes during this request.
            $context->getTemplatePaths()->setTemplateSource($template->content);
            try {
                $result = new TemplateValidator()->validateTemplateFiles([$template->absolutePath], $context)[$template->absolutePath];
            } catch (Error $error) {
                // Fluid restores its temporary handler for Exceptions, but not PHP Errors.
                restore_error_handler();
                throw $error;
            }

            $diagnostics = $this->diagnostics($result, $template->content);
            return [
                'source' => $this->highlighter->highlightFluidDiagnostics($template->content, $diagnostics),
                'errorCount' => count($result->errors),
                'status' => '',
            ];
        } catch (Throwable $throwable) {
            return [
                'source' => $this->highlighter->highlightFluidDiagnostics($template->content, []),
                'errorCount' => 1,
                'status' => 'Fluid analysis is unavailable. ' . $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return list<array{line: ?int, character: ?int, severity: string, message: string}>
     */
    public function diagnostics(TemplateValidatorResult $result, string $source): array
    {
        $diagnostics = [];
        $lineCount = substr_count($source, "\n") + 1;
        foreach ($result->errors as $error) {
            $location = $error instanceof TemplateLocationException ? $error->getTemplateLocation() : null;
            $line = $location !== null && $location->identifierOrPath === $result->path && $location->line > 0 && $location->line <= $lineCount
                ? $location->line : null;
            // Fluid's location wrapper repeats the path, position and source chunk.
            // Keep its structured location for the marker, but display the original error.
            $message = $error instanceof ParserException && $error->getPrevious() !== null
                ? $error->getPrevious()->getMessage() . ' (error code ' . $error->getCode() . ').'
                : $error->getMessage();
            $diagnostics[] = ['line' => $line, 'character' => $line !== null && $location->character > 0 ? $location->character : null, 'severity' => 'error', 'message' => $message];
        }

        foreach ($result->deprecations as $deprecation) {
            // Deprecation locations refer to PHP, not the displayed Fluid source.
            $diagnostics[] = ['line' => null, 'character' => null, 'severity' => 'deprecation', 'message' => $deprecation->message];
        }

        return $diagnostics;
    }
}
