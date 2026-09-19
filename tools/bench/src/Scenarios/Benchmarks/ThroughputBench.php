<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Scenarios\Benchmarks;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use PhpBench\Attributes as Bench;
use Sunchayn\Strata\Bench\Support\Environment;

/**
 * The put, get, many, forget, and increment cost, at a few payload sizes.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders(['drivers', 'payloadSizes'])]
#[Bench\Revs(30)]
#[Bench\Warmup(5)]
#[Bench\Groups(['throughput'])]
final class ThroughputBench
{
    private const array PAYLOAD_SIZES = [
        'small (~50B)' => 50,
        'medium (~1KB)' => 1_024,
        'large (~100KB)' => 100 * 1_024,
        'massive (~10MB)' => 10 * 1_024 * 1_024,
    ];

    private Repository $repository;

    private string $value;

    private string $primedKey;

    /**
     * @var array<int, string>
     */
    private array $manyKeys;

    private string $counterKey;

    /**
     * @param  array{driver: string, payload: string}  $params
     */
    public function setUp(array $params): void
    {
        $this->repository = Environment::singleton()->store($params['driver']);
        $this->value = str_repeat('a', self::PAYLOAD_SIZES[$params['payload']]);

        $this->primedKey = 'primed-'.Str::random(8);
        $this->repository->put($this->primedKey, $this->value, 60);

        $this->manyKeys = array_map(
            callback: fn (int $i): string => "many-{$i}-".Str::random(6),
            array: range(1, 10),
        );

        foreach ($this->manyKeys as $manyKey) {
            $this->repository->put($manyKey, $this->value, 60);
        }

        $this->counterKey = 'counter-'.Str::random(8);
        $this->repository->put($this->counterKey, 0, 60);
    }

    public function benchPut(): void
    {
        $this->repository->put(Str::random(20), $this->value, 60);
    }

    public function benchGetHit(): void
    {
        $this->repository->get($this->primedKey);
    }

    public function benchManyX10(): void
    {
        $this->repository->many($this->manyKeys);
    }

    /**
     * A fresh key is written on every rev,
     * since forgetting an already-forgotten key is a different, cheaper operation than forgetting one that still exists.
     */
    #[Bench\BeforeMethods(['setUp', 'primeForget'])]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    #[Bench\Iterations(35)]
    public function benchForget(): void
    {
        $this->repository->forget($this->primedKey);
    }

    public function benchIncrement(): void
    {
        $this->repository->increment($this->counterKey);
    }

    public function primeForget(): void
    {
        $this->primedKey = 'forget-'.Str::random(8);
        $this->repository->put($this->primedKey, $this->value, 60);
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
