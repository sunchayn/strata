<br />
<img src="art/strata-logo.png" width="220"/>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sunchayn/strata.svg?style=flat-square)](https://packagist.org/packages/sunchayn/strata)
[![License](https://img.shields.io/packagist/l/sunchayn/strata.svg?style=flat-square)](https://packagist.org/packages/sunchayn/strata)
[![PHP Version](https://img.shields.io/packagist/php-v/sunchayn/strata.svg?style=flat-square)](https://packagist.org/packages/sunchayn/strata)
[![codecov](https://codecov.io/github/sunchayn/strata/graph/badge.svg?token=IPMYSPI2T4)](https://codecov.io/github/sunchayn/strata)
<a href="https://packagist.org/packages/sunchayn/strata"><img src="https://badge.laravel.cloud/badge/sunchayn/strata?style=flat" alt="Laravel versions"></a>

**A feature complete Laravel filesystem cache with tagged cache support.**

---

## Why Strata?

Laravel's built-in `file` cache driver has no tag support. Needing tags, even on a small app or a single server, means opting in for a different driver or having some per-env logic to interact with the cache differently. Strata closes this gap, it is a drop-in replacement for the default drive with tags support.

## Key Features

- **Drop-in Laravel cache API.** Same API as the Laravel's default file driver.
- **Tag support.** Tags are supported natively.
- **Isolated Atomic locks.** `Cache::lock()` support backed by non-blocking file locks, stored apart from cache system, thus, a flush never releases a lock.
- **Safe concurrent access.** Reads and writes are safe across multiple PHP processes sharing the same cache directory.
- **Configurable file permissions.** Every file and directory Strata creates gets a permission you choose, useful for certain deploys.
- **Better performance in many cases**, particularly reads and bulk lookups. See [`tools/bench/output`](tools/bench/output) for the full benchmark.

## Installation

You can install the package via Composer:

```bash
composer require sunchayn/strata
```

You may publish all the package's resources at once with:

```bash
php artisan vendor:publish --tag="strata"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="strata-config"
```

## Requirements

- PHP 8.3 or higher.
- Laravel 12 or 13.

## Setup

Add a store that uses the `strata` driver to `config/cache.php`.

```php
'stores' => [
    'strata' => [
        'driver' => 'strata',
    ],
],
```

Then select it as the default store in your `.env` file, or use it by name in your code.

```dotenv
CACHE_STORE=strata
```

## Usage

Strata follows the Laravel cache API.

```php
use Illuminate\Support\Facades\Cache;

Cache::store('strata')->put('user:1', $user, 600);
Cache::store('strata')->get('user:1');
Cache::store('strata')->forget('user:1');
```

### Tags

Unlike the Laravel `file` driver, Strata supports tags.

A tag lets you group cache entries so you can invalidate them all at once, instead of tracking every individual key that needs to expire together. Attach the same tag to any number of values, then flush that one tag when the underlying data changes.

```php
Cache::store('strata')->tags(['books', 'catalog'])->put('books:1', $book, 600);

Cache::store('strata')->get('books:1');

Cache::store('strata')->tags(['books'])->flush();
```

_Note: Tag names must be valid UTF-8._

#### How tagging and eviction work

Strata does not run anything in the background to keep the cache clean. Everything happens in two steps, both triggered by a normal read.

1. **A flush marks, it does not delete.** `tags(['books'])->flush()` only marks the tag `books` as flushed. None of the value files stored under it are touched at that moment.
2. **A read checks, then evicts.** Every read first checks the entry's expiration and its tags. If the entry is expired, or one of its tags was flushed since the value was written, the read returns a miss and deletes that file right there.

_Note: this design is similar to how the default file driver evicts expired values. It is on read._

**Cached value structure**

| Part | Content | Used for |
| --- | --- | --- |
| Line 1 | Expiration time | The expiration check on read. |
| Line 2 | Tags, at the time the value was written | The tag check on read. |
| Rest of the file | The cached value itself | Returned to you on a hit. |

#### Scheduling the tag files cleanup

Strata keeps one small file for each tag you have ever used, and never removes it on its own. Schedule the prune command to remove old tag files by adding this to `routes/console.php`.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('strata:prune-stale-tags')->daily();
```

The command will delete any tag that wasn't re-flushed in the last `config('strata.tag_gc_ttl)` seconds.

### Atomic locks

Strata supports `Cache::lock()`. Locks are stored in a separate `locks` directory, so `Cache::flush()` does not release them. 

You can use `Cache::flushLocks()` to flush all locks them.

```php
Cache::store('strata')->lock('import', 10)->get(function () {
    // ...
});
```

### Atomic Operations

Strata supports `cache::add()`, similar to the default file driver, to add an item if it doesn't exist.

It also provides atomic operations support to the following methods:
- increment
- decrement

```php
Cache::store('strata')->increment('views');
Cache::store('strata')->decrement('stock', 5);
```

## Configuration

Publish the configuration file, then edit `config/strata.php`.

| Key | Default | Purpose |
| --- | --- | --- |
| `directory` | `storage/framework/cache/strata` | Root directory of all Strata files. |
| `tag_gc_ttl` | One month, in seconds | Age after which the prune command deletes a tag file. |
| `file_permission` | `0o755` | Permission (octal value) of every file and directory Strata creates. |

## How it works

Read the [Cache module documentation](src/Modules/Cache/README.md) for the full architecture, the on-disk file layout, and the algorithms behind tags, locks, and concurrency.

## Links

- [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.
- [Contributing Guide](.github/CONTRIBUTING.md) Thank you for considering contributing to Strata!
- Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## License

Strata is open-sourced software licensed under the [MIT license](LICENSE.md).
