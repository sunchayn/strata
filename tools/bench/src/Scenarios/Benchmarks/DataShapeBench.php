<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Scenarios\Benchmarks;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpBench\Attributes as Bench;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use stdClass;
use Sunchayn\Strata\Bench\Support\BenchUser;
use Sunchayn\Strata\Bench\Support\Environment;

/**
 * The get() cost for different shapes other than a flat string.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders(['drivers', 'shapes'])]
#[Bench\Revs(1)]
#[Bench\Warmup(0)]
#[Bench\Iterations(35)]
#[Bench\Groups(['data-shapes'])]
final class DataShapeBench
{
    private Repository $repository;

    private mixed $value;

    private string $key;

    /**
     * @param  array{driver: string, shape: string}  $params
     *
     * @throws InvalidArgumentException
     */
    public function setUp(array $params): void
    {
        $this->repository = Environment::singleton()->store($params['driver']);
        $this->value = $this->shapeValue($params['shape']);

        $checkKey = 'shape-check-'.Str::random(8);

        $this->repository->put($checkKey, $this->value, 60);

        if (! $this->valuesMatch($this->value, $this->repository->get($checkKey))) {
            throw new RuntimeException("Value shape \"{$params['shape']}\" did not round-trip correctly on the \"{$params['driver']}\" driver.");
        }

        $this->key = 'shape-'.Str::random(8);

        $this->repository->put($this->key, $this->value, 60);
    }

    public function benchGet(): void
    {
        $this->repository->get($this->key);
    }

    /**
     * @return Generator<string, array{driver: string}>
     */
    public static function drivers(): Generator
    {
        yield 'strata' => ['driver' => 'strata'];
        yield 'laravelFileDriver' => ['driver' => 'laravelFileDriver'];
    }

    private const array SHAPE_LABELS = [
        'int scalar',
        'bool scalar',
        'flat array (~50 entries)',
        'nested array (~200 leaves)',
        'object (~20 properties)',
        'multi-byte string (~4KB)',
        'eloquent model',
        'eloquent collection (200 models)',
    ];

    /**
     * The shape labels only,
     * so collecting them for every driver/shape combination never has to build the shapes themselves,
     * only the one setUp() actually asks for.
     *
     * @return Generator<string, array{shape: string}>
     */
    public static function shapes(): Generator
    {
        foreach (self::SHAPE_LABELS as $label) {
            yield $label => ['shape' => $label];
        }
    }

    private function shapeValue(string $label): mixed
    {
        return match ($label) {
            'int scalar' => 123_456_789,
            'bool scalar' => true,
            'flat array (~50 entries)' => $this->flatArray(),
            'nested array (~200 leaves)' => $this->nestedArray(),
            'object (~20 properties)' => $this->object(),
            'multi-byte string (~4KB)' => str_repeat('日本語テスト', 300),
            'eloquent model' => $this->user(1),
            'eloquent collection (200 models)' => new Collection(array_map($this->user(...), range(1, 200))),
            default => throw new RuntimeException("Unknown shape label \"{$label}\"."),
        };
    }

    private function user(int $seed): BenchUser
    {
        return new BenchUser([
            'name' => "User {$seed}",
            'email' => "user-{$seed}@example.com",
            'email_verified_at' => Carbon::now(),
            'password' => '$2y$10$verylongpasswordfordummymodels',
            'remember_token' => Str::random(10),
        ]);
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function flatArray(): array
    {
        $entries = [];

        for ($i = 0; $i < 50; $i++) {
            $entries["key-{$i}"] = match ($i % 5) {
                0 => $i,
                1 => $i / 3,
                2 => "value-{$i}",
                3 => $i % 2 === 0,
                default => null,
            };
        }

        return $entries;
    }

    /**
     * @return array<string, array<string, array<string, int|float|string|bool>>>
     */
    private function nestedArray(): array
    {
        $nested = [];

        for ($section = 0; $section < 4; $section++) {
            for ($group = 0; $group < 5; $group++) {
                $leaves = [];

                for ($i = 0; $i < 10; $i++) {
                    $leaves["leaf-{$i}"] = $this->leaf($section * 50 + $group * 10 + $i);
                }

                $nested["section-{$section}"]["group-{$group}"] = $leaves;
            }
        }

        return $nested;
    }

    private function leaf(int $seed): int|float|string|bool
    {
        return match ($seed % 4) {
            0 => $seed,
            1 => "leaf-{$seed}",
            2 => $seed / 7,
            default => $seed % 2 === 0,
        };
    }

    private function object(): stdClass
    {
        $object = new stdClass;

        for ($i = 0; $i < 20; $i++) {
            $object->{"property_{$i}"} = match ($i % 4) {
                0 => $i,
                1 => "value-{$i}",
                2 => $i / 2,
                default => $i % 2 === 0,
            };
        }

        return $object;
    }

    /**
     * An unserialized object is never the same instance, so it is compared by its properties instead.
     */
    private function valuesMatch(mixed $original, mixed $roundTripped): bool
    {
        return is_object($original) ? $original == $roundTripped : $original === $roundTripped;
    }
}
