<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

final readonly class PreviewTypoScriptContextBuilder implements PreviewTypoScriptContextBuilderInterface
{
    public function __construct(private FrontendTypoScriptFactory $frontendTypoScriptFactory)
    {
    }

    public function build(ServerRequestInterface $request): ServerRequestInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            throw new RuntimeException('The preview request has no resolved site.', 2420874664);
        }

        $siteLanguage = $request->getAttribute('language');
        if (!$siteLanguage instanceof SiteLanguage) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        $request = $request->withAttribute('language', $siteLanguage);
        $siteForTypoScript = new Site(
            $site->getIdentifier(),
            $site->getRootPageId(),
            $site->getConfiguration(),
            $site->getSettings(),
            $site->getTypoScript(),
        );
        $sysTemplateRows = [];
        $conditionMatcherVariables = [
            'request' => $request,
            'pageId' => $site->getRootPageId(),
            'page' => [],
            'fullRootLine' => [],
            'localRootLine' => [],
            'site' => $site,
            'siteLanguage' => $siteLanguage,
        ];
        $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
            $siteForTypoScript,
            $sysTemplateRows,
            $conditionMatcherVariables,
            null,
        );
        $frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
            true,
            $frontendTypoScript,
            $siteForTypoScript,
            $sysTemplateRows,
            $conditionMatcherVariables,
            '0',
            null,
            $request,
        );
        $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObjectRenderer->setRequest($request);

        $request = $request->withAttribute('currentContentObject', $contentObjectRenderer);
        $contentObjectRenderer->setRequest($request);

        return $request;
    }
}
