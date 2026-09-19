<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Scenarios\Benchmarks;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use PhpBench\Attributes as Bench;
use Sunchayn\Strata\Bench\Support\Environment;

/**
 * The cost of tagging cache.
 * This is only applicable for Strata driver.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders(['tagCounts', 'payloadSizes'])]
#[Bench\Revs(30)]
#[Bench\Warmup(5)]
#[Bench\Groups(['tags'])]
final class TagBench
{
    private const array PAYLOAD_SIZES = [
        'small (~50B)' => 50,
        'medium (~1KB)' => 1_024,
        'large (~100KB)' => 100 * 1_024,
        'massive (~10MB)' => 10 * 1_024 * 1_024,
    ];

    private const array TAG_COUNTS = [
        'no tags' => 0,
        '1 tag' => 1,
        '5 tags' => 5,
    ];

    private Repository $repository;

    private string $value;

    private string $primedKey;

    /**
     * @param  array{tagCount: string, payload: string}  $params
     */
    public function setUp(array $params): void
    {
        $store = Environment::singleton()->store('strata');
        $tagCount = self::TAG_COUNTS[$params['tagCount']];

        $this->repository = $tagCount === 0
            ? $store
            : $store->tags(array_map(fn (int $i): string => "tag-{$i}", range(1, $tagCount)));

        $this->value = str_repeat('a', self::PAYLOAD_SIZES[$params['payload']]);

        $this->primedKey = 'primed-'.Str::random(8);
        $this->repository->put($this->primedKey, $this->value, 60);
    }

    public function benchPut(): void
    {
        $this->repository->put(Str::random(20), $this->value, 60);
    }

    public function benchGetHit(): void
    {
        $this->repository->get($this->primedKey);
    }

    /**
     * @return Generator<string, array{tagCount: string}>
     */
    public static function tagCounts(): Generator
    {
        foreach (array_keys(self::TAG_COUNTS) as $label) {
            yield $label => ['tagCount' => $label];
        }
    }

    /**
     * @return Generator<string, array{payload: string}>
     */
    public static function payloadSizes(): Generator
    {
        foreach (array_keys(self::PAYLOAD_SIZES) as $label) {
            yield $label => ['payload' => $label];
        }
    }
}
