## Strata vs stock Laravel cache benchmark

| machine | value |
| --- | --- |
| OS | Darwin 25.2.0 (arm64) |
| PHP | 8.4.25 (opcache off) |
| CPU cores | 10 |
| Memory | 24.0 GB |
| illuminate/support | v13.31.0 |

## Read/write throughput

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| small (~50B) put | 0.160 ms (±4%) | 0.126 ms (±3%) | laravelFileDriver ~21% faster |
| medium (~1KB) put | 0.148 ms (±6%) | 0.124 ms (±1%) | laravelFileDriver ~16% faster |
| large (~100KB) put | 0.161 ms (±4%) | 0.135 ms (±4%) | laravelFileDriver ~16% faster |
| massive (~10MB) put | 3.473 ms (±5%) | 3.310 ms (±5%) | laravelFileDriver ~5% faster |
| small (~50B) get hit | 0.017 ms (±3%) | 0.021 ms (±2%) | ≈ tie |
| medium (~1KB) get hit | 0.017 ms (±2%) | 0.022 ms (±5%) | ≈ tie |
| large (~100KB) get hit | 0.028 ms (±1%) | 0.033 ms (±1%) | ≈ tie |
| massive (~10MB) get hit | 2.719 ms (±5%) | 3.516 ms (±5%) | strata ~23% faster |
| small (~50B) many x10 | 0.158 ms (±2%) | 0.207 ms (±4%) | strata ~24% faster |
| medium (~1KB) many x10 | 0.160 ms (±1%) | 0.211 ms (±6%) | strata ~24% faster |
| large (~100KB) many x10 | 0.257 ms (±1%) | 0.324 ms (±3%) | strata ~21% faster |
| massive (~10MB) many x10 | 28.541 ms (±1%) | 36.154 ms (±0%) | strata ~21% faster |
| small (~50B) forget | 0.004 ms (±0%) | 0.005 ms (±0%) | ≈ tie |
| medium (~1KB) forget | 0.005 ms (±0%) | 0.005 ms (±0%) | ≈ tie |
| large (~100KB) forget | 0.004 ms (±0%) | 0.005 ms (±0%) | ≈ tie |
| massive (~10MB) forget | 0.005 ms (±0%) | 0.005 ms (±0%) | ≈ tie |
| small (~50B) increment | 0.057 ms (±4%) | 0.059 ms (±3%) | ≈ tie |
| medium (~1KB) increment | 0.058 ms (±3%) | 0.059 ms (±1%) | ≈ tie |
| large (~100KB) increment | 0.058 ms (±2%) | 0.058 ms (±2%) | ≈ tie |
| massive (~10MB) increment | 0.062 ms (±0%) | 0.068 ms (±6%) | ≈ tie |

## Data shape coverage

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| int scalar | 0.017 ms (±3%) | 0.021 ms (±3%) | ≈ tie |
| bool scalar | 0.018 ms (±5%) | 0.022 ms (±5%) | ≈ tie |
| flat array (~50 entries) | 0.020 ms (±4%) | 0.025 ms (±3%) | ≈ tie |
| nested array (~200 leaves) | 0.027 ms (±5%) | 0.031 ms (±3%) | ≈ tie |
| object (~20 properties) | 0.019 ms (±5%) | 0.021 ms (±3%) | ≈ tie |
| multi-byte string (~4KB) | 0.017 ms (±4%) | 0.021 ms (±3%) | ≈ tie |
| eloquent model | 0.025 ms (±2%) | 0.030 ms (±3%) | ≈ tie |
| eloquent collection (200 models) | 1.498 ms (±3%) | 1.491 ms (±2%) | ≈ tie |

## Expiry/eviction read cost vs payload size

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| 1KB expired miss | 0.009 ms (±0%) | 0.005 ms (±0%) | ≈ tie |
| 100KB expired miss | 0.009 ms (±5%) | 0.005 ms (±0%) | ≈ tie |
| 1MB expired miss | 0.009 ms (±5%) | 0.005 ms (±0%) | ≈ tie |
| 10MB expired miss | 0.009 ms (±5%) | 0.005 ms (±0%) | ≈ tie |
| 1KB live hit | 0.017 ms (±4%) | 0.021 ms (±1%) | ≈ tie |
| 100KB live hit | 0.027 ms (±0%) | 0.034 ms (±4%) | ≈ tie |
| 1MB live hit | 0.219 ms (±4%) | 0.309 ms (±5%) | strata ~29% faster |
| 10MB live hit | 2.972 ms (±5%) | 3.521 ms (±5%) | strata ~16% faster |

## Summary

Read/write throughput: Strata was faster on 5/20 measurements, laravelFileDriver on 4/20, tied on 11/20.

Data shape coverage: Strata was faster on 0/8 measurements, laravelFileDriver on 0/8, tied on 8/8.

Expiry/eviction read cost vs payload size: Strata was faster on 2/8 measurements, laravelFileDriver on 0/8, tied on 6/8.

