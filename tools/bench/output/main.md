## Strata vs Default Laravel File Cache Benchmarking

| machine | value |
| --- | --- |
| OS | Darwin 25.2.0 (arm64) |
| PHP | 8.4.25 (opcache off) |
| CPU cores | 10 |
| Memory | 24.0 GB |
| illuminate/support | v13.33.0 |

## 1:1 Benchmarking

## Read/write throughput

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| small (~50B) put | 0.124 ms (±7%) | 0.104 ms (±5%) | laravelFileDriver ~16% faster |
| medium (~1KB) put | 0.116 ms (±4%) | 0.108 ms (±3%) | ≈ tie |
| large (~100KB) put | 0.134 ms (±5%) | 0.113 ms (±4%) | laravelFileDriver ~16% faster |
| massive (~10MB) put | 3.803 ms (±4%) | 3.232 ms (±3%) | laravelFileDriver ~15% faster |
| small (~50B) get hit | 0.016 ms (±4%) | 0.020 ms (±2%) | ≈ tie |
| medium (~1KB) get hit | 0.016 ms (±4%) | 0.021 ms (±3%) | ≈ tie |
| large (~100KB) get hit | 0.025 ms (±5%) | 0.030 ms (±3%) | ≈ tie |
| massive (~10MB) get hit | 2.349 ms (±5%) | 3.260 ms (±3%) | strata ~28% faster |
| small (~50B) many x10 | 0.152 ms (±4%) | 0.198 ms (±3%) | strata ~23% faster |
| medium (~1KB) many x10 | 0.150 ms (±2%) | 0.195 ms (±2%) | strata ~23% faster |
| large (~100KB) many x10 | 0.245 ms (±0%) | 0.314 ms (±2%) | strata ~22% faster |
| massive (~10MB) many x10 | 27.267 ms (±3%) | 35.249 ms (±3%) | strata ~23% faster |
| small (~50B) forget | 0.004 ms (±0%) | 0.004 ms (±0%) | ≈ tie |
| medium (~1KB) forget | 0.004 ms (±0%) | 0.005 ms (±0%) | ≈ tie |
| large (~100KB) forget | 0.004 ms (±0%) | 0.004 ms (±0%) | ≈ tie |
| massive (~10MB) forget | 0.004 ms (±0%) | 0.004 ms (±0%) | ≈ tie |
| small (~50B) increment | 0.057 ms (±3%) | 0.059 ms (±2%) | ≈ tie |
| medium (~1KB) increment | 0.057 ms (±4%) | 0.058 ms (±5%) | ≈ tie |
| large (~100KB) increment | 0.057 ms (±7%) | 0.061 ms (±4%) | ≈ tie |
| massive (~10MB) increment | 0.054 ms (±4%) | 0.057 ms (±2%) | ≈ tie |

## Data shape coverage

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| int scalar | 0.020 ms (±4%) | 0.021 ms (±3%) | ≈ tie |
| bool scalar | 0.017 ms (±4%) | 0.021 ms (±4%) | ≈ tie |
| flat array (~50 entries) | 0.019 ms (±4%) | 0.024 ms (±3%) | ≈ tie |
| nested array (~200 leaves) | 0.026 ms (±4%) | 0.031 ms (±3%) | ≈ tie |
| object (~20 properties) | 0.018 ms (±4%) | 0.022 ms (±4%) | ≈ tie |
| multi-byte string (~4KB) | 0.018 ms (±3%) | 0.022 ms (±5%) | ≈ tie |
| eloquent model | 0.026 ms (±4%) | 0.030 ms (±1%) | ≈ tie |
| eloquent collection (200 models) | 1.547 ms (±1%) | 1.541 ms (±1%) | ≈ tie |

## Expiry/eviction read cost vs payload size

