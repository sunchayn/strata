<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Lock;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Modules\Lock\StrataLock;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataLock::class)]
final class StrataLockFunctionalTest extends TestCase
{
    public function test_it_stores_the_expiry_and_the_owner_as_plain_text(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $acquired = $strataStore->lock('orders', 60, 'owner-1')->get();

        // Assert

        $this->assertTrue($acquired);

        $this->assertSame(
            (now()->getTimestamp() + 60)."\nowner-1",
            file_get_contents($this->lockFilepath('orders')),
        );
    }

    #[DataProvider('foreverSecondsProvider')]
    public function test_it_keeps_a_lock_forever_when_the_seconds_are_zero_or_too_long(int $seconds): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->lock('orders', $seconds, 'owner-1')->get();

        // Assert

        $this->assertSame(
            StrataStore::FOREVER_TTL."\nowner-1",
            file_get_contents($this->lockFilepath('orders')),
        );
    }

    /**
     * @return Generator<string, array{seconds: int}>
     */
    public static function foreverSecondsProvider(): Generator
    {
        yield 'zero seconds' => [
            'seconds' => 0,
        ];

        yield 'seconds beyond the limit' => [
            'seconds' => 99_999_999_999,
        ];
    }

    public function test_it_does_not_acquire_a_lock_that_is_still_held(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        $contentsBefore = file_get_contents($this->lockFilepath('orders'));

        // Act

        $acquired = $strataStore->lock('orders', 60, 'owner-2')->get();

        // Assert

        $this->assertFalse($acquired);

        $this->assertSame($contentsBefore, file_get_contents($this->lockFilepath('orders')));
    }

    public function test_it_acquires_a_lock_after_it_expired(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        $this->travel(60)->seconds();

        // Act

        $acquired = $strataStore->lock('orders', 60, 'owner-2')->get();

        // Assert

        $this->assertTrue($acquired);

        $this->assertSame(
            (now()->getTimestamp() + 60)."\nowner-2",
            file_get_contents($this->lockFilepath('orders')),
        );
    }

    public function test_it_acquires_a_lock_over_a_malformed_file(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        mkdir(dirname($this->lockFilepath('orders')), 0o755, recursive: true);

        file_put_contents($this->lockFilepath('orders'), 'not a lock');

        // Act

        $acquired = $strataStore->lock('orders', 60, 'owner-1')->get();

        // Assert

        $this->assertTrue($acquired);

        $this->assertStringEndsWith("\nowner-1", (string) file_get_contents($this->lockFilepath('orders')));
    }

    public function test_it_releases_a_lock_held_by_the_owner(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $lock = $strataStore->lock('orders', 60, 'owner-1');

        $lock->get();

        // Act

        $released = $lock->release();

        // Assert

        $this->assertTrue($released);

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));

        $this->assertTrue($strataStore->lock('orders', 60, 'owner-2')->get());
    }

    public function test_it_does_not_release_a_lock_held_by_another_owner(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        $contentsBefore = file_get_contents($this->lockFilepath('orders'));

        // Act

        $released = $strataStore->lock('orders', 60, 'owner-2')->release();

        // Assert

        $this->assertFalse($released);

        $this->assertSame($contentsBefore, file_get_contents($this->lockFilepath('orders')));
    }

    public function test_it_does_not_release_a_missing_lock_and_leaves_no_file(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $released = $strataStore->lock('orders', 60, 'owner-1')->release();

        // Assert

        $this->assertFalse($released);

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));
    }

    public function test_it_force_releases_a_lock_held_by_another_owner(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        // Act

        $strataStore->lock('orders', 60, 'owner-2')->forceRelease();

        // Assert

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));
    }

    public function test_it_force_releases_a_missing_lock_without_failing(): void
    {
        // Arrange

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        // Act

        $lock->forceRelease();

        // Assert

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));
    }

    public function test_it_reports_the_owner_of_a_live_lock(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        // Act

        $lock = $strataStore->lock('orders', 60, 'owner-2');

        // Assert

        $this->assertTrue($lock->isLocked());

        $this->assertTrue($lock->isOwnedBy('owner-1'));

        $this->assertFalse($lock->isOwnedByCurrentProcess());
    }

    public function test_it_reports_a_missing_lock_as_not_locked(): void
    {
        // Arrange

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        // Act & Assert

        $this->assertFalse($lock->isLocked());
    }

    public function test_it_reports_an_expired_lock_as_not_locked_and_keeps_its_file(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $lock = $strataStore->lock('orders', 60, 'owner-1');

        $lock->get();

        $this->travel(60)->seconds();

        // Act

        $isLocked = $lock->isLocked();

        // Assert

        $this->assertFalse($isLocked);

        $this->assertFileExists($this->lockFilepath('orders'));
    }

    #[DataProvider('refreshSecondsProvider')]
    public function test_it_refreshes_the_expiry_of_a_lock_held_by_the_owner(?int $seconds, int $expectedSeconds): void
    {
        // Arrange

        $this->freezeTime();

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        $lock->get();

        $this->travel(30)->seconds();

        // Act

        $refreshed = $lock->refresh($seconds);

        // Assert

        $this->assertTrue($refreshed);

        $this->assertSame(
            (now()->getTimestamp() + $expectedSeconds)."\nowner-1",
            file_get_contents($this->lockFilepath('orders')),
        );
    }

    /**
     * @return Generator<string, array{seconds: int|null, expectedSeconds: int}>
     */
    public static function refreshSecondsProvider(): Generator
    {
        yield 'the seconds of the lock' => [
            'seconds' => null,
            'expectedSeconds' => 60,
        ];

        yield 'explicit seconds' => [
            'seconds' => 120,
            'expectedSeconds' => 120,
        ];
    }

    public function test_it_does_not_refresh_a_lock_held_by_another_owner(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        $contentsBefore = file_get_contents($this->lockFilepath('orders'));

        // Act

        $refreshed = $strataStore->lock('orders', 60, 'owner-2')->refresh(120);

        // Assert

        $this->assertFalse($refreshed);

        $this->assertSame($contentsBefore, file_get_contents($this->lockFilepath('orders')));
    }

    public function test_it_does_not_refresh_an_expired_lock(): void
    {
        // Arrange

        $this->freezeTime();

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        $lock->get();

        $this->travel(60)->seconds();

        // Act & Assert

        $this->assertFalse($lock->refresh(60));
    }

    public function test_it_does_not_refresh_a_missing_lock_and_leaves_no_file(): void
    {
        // Arrange

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        // Act

        $refreshed = $lock->refresh(60);

        // Assert

        $this->assertFalse($refreshed);

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));
    }

    public function test_it_does_not_acquire_a_lock_while_another_process_holds_the_file_lock(): void
    {
        // Arrange

        resolve(StrataStore::class)->lock('orders', 60, 'owner-1')->get();

        $handle = fopen($this->lockFilepath('orders'), 'c+');

        flock($handle, LOCK_EX);

        // Act

        $acquired = resolve(StrataStore::class)->lock('orders', 60, 'owner-2')->get();

        // Assert

        $this->assertFalse($acquired);

        flock($handle, LOCK_UN);

        fclose($handle);

        $this->assertFileExists($this->lockFilepath('orders'));
    }

    public function test_it_waits_for_another_process_to_release_the_file_lock_before_releasing_a_lock_held_by_the_owner(): void
    {
        // Arrange

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        $lock->get();

        // A separate process now holds the file's exclusive lock, see holdTheFileLockBriefly() below.
        $holder = $this->holdTheFileLockBriefly($this->lockFilepath('orders'));

        // Act

        // The release() opens the file under a blocking exclusive lock,
        // so this call waits for the other process to release its lock, instead of failing to acquire one straight away.
        $released = $lock->release();

        // Assert

        $this->assertTrue($released);

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));

        proc_close($holder);
    }

    public function test_it_waits_for_another_process_to_release_the_file_lock_before_refreshing_a_lock_held_by_the_owner(): void
    {
        // Arrange

        $lock = resolve(StrataStore::class)->lock('orders', 60, 'owner-1');

        $lock->get();

        // A separate process now holds the file's exclusive lock, see holdTheFileLockBriefly() below.
        $holder = $this->holdTheFileLockBriefly($this->lockFilepath('orders'));

        // Act

        // The refresh() opens the file under a blocking exclusive lock,
        // so this call waits for the other process to release its lock, instead of failing to acquire one straight away.
        $refreshed = $lock->refresh(120);

        // Assert

        $this->assertTrue($refreshed);

        proc_close($holder);
    }

    public function test_it_keeps_an_owner_that_contains_new_lines(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, "line-1\nline-2")->get();

        // Act

        $strataLock = $strataStore->restoreLock('orders', "line-1\nline-2");

        // Assert

        $this->assertTrue($strataLock->isOwnedByCurrentProcess());

        $this->assertTrue($strataLock->release());
    }

    public function test_it_writes_the_lock_file_and_its_directory_with_the_configured_file_permission(): void
    {
        // Arrange

        config(['strata.file_permission' => 0o750]);

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->lock('orders', 60, 'owner-1')->get();

        // Assert

        $this->assertSame(0o750, $this->permissionsOf($this->lockFilepath('orders')));

        $this->assertSame(0o750, $this->permissionsOf(dirname($this->lockFilepath('orders'))));
    }

    /**
     * Spawn a process that holds the file lock briefly, without touching its contents.
     * The caller waits for the "locked" line before acting, so it always finds the lock held.
     *
     * @return resource
     */
    private function holdTheFileLockBriefly(string $filepath)
    {
        $script = <<<'PHP'
            // 1. Take the exclusive lock, without touching the file's contents.
            $handle = fopen($argv[1], 'c+');
            flock($handle, LOCK_EX);

            // 2. Tell the parent process it can now attempt its own blocking acquire.
            echo "locked\n";
            fflush(STDOUT);

            // 3. Give the parent time to reach its own blocking call below, while still holding the lock.
            usleep(30000);

            // 4. Release the lock, which unblocks the parent's call.
            flock($handle, LOCK_UN);
            fclose($handle);
        PHP;

        $holder = proc_open(
            command: [PHP_BINARY, '-r', $script, $filepath],
            descriptor_spec: [1 => ['pipe', 'w']],
            pipes: $pipes,
        );

        // Block here until step 2 above runs, so the caller only proceeds once the lock is held.
        fgets($pipes[1]);

        return $holder;
    }
}
