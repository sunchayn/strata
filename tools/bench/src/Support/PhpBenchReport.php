<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Exception;
use SimpleXMLElement;
use Sunchayn\Strata\Bench\ValueObjects\AggregatedRow;
use Sunchayn\Strata\Bench\ValueObjects\RowKind;

/**
 * Reads a PHPBench dump-file result and reshapes it into the strata/laravelFileDriver comparison table.
 */
final class PhpBenchReport
{
    private const array BENCHMARK_HEADINGS = [
        'ThroughputBench' => 'Read/write throughput',
        'DataShapeBench' => 'Data shape coverage',
        'EvictionReadCostBench' => 'Expiry/eviction read cost vs payload size',
    ];

    private const array SUBJECT_VERBS = [
        'benchPut' => 'put',
        'benchGetHit' => 'get hit',
        'benchManyX10' => 'many x10',
        'benchForget' => 'forget',
        'benchIncrement' => 'increment',
        'benchExpiredMiss' => 'expired miss',
        'benchLiveHit' => 'live hit',
    ];

    /**
     * @return array<string, array<int, AggregatedRow>> Rows grouped by heading, in file order.
     *
     * @throws Exception
     */
    public static function parse(string $dumpFilePath): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents($dumpFilePath));

        $grouped = [];

        foreach ($xml->suite->benchmark as $benchmark) {
            $heading = self::headingFor((string) $benchmark['class']);

            $grouped[$heading] ??= [];

            foreach ($benchmark->subject as $subject) {
                array_push($grouped[$heading], ...self::rowsForSubject($subject));
            }
        }

        return $grouped;
    }

    /**
     * @return array<int, AggregatedRow>
     */
    private static function rowsForSubject(SimpleXMLElement $subject): array
    {
        $subjectName = (string) $subject['name'];

        $byCondition = [];

        foreach ($subject->variant as $variant) {
            $parameters = self::parameters($variant);
            $driver = $parameters['driver'] ?? null;

            if ($driver === null) {
                continue;
            }

            $condition = self::conditionLabel($subjectName, $parameters);

            $byCondition[$condition][$driver] = [
                'ms' => ((float) $variant->stats['mode']) / 1_000,
                'rstdev' => (float) $variant->stats['rstdev'],
            ];
        }

        $rows = [];

        foreach ($byCondition as $condition => $byDriver) {
            if (! isset($byDriver['strata'], $byDriver['laravelFileDriver'])) {
                continue;
            }

            $rows[] = new AggregatedRow(
                label: $condition,
                kind: RowKind::Duration,
                strata: $byDriver['strata']['ms'],
                laravelFileDriver: $byDriver['laravelFileDriver']['ms'],
                strataVariancePercent: $byDriver['strata']['rstdev'],
                laravelFileDriverVariancePercent: $byDriver['laravelFileDriver']['rstdev'],
            );
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private static function parameters(SimpleXMLElement $variant): array
    {
        $parameters = [];

        foreach ($variant->{'parameter-set'}->parameter as $parameter) {
            $parameters[(string) $parameter['name']] = (string) $parameter['value'];
        }

        return $parameters;
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private static function conditionLabel(string $subjectName, array $parameters): string
    {
        $condition = implode(' ', array_values(array_diff_key($parameters, ['driver' => true])));

        $verb = self::SUBJECT_VERBS[$subjectName] ?? null;

        return $verb === null ? $condition : "{$condition} {$verb}";
    }

    private static function headingFor(string $benchmarkClass): string
    {
        $shortName = substr((string) strrchr($benchmarkClass, '\\'), 1);

        return self::BENCHMARK_HEADINGS[$shortName] ?? $shortName;
    }
}
