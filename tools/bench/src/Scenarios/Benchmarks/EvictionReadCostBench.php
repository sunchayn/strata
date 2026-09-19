<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Scenarios\Benchmarks;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use PhpBench\Attributes as Bench;
use Sunchayn\Strata\Bench\Support\Environment;

/**
 * Cost of reading an already-expired key, at growing payload sizes.
 * Strata reads only the header line before it decides a key is a miss.
 * Laravel's file driver reads the whole file first, so its cost should grow with payload size.
 */
#[Bench\ParamProviders(['drivers', 'payloadSizes'])]
#[Bench\Groups(['eviction'])]
final class EvictionReadCostBench
{
    private const array PAYLOAD_SIZES = [
        '1KB' => 1_024,
        '100KB' => 100 * 1_024,
        '1MB' => 1_024 * 1_024,
        '10MB' => 10 * 1_024 * 1_024,
    ];

    private Repository $repository;

    private string $value;

    private string $key;

    /**
     * An already-expired key is deleted from disk the moment it is read,
     * so a fresh one is written before every timed rev, or later revs would measure a plain missing key instead.
     */
    #[Bench\BeforeMethods(['primeExpired'])]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    #[Bench\Iterations(14)]
    public function benchExpiredMiss(): void
    {
        $this->repository->get($this->key);
    }

    #[Bench\BeforeMethods(['primeLive'])]
    #[Bench\Revs(12)]
    #[Bench\Warmup(2)]
    public function benchLiveHit(): void
    {
        $this->repository->get($this->key);
    }

    /**
     * @param  array{driver: string, payload: string}  $params
     */
    public function primeExpired(array $params): void
    {
        $this->repository = Environment::shared()->store($params['driver']);
        $this->value = str_repeat('a', self::PAYLOAD_SIZES[$params['payload']]);
        $this->key = 'expired-'.Str::random(8);

        $this->repository->put($this->key, $this->value, -5);
    }

    /**
     * @param  array{driver: string, payload: string}  $params
     */
    public function primeLive(array $params): void
    {
        $this->repository = Environment::shared()->store($params['driver']);
        $this->value = str_repeat('a', self::PAYLOAD_SIZES[$params['payload']]);
        $this->key = 'live-'.Str::random(8);

        $this->repository->put($this->key, $this->value, 3600);
    }

    /**
     * @return Generator<string, array{driver: string}>
     */
    public static function drivers(): Generator
    {
        yield 'strata' => ['driver' => 'strata'];
        yield 'laravelFileDriver' => ['driver' => 'laravelFileDriver'];
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
