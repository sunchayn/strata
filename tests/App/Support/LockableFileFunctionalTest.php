<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use Sunchayn\Strata\Modules\Lock\StrataLockableFile;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataLockableFile::class)]
final class LockableFileFunctionalTest extends TestCase
{
    /**
     * @var array<int, StrataLockableFile|null>
     */
    private array $openedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->openedFiles as $openedFile) {
            $openedFile?->close();
        }

        parent::tearDown();
    }

    public function test_it_creates_a_missing_file(): void
    {
        // Arrange

        $path = "{$this->cacheDirectory}/missing";

        mkdir($this->cacheDirectory);

        // Act

        $file = $this->trackForClosing(StrataLockableFile::openExclusive($path));

        // Assert

        $this->assertInstanceOf(StrataLockableFile::class, $file);

        $this->assertFileExists($path);
    }

    public function test_it_does_not_create_a_missing_file_when_told_not_to(): void
    {
        // Arrange

        $path = "{$this->cacheDirectory}/missing";

        mkdir($this->cacheDirectory);

        // Act

        $file = StrataLockableFile::openExclusive($path, create: false);

        // Assert

        $this->assertNull($file);

        $this->assertFileDoesNotExist($path);
    }

    public function test_it_opens_an_existing_file_when_told_not_to_create(): void
    {
        // Arrange

        $path = $this->createFile('value');

        // Act

        $file = $this->trackForClosing(StrataLockableFile::openExclusive($path, create: false));

        // Assert

        $this->assertInstanceOf(StrataLockableFile::class, $file);
    }

    public function test_it_knows_the_path_still_points_to_the_opened_file(): void
    {
        // Arrange

        $file = $this->trackForClosing(StrataLockableFile::openExclusive($this->createFile('value')));

        // Act & Assert

        $this->assertTrue($file?->isHandleStillValid());
    }

    public function test_it_knows_the_opened_file_was_deleted(): void
    {
        // Arrange

        $path = $this->createFile('value');

        $file = $this->trackForClosing(StrataLockableFile::openExclusive($path));

        unlink($path);

        // Act & Assert

        $this->assertFalse($file?->isHandleStillValid());
    }

    public function test_it_knows_the_opened_file_was_replaced(): void
    {
        // Arrange

        $path = $this->createFile('value');

        $file = $this->trackForClosing(StrataLockableFile::openExclusive($path));

        unlink($path);

        file_put_contents($path, 'replacement');

        // Act & Assert

        $this->assertFalse($file?->isHandleStillValid());
    }

    public function test_it_does_not_open_a_file_that_another_handle_holds_locked(): void
    {
        // Arrange

        $path = $this->createFile('value');

        $this->trackForClosing(StrataLockableFile::openExclusive($path));

        // Act

        $second = $this->trackForClosing(StrataLockableFile::openExclusive($path));

        // Assert

        $this->assertNull($second);
    }

    public function test_it_does_not_open_a_file_that_cannot_be_created(): void
    {
        // Act & Assert

        $this->assertNull(StrataLockableFile::openExclusive($this->cacheDirectory.'/missing-directory/file'));
    }

    public function test_it_replaces_longer_content_with_shorter_content_without_leftovers(): void
    {
        // Arrange

        $path = $this->createFile('a much longer content');

        $file = StrataLockableFile::openExclusive($path);

        // Act

        $file?->overwrite('short');

        $file?->close();

        // Assert

        $this->assertSame('short', file_get_contents($path));
    }

    public function test_it_replaces_the_content_after_the_stream_was_read(): void
    {
        // Arrange

        $path = $this->createFile("first\nsecond");

        $file = StrataLockableFile::openExclusive($path);

        fgets($file?->handle());

        // Act

        $file?->overwrite('new');

        $file?->close();

        // Assert

        $this->assertSame('new', file_get_contents($path));
    }

    public function test_it_releases_the_lock_when_closed(): void
    {
        // Arrange

        $path = $this->createFile('value');

        StrataLockableFile::openExclusive($path)?->close();

        // Act

        $second = $this->trackForClosing(StrataLockableFile::openExclusive($path));

        // Assert

        $this->assertInstanceOf(StrataLockableFile::class, $second);
    }

    public function test_it_can_be_closed_twice(): void
    {
        // Arrange

        $file = StrataLockableFile::openExclusive($this->createFile('value'));

        $file?->close();

        // Act & Assert

        $file?->close();

        $this->assertInstanceOf(StrataLockableFile::class, $file);
    }

    /*
     * Helpers.
     */

    private function createFile(string $contents): string
    {
        mkdir($this->cacheDirectory);

        $path = $this->cacheDirectory.'/file';

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Queue the given file to be closed in tearDown().
     */
    private function trackForClosing(?StrataLockableFile $file): ?StrataLockableFile
    {
        $this->openedFiles[] = $file;

        return $file;
    }
}
