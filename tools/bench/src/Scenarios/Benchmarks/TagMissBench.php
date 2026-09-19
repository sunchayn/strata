<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Scenarios\Benchmarks;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use PhpBench\Attributes as Bench;
use Sunchayn\Strata\Bench\Support\Environment;

/**
 * Cost of reading a key whose tag was flushed, at growing payload sizes, against a plain cache miss.
 * A tag flush only marks the tag, so this reads the same lazy-eviction path as an expired key.
 */
#[Bench\ParamProviders(['payloadSizes'])]
#[Bench\Groups(['tags'])]
final class TagMissBench
{
    private const array PAYLOAD_SIZES = [
        'small (~50B)' => 50,
        'medium (~1KB)' => 1_024,
        'large (~100KB)' => 100 * 1_024,
        'massive (~10MB)' => 10 * 1_024 * 1_024,
    ];

    private Repository $repository;

    private string $value;

    private string $key;

    /**
     * A tag flush only marks the tag, the value is evicted on the next read, and deleted right there.
     * So a fresh key is tagged and flushed before every timed rev, or later revs would read an already-deleted key instead.
     */
    #[Bench\BeforeMethods(['primeTagMiss'])]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    #[Bench\Iterations(14)]
    public function benchTagMissGet(): void
    {
        $this->repository->get($this->key);
    }

    /**
     * The baseline for benchTagMissGet(), reading a key that was never written at all.
     */
    #[Bench\BeforeMethods(['primeCacheMiss'])]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    #[Bench\Iterations(14)]
    public function benchCacheMiss(): void
    {
        $this->repository->get($this->key);
    }

    /**
     * @param  array{payload: string}  $params
     */
    public function primeTagMiss(array $params): void
    {
        $store = Environment::singleton()->store('strata');

        $this->value = str_repeat('a', self::PAYLOAD_SIZES[$params['payload']]);
        $this->key = 'tag-miss-'.Str::random(8);

        $store->tags(['flushable-tag'])->put($this->key, $this->value, 3600);
        $store->tags(['flushable-tag'])->flush();

        $this->repository = $store;
    }

    public function primeCacheMiss(): void
    {
        $this->repository = Environment::singleton()->store('strata');
        $this->key = 'missing-'.Str::random(8);
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
