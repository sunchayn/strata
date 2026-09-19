## Strata vs stock Laravel cache: concurrency checks

Cache root: /var/folders/9m/34420czx5034zl60z4tt3h_c0000gn/T/strata-bench-95e6dIbd

Averaging every scenario over 5 reps. Override with BENCH_REPS=N. Output format: BENCH_FORMAT=terminal|markdown.

| machine | value |
| --- | --- |
| OS | Darwin 25.2.0 (arm64) |
| PHP | 8.4.25 (opcache off) |
| CPU cores | 10 |
| Memory | 24.0 GB |
| illuminate/support | v13.31.0 |

## add() atomicity under 16 concurrent processes

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| successful add() out of 16 | 1 | 1 | match |
| exactly-once invariant | held 5/5 reps | held 5/5 reps | held (both) |

## Lock contention under 16 concurrent processes

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| held the lock out of 16 | 16 | 16 | match |
| no overlapping holds | held 5/5 reps | held 5/5 reps | held (both) |

## Summary

add() atomicity under 16 concurrent processes: Strata: 1.0/16 add() succeeded on average. LaravelFileDriver: 1.0/16 succeeded on average, over 5 reps. Exactly one is the correct outcome for both.

Lock contention under 16 concurrent processes: Strata held the lock 16.0/16 times on average under contention, laravelFileDriver held it 16.0/16 times, over 5 reps.

