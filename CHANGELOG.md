# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

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
