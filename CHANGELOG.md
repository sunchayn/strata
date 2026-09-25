# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.2](https://github.com/sunchayn/strata/compare/v0.1.1...v0.1.2) (2026-09-25)


### Maintenance

* improve wording ([45a921c](https://github.com/sunchayn/strata/commit/45a921cd3c6e402098850bcd0bca077a8839976b))
* reword stale section ([9bd6945](https://github.com/sunchayn/strata/commit/9bd69455e83c311687b7707c5cdf36e31aec62a0))

## [0.1.1](https://github.com/sunchayn/strata/compare/v0.1.0...v0.1.1) (2026-09-24)


### Maintenance

* cleanup dead reference from skeleton ([b93df90](https://github.com/sunchayn/strata/commit/b93df90419e39a952efae6d8d1c08fc87d5a9456))

## 0.1.0 (2026-09-24)

Initial release.

### Added

- A filesystem-based `strata` cache driver, a drop-in replacement for Laravel's built-in `file` driver (`put`, `get`, `many`, `forget`, `flush`, `touch`, `add`).
- Native tag support (`Cache::tags(...)`), with lazy eviction on read.
- Atomic `increment()`/`decrement()`, reading and writing the value under a single held file lock, so concurrent calls on the same key can't lose an update the way Laravel's `file` driver can.
- Atomic locks (`Cache::lock(...)`), stored in their own directory apart from cached values, so a `flush()` never releases a held lock. `flushLocks()` clears them explicitly.
- Atomic `add()`, storing a value only when the key is missing, expired, or its tag was flushed.
- The `strata:prune-stale-tags` Artisan command, for garbage-collecting tag id files that haven't been flushed in a configurable TTL.
- Configurable file permissions, applied to every file and directory Strata creates.
- Support for Laravel 12 and 13, on PHP 8.3+.