| condition | strata | laravelFileDriver | verdict |
| --- | --- | --- | --- |
| 1KB expired miss | 0.008 ms (±6%) | 0.005 ms (±0%) | ≈ tie |
| 100KB expired miss | 0.008 ms (±7%) | 0.005 ms (±0%) | ≈ tie |
| 1MB expired miss | 0.007 ms (±7%) | 0.005 ms (±0%) | ≈ tie |
| 10MB expired miss | 0.008 ms (±5%) | 0.005 ms (±0%) | ≈ tie |

## Summary

Read/write throughput: Strata was faster on 5/20 measurements, laravelFileDriver on 3/20, tied on 12/20.

Data shape coverage: Strata was faster on 0/8 measurements, laravelFileDriver on 0/8, tied on 8/8.

Expiry/eviction read cost vs payload size: Strata was faster on 0/4 measurements, laravelFileDriver on 0/4, tied on 4/4.

## Strata Tagging Benchmarking

Benchmark tagging for Strata for put operations without (baseline) and with tagging.

| condition | mean ms | vs baseline |
| --- | --- | --- |
| small (~50B) no tags put | 0.134 ms (±5%) | baseline |
| small (~50B) 1 tag put | 0.122 ms (±5%) | ~9% faster than no tags |
| small (~50B) 5 tags put | 0.122 ms (±4%) | ~9% faster than no tags |
| medium (~1KB) no tags put | 0.121 ms (±4%) | baseline |
| medium (~1KB) 1 tag put | 0.126 ms (±3%) | ≈ tie with no tags |
| medium (~1KB) 5 tags put | 0.124 ms (±4%) | ≈ tie with no tags |
| large (~100KB) no tags put | 0.132 ms (±2%) | baseline |
| large (~100KB) 1 tag put | 0.130 ms (±3%) | ≈ tie with no tags |
| large (~100KB) 5 tags put | 0.130 ms (±3%) | ≈ tie with no tags |
| massive (~10MB) no tags put | 3.157 ms (±3%) | baseline |
| massive (~10MB) 1 tag put | 3.171 ms (±2%) | ≈ tie with no tags |
| massive (~10MB) 5 tags put | 3.177 ms (±2%) | ≈ tie with no tags |
| small (~50B) no tags get hit | 0.017 ms (±1%) | baseline |
| small (~50B) 1 tag get hit | 0.017 ms (±1%) | ≈ tie with no tags |
| small (~50B) 5 tags get hit | 0.018 ms (±1%) | ≈ tie with no tags |
| medium (~1KB) no tags get hit | 0.017 ms (±1%) | baseline |
| medium (~1KB) 1 tag get hit | 0.018 ms (±4%) | ≈ tie with no tags |
| medium (~1KB) 5 tags get hit | 0.018 ms (±2%) | ≈ tie with no tags |
| large (~100KB) no tags get hit | 0.025 ms (±2%) | baseline |
| large (~100KB) 1 tag get hit | 0.027 ms (±2%) | ≈ tie with no tags |
| large (~100KB) 5 tags get hit | 0.028 ms (±1%) | ≈ tie with no tags |
| massive (~10MB) no tags get hit | 2.456 ms (±3%) | baseline |
| massive (~10MB) 1 tag get hit | 2.361 ms (±3%) | ~4% faster than no tags |
| massive (~10MB) 5 tags get hit | 2.405 ms (±3%) | ≈ tie with no tags |
| small (~50B) cache miss | 0.006 ms (±0%) | baseline |
| small (~50B) tag miss | 0.005 ms (±0%) | ≈ tie with cache miss |
| medium (~1KB) cache miss | 0.006 ms (±8%) | baseline |
| medium (~1KB) tag miss | 0.005 ms (±0%) | ≈ tie with cache miss |
| large (~100KB) cache miss | 0.007 ms (±0%) | baseline |
| large (~100KB) tag miss | 0.005 ms (±0%) | ≈ tie with cache miss |
| massive (~10MB) cache miss | 0.007 ms (±0%) | baseline |
| massive (~10MB) tag miss | 0.005 ms (±0%) | ≈ tie with cache miss |
