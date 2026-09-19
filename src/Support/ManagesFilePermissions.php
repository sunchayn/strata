<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Support;

use Illuminate\Filesystem\Filesystem;

trait ManagesFilePermissions
{
    /**
     * Create the directory, then apply the desired permission to every directory that was newly created.
     */
    protected function makeDirectoryWithPermission(
        Filesystem $filesystem,
        string $directory,
        int $filePermission,
    ): void {
        if ($filesystem->exists($directory)) {
            return;
        }

        $parent = dirname($directory);

        if ($parent !== $directory) {
            $this->makeDirectoryWithPermission($filesystem, $parent, $filePermission);
        }

        // We initially make the directory with the widest possible access to mitigate the umask.
        // Once the OS applies the unmask and further narrows the permissions,
        // we can later apply the desired narrow file permissions we are aiming for with chmod.
        $filesystem->makeDirectory($directory, 0o777, force: true);

        $this->setCorrectFilePermission($filesystem, $directory, $filePermission);
    }

    /**
     * Ensure the created path has the correct permissions.
     */
    protected function setCorrectFilePermission(Filesystem $filesystem, string $path, int $filePermission): void
    {
        if (intval($filesystem->chmod($path), 8) === $filePermission) {
            return;
        }

        $filesystem->chmod($path, $filePermission);
    }
}
