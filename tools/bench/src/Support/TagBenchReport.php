<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Exception;
use SimpleXMLElement;

/**
 * Reads a PHPBench dump-file result for TagBench and reshapes it into a table.
 * A put or get-hit row is compared against its own "no tags" baseline, a tag-miss row against the cache-miss baseline.
 */
final class TagBenchReport
{
    private const array SUBJECT_VERBS = [
        'benchPut' => 'put',
        'benchGetHit' => 'get hit',
        'benchTagMissGet' => 'tag miss',
        'benchCacheMiss' => 'cache miss',
    ];

    private const string NO_TAGS_LABEL = 'no tags';

    private const string TAG_MISS_VERB = 'tag miss';

    private const string CACHE_MISS_VERB = 'cache miss';

    private const float TIE_THRESHOLD_PERCENT = 3.0;

    /**
     * A gap smaller than this is a tie regardless of its percentage,
     * since a fraction of a millisecond can read as a large relative difference,
     * once both values are themselves close to zero.
     */
    private const float TIE_MINIMUM_ABSOLUTE_MS = 0.01;

    private const array PAYLOAD_ORDER = [
        'small (~50B)' => 0,
        'medium (~1KB)' => 1,
        'large (~100KB)' => 2,
        'massive (~10MB)' => 3,
    ];

    /**
     * A baseline (cache miss) is its own family with tag miss,
     * so the two sort next to each other instead of by their separate PHPBench subjects.
     */
    private const array VERB_FAMILIES = [
        'put' => 0,
        'get hit' => 1,
        'cache miss' => 2,
        'tag miss' => 2,
    ];

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> One [condition, mean, comparison] row per variant, grouped by operation, then payload, baseline first.
     *
     * @throws Exception
     */
    public static function parse(string $dumpFilePath): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents($dumpFilePath));

        $variants = [];

        foreach ($xml->suite->benchmark->subject as $subject) {
            array_push($variants, ...self::variantsForSubject($subject));
        }

        usort($variants, fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));

        $tagCountBaselines = self::tagCountBaselines($variants);
        $missBaselines = self::missBaselines($variants);

        $rows = [];

        foreach ($variants as $variant) {
            $rows[] = [
                $variant['condition'],
                sprintf('%.3f ms (±%d%%)', $variant['ms'], round($variant['rstdev'])),
                self::comparison($variant, $tagCountBaselines, $missBaselines),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{payload: string, verb: string, tagCount: ?string, condition: string, ms: float, rstdev: float}>
     */
    private static function variantsForSubject(SimpleXMLElement $subject): array
    {
        $subjectName = (string) $subject['name'];
        $verb = self::SUBJECT_VERBS[$subjectName] ?? $subjectName;

        $variants = [];

        foreach ($subject->variant as $variant) {
            $parameters = self::parameters($variant);
            $payload = $parameters['payload'] ?? null;

            if ($payload === null) {
                continue;
            }

            $tagCount = $parameters['tagCount'] ?? null;

            $variants[] = [
                'payload' => $payload,
                'verb' => $verb,
                'tagCount' => $tagCount,
                'condition' => $tagCount === null ? "{$payload} {$verb}" : "{$payload} {$tagCount} {$verb}",
                'ms' => ((float) $variant->stats['mode']) / 1_000,
                'rstdev' => (float) $variant->stats['rstdev'],
            ];
        }

        return $variants;
    }

    /**
     * @param  array{payload: string, verb: string, tagCount: ?string, condition: string, ms: float, rstdev: float}  $variant
     * @return array{int, int, int, string}
     */
    private static function sortKey(array $variant): array
    {
        return [
            self::VERB_FAMILIES[$variant['verb']] ?? 99,
            self::PAYLOAD_ORDER[$variant['payload']] ?? 99,
            self::isBaseline($variant) ? 0 : 1,
            $variant['tagCount'] ?? '',
        ];
    }

    /**
     * @param  array{payload: string, verb: string, tagCount: ?string, condition: string, ms: float, rstdev: float}  $variant
     */
    private static function isBaseline(array $variant): bool
    {
        return $variant['verb'] === self::CACHE_MISS_VERB || $variant['tagCount'] === self::NO_TAGS_LABEL;
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
     * @param  array<int, array{payload: string, verb: string, tagCount: ?string, condition: string, ms: float, rstdev: float}>  $variants
     * @return array<string, float> The "no tags" mean, in ms, keyed by "payload|verb".
     */
    private static function tagCountBaselines(array $variants): array
    {
        $baselines = [];

        foreach ($variants as $variant) {
            if ($variant['tagCount'] === self::NO_TAGS_LABEL) {
                $baselines["{$variant['payload']}|{$variant['verb']}"] = $variant['ms'];
            }
        }

        return $baselines;
    }

    /**
     * @param  array<int, array{payload: string, verb: string, tagCount: ?string, condition: string, ms: float, rstdev: float}>  $variants
     * @return array<string, float> The cache-miss mean, in ms, keyed by payload.
     */
    private static function missBaselines(array $variants): array
    {
        $baselines = [];

        foreach ($variants as $variant) {
            if ($variant['verb'] === self::CACHE_MISS_VERB) {
                $baselines[$variant['payload']] = $variant['ms'];
            }
        }

        return $baselines;
    }

    /**
     * @param  array{payload: string, verb: string, tagCount: ?string, condition: string, ms: float, rstdev: float}  $variant
     * @param  array<string, float>  $tagCountBaselines
     * @param  array<string, float>  $missBaselines
     */
    private static function comparison(array $variant, array $tagCountBaselines, array $missBaselines): string
    {
        if (self::isBaseline($variant)) {
            return 'baseline';
        }

        [$baselineMs, $baselineLabel] = $variant['verb'] === self::TAG_MISS_VERB
            ? [$missBaselines[$variant['payload']], self::CACHE_MISS_VERB]
            : [$tagCountBaselines["{$variant['payload']}|{$variant['verb']}"], self::NO_TAGS_LABEL];

        return self::diffLabel($variant['ms'], $baselineMs, $baselineLabel);
    }

    private static function diffLabel(float $ms, float $baselineMs, string $baselineLabel): string
    {
        if ($baselineMs <= 0.0 || abs($ms - $baselineMs) < self::TIE_MINIMUM_ABSOLUTE_MS) {
            return "≈ tie with {$baselineLabel}";
        }

        $diffPercent = abs($ms - $baselineMs) / $baselineMs * 100;

        if ($diffPercent < self::TIE_THRESHOLD_PERCENT) {
            return "≈ tie with {$baselineLabel}";
        }

        return $ms > $baselineMs
            ? sprintf('~%d%% slower than %s', round($diffPercent), $baselineLabel)
            : sprintf('~%d%% faster than %s', round($diffPercent), $baselineLabel);
    }
}
