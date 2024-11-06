<?php

declare(strict_types=1);

/**
 * This file was copied from https://github.com/jbelien/phpstan-sarif-formatter/blob/d8cf03abf5c8e209e55e10d6a80160f0d97cdb19/src/SarifErrorFormatter.php,
 * originally released under the MIT license.
 *
 * The code has been changed to export rules (without descriptions)
 */

namespace MongoDB\Laravel\Tests\PHPStan;

use Nette\Utils\Json;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;
use PHPStan\Internal\ComposerHelper;

use function array_values;

/** @internal */
class SarifErrorFormatter implements ErrorFormatter
{
    private const URI_BASE_ID = 'WORKINGDIR';

    public function __construct(
        private RelativePathHelper $relativePathHelper,
        private string $currentWorkingDirectory,
        private bool $pretty,
    ) {
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        // @phpstan-ignore phpstanApi.method
        $phpstanVersion = ComposerHelper::getPhpStanVersion();

        $originalUriBaseIds = [
            self::URI_BASE_ID => [
                'uri' => 'file://' . $this->currentWorkingDirectory . '/',
            ],
        ];

        $results = [];
        $rules = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            $ruleId = $fileSpecificError->getIdentifier();
            $rules[$ruleId] = ['id' => $ruleId];

            $result = [
                'ruleId' => $ruleId,
                'level' => 'error',
                'message' => [
                    'text' => $fileSpecificError->getMessage(),
                ],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => $this->relativePathHelper->getRelativePath($fileSpecificError->getFile()),
                                'uriBaseId' => self::URI_BASE_ID,
                            ],
                        ],
                    ],
                ],
                'properties' => [
                    'ignorable' => $fileSpecificError->canBeIgnored(),
                ],
            ];

            if ($fileSpecificError->getTip() !== null) {
                $result['properties']['tip'] = $fileSpecificError->getTip();
            }

            if ($fileSpecificError->getLine() !== null) {
                $result['locations'][0]['physicalLocation']['region']['startLine'] = $fileSpecificError->getLine();
            }

            $results[] = $result;
        }

        foreach ($analysisResult->getNotFileSpecificErrors() as $notFileSpecificError) {
            $results[] = [
                'level' => 'error',
                'message' => [
                    'text' => $notFileSpecificError,
                ],
            ];
        }

        foreach ($analysisResult->getWarnings() as $warning) {
            $results[] = [
                'level' => 'warning',
                'message' => [
                    'text' => $warning,
                ],
            ];
        }

        $sarif = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'PHPStan',
                            'fullName' => 'PHP Static Analysis Tool',
                            'informationUri' => 'https://phpstan.org',
                            'version' => $phpstanVersion,
                            'semanticVersion' => $phpstanVersion,
                            'rules' => array_values($rules),
                        ],
                    ],
                    'originalUriBaseIds' => $originalUriBaseIds,
                    'results' => $results,
                ],
            ],
        ];

        $json = Json::encode($sarif, $this->pretty ? Json::PRETTY : 0);

        $output->writeRaw($json);

        return $analysisResult->hasErrors() ? 1 : 0;
    }
}
