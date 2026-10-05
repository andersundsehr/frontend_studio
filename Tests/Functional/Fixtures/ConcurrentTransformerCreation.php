<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTransformerGenerator;
use Andersundsehr\FrontendStudio\Service\ComponentWritePolicy;
use Andersundsehr\FrontendStudio\Service\TransformerTemplateGenerator;
use TYPO3\TestingFramework\Core\Testbase;

$autoloadPath = $_SERVER['argv'][1] ?? '';
$instancePath = $_SERVER['argv'][2] ?? '';
if (!is_string($autoloadPath) || $autoloadPath === '' || !is_string($instancePath) || $instancePath === '') {
    throw new InvalidArgumentException('Provide the autoloader and functional test instance paths.', 4926101826);
}

require $autoloadPath;
$container = new Testbase()->setUpBasicTypo3Bootstrap($instancePath);
$generator = new ComponentTransformerGenerator(
    $container->get(ComponentMetadataProvider::class),
    new TransformerTemplateGenerator(),
    new ComponentWritePolicy(),
);
fwrite(STDOUT, "ready\n");
fgets(STDIN);
try {
    $generator->create('site:missingTransformer:Default');
    fwrite(STDOUT, 'created');
} catch (InvalidArgumentException | RuntimeException) {
    fwrite(STDOUT, 'rejected');
}
