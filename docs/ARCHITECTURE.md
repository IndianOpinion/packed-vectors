# packed-vectors: architecture

This document describes how the library is put together and records every
design decision, including the answers to the open questions in section 13
of [PRD.md](PRD.md). Numbers come from `benchmarks/bench.php` and the test
suite, run on PHP 8.3.6 without JIT on a shared 2-core server that was under
load (load average about 4) at the time, so treat absolute timings as
pessimistic and the relative ones as indicative.

## Modules

| Module | Role |
|---|---|
| `Format` | Format constants and the size arithmetic. No I/O. |
| `Builder` | Atomic rebuild: directory check, lock, stale-temporary cleanup, temporary file, streaming pack and CRC, header, fsync, rename, directory fsync. `rebuildIfStale()` and `staleReason()`. |
| `Scanner` | Opens one file, checks the header against `fstat`, streams the payload in chunks, scores, keeps the top-k, checks the payload CRC. Also `info()` and `verify()`. |
| `ChunkedScanner` | The fallback: same checks, scoring and ranking over caller pages fetched with keyset pagination. |
| `BuildOptions`, `ScanOptions` | Immutable, validated settings with with-ers. |
| `BuildReport`, `ScanReport`, `ScanResult`, `Hit`, `FileInfo`, `VerifyReport`, `Record` | Read-only values. |
| `Environment` | The 64-bit guard. |
| `Cli` (internal) | `bin/packed-vectors info` and `verify`. |
| `Internal\Header` | Encodes and checks the 40-byte header. |
| `Internal\Vectors` | Record and query validation, norms, query normalisation, the `k` bound. |
| `Internal\TopK` | Bounded min-heap with tie-breaking and the best-per-ref map. |
| `Internal\Errors` | Runs a filesystem call with a temporary error handler and keeps the warning text, instead of using `@`. |
| `Exception\*` | One class per failure, all implementing `PackedVectorsError`; the "fall back and rebuild" family also implements `UnavailableException`. |

`bootstrap.php` loads the guard, runs it, and registers a PSR-4 style
autoloader for use without Composer.

## Data flow

Build:

```
caller iterable
  -> validate record (bad: skip and count by reason, or throw)
  -> normalise in double precision (when on)
  -> pack('VVg*') into a 1 MiB buffer
  -> checked fwrite to <target>.<16 hex>.tmp, hash_update(crc32b)
  -> seek 0, write the real header, fflush, fsync, fclose, size check
  -> rename(tmp, target), fsync(directory)
  -> BuildReport
```

Scan:

```
fopen -> fstat(handle) -> read 40 bytes
  -> Header::decode: magic, version, header CRC, reserved bits, dim, exact size
  -> for each chunk of whole records: read until complete, hash_update
       -> for each block: one unpack('g' . n) with 1-based offsets
            -> for each record: dot product (plus norm if unnormalised), clamp,
               floor and minScore check, decode id and ref only if needed,
               TopK::offer, budget check every 256 records
  -> payload CRC check
  -> ScanResult
```

## Decisions

Each entry gives the decision and the reason. Entries marked "ruling" were
decided by the project owner; the rest were decided by the builder.

### D1. Top-k structure: bounded binary min-heap (ruling; PRD question 1)

`Internal\TopK` keeps at most `k` entries in a binary min-heap held in three
parallel arrays (score, id, ref) whose root is the worst kept hit. The
scanner keeps the root's score in a local `$floor` and rejects most
candidates with one float comparison and no method call. Plain arrays rather
than an `SplMinHeap` subclass avoid a `compare()` callback per sift step.

Measured over 100,000 random scores (best of 5):

| k | min-heap | sorted array, binary insertion |
|---|---|---|
| 10 | 0.0026 s | 0.0059 s |
| 1,000 | 0.0650 s | 0.0613 s |

The heap is twice as fast at the common small `k` and level at `k = 1,000`;
both are noise next to the dot products (about 0.8 s for 10,000 x 512). The
heap's bound holds for any `k`, which is what the ruling asked for.

Ordering is higher score first, then lower id, then lower ref, so results
are deterministic for a given file and query.

### D2. Best per ref inside the heap

In best-per-ref mode the heap also maps each kept ref to its slot. A better
record for a kept ref replaces that entry in place and sifts down; a worse
one is dropped; an eviction removes the ref from the map. Memory stays
bounded by `k`, and the result is exact: an evicted entry was below the cut,
so any later record with that ref and a lower score is below the cut too.
Verified against a sort-everything reference on 1,000 random files with
many exact ties.

### D3. Unpack strategy: per chunk, with 1-based offset arithmetic (ruling; PRD question 2)

Each read chunk is decoded with one `unpack('g' . n, $chunk, $offset)` per
block of whole records. The id and ref words are decoded as floats too and
simply skipped: record `r` of a block has its first value at key
`r * (dim + 2) + 3` and its last at `r * (dim + 2) + dim + 2`, because
`unpack()` keys start at 1. The id and ref are decoded with `unpack('V2')`
only for the few records that beat the heap floor.

Blocks are capped at 32,768 values (`Scanner::UNPACK_BLOCK_VALUES`). A whole
4 MiB chunk unpacked at once would be a one-million-element array, about
32 MiB on PHP 8.1 and 16 MiB on 8.2 and later, which breaks the 16 MiB scan
budget. The cap keeps the decoded array near 1 MiB whatever `chunkBytes` is.

Measured over 2,000 records x 512 dims in memory (best of 5, relative to the
shipped loop):

| Strategy | Time | Change |
|---|---|---|
| Block unpack, 1-based offsets, `for` loop (shipped) | 0.1427 s | 0 |
| Block unpack, `foreach` over the query | 0.1613 s | +13.1% |
| Per-record unpack + `array_values()` | 0.1762 s | +23.5% |
| Per-record unpack, 1-keyed query, `foreach` | 0.1493 s | +4.6% |

The one-based trap is documented in the code, the README and a test that
proves the trap exists (`unpack()` output has no key 0) and that vectors
whose only signal is in the first or last slot score exactly 1.0 against a
matching query, in both normalised and unnormalised files, at chunk sizes
that put records at many offsets.

### D4. No loop unrolling (ruling; PRD question 3)

The dot product is a plain loop. Measured on the same data:

| Loop | Time | Change |
|---|---|---|
| Plain (shipped) | 0.1427 s | 0 |
| Unrolled by 4 | 0.1211 s | -15.1% |
| Unrolled by 8 | 0.1523 s | +6.7% |

Unrolling by 4 sat right at the PRD's 15 percent threshold on a loaded
machine, and unrolling by 8 was slower. A gain that small and that unstable
does not pay for the extra code and the tail loop, and it can vanish with
JIT. Re-measure with `php benchmarks/bench.php` before revisiting.

### D5. fsync the file, then the directory after the rename (ruling; PRD question 4)

With `fsync` on (the default) the builder calls `fflush()` and `fsync()` on
the temporary file before closing it; a failed `fsync` is a build failure.
After the rename it opens the directory read-only and calls `fsync()` on it,
so the rename itself survives a power cut. On Linux, PHP can open and fsync a
directory (checked locally; the default-options build test exercises both
syncs on every CI run). The directory
sync is best effort: by then the new file is already in place, so a platform
that cannot open or sync a directory (Windows) does not fail the build.
`fsync()` exists from PHP 8.1, the minimum version.

### D6. 64-bit ids later, through the version field (ruling; PRD question 5)

0.1.0 writes and reads format version 1 only, with unsigned 32-bit ids and
refs. Ids above 4,294,967,295 are skipped as `bad_id`, never truncated. The
version field is the extension point: a future version 2 would carry
unsigned 64-bit id and ref fields (record size `16 + 4 * dim`) and bump the
version. Version 1 code refuses any other version with
`UnsupportedVersionException` before reading the layout, and refuses unknown
flag bits and a non-zero reserved field as corrupt, so a newer file can never
be misread as an older one. No flag bit is spent on id width.

### D7. Time budget checked every 256 records (ruling; PRD question 6)

`Scanner::BUDGET_CHECK_EVERY = 256`, capped by
`Scanner::BUDGET_CHECK_VALUES = 65536` values, so the interval is
`max(1, min(256, intdiv(65536, dim)))` records (`budgetCheckInterval()`):
256 at small dims, 128 at 512, 1 at 65,535. Without the cap a file of fewer
than 256 records with a large dim was never checked at all. The check is one
modulo per record plus one `hrtime()` call per interval, and only when a
budget is set. In the
benchmark a scan with a budget was not measurably slower than one without
(0.747 s against 0.843 s, inside the noise). The budget is measured from the
start of `top()`, so it includes opening the file. `ChunkedScanner` checks at
the same interval and after every page. A scan over budget throws
`BudgetExceededException` with the records scanned so far and never returns
partial results.

### D8. A dimension change needs a full rebuild (ruling; PRD question 7)

`rebuildIfStale()` rebuilds when the file's `dim` differs from the
builder's, and also when its normalisation flag differs from the builder's
option, because both mean the file no longer matches what the application
expects. The scanner refuses a query of another length with
`DimensionMismatchException` (an `UnavailableException`), carrying both
numbers, and never truncates or pads. After a model change, the rebuild
skips every old-dimension row as `length` and writes a valid empty file;
`staleReason()` says why a rebuild is due.

### D9. Lock file beside the target; unwritable directory is a typed exception (ruling; PRD question 8)

The lock is `<target>.lock` in the target's directory, opened with mode `c`
(create, never truncate) and locked with `flock(LOCK_EX | LOCK_NB)`. It is
never the data file and is left in place. Only builders take it; scanners
never create, open or lock it, so the web server user needs no write access
to the directory. If the directory is missing or not writable, the build
throws `DirectoryNotWritableException` (a `BuildFailedException`) before the
lock is taken and before the source is touched. The builder never falls back
to a system temporary directory, because a rename across filesystems is a
copy that readers can see half done.

With `lockWaitSeconds > 0` the builder polls the lock every 50 ms or less
until the deadline. `rebuildIfStale()` calls the records factory only after
the lock is held, and re-checks freshness once it holds it, so a builder that
waited for another one does not rebuild a file that has just been rebuilt.

### D10. Header check order

`magic`, then `version`, then the header CRC, then reserved field and flag
bits, then `dim`, then the exact file size. The version comes before the CRC
so that a newer layout is reported as unsupported rather than judged by
version 1 rules. `count` is bounded by the size available before it is
multiplied, so a forged header cannot overflow the arithmetic or cause a
large allocation. A file shorter than the header is `size`.

### D11. Extra failure reasons

Beyond the PRD's list, `CorruptFileException` also uses `dim` (header dim of
0 or above 65,535 with a valid header CRC) and `non_finite` (a NaN or
infinite stored value met during a scan, which a version 1 builder never
writes). Build skip reasons add `not_list` (the vector is not a PHP list, or
not an array at all) and `not_numeric` (a value that is not an int or a
float, such as a numeric string). Every reason appears in
`BuildReport::skipped()` with zero when unused, so log lines are stable.

### D12. Record shape errors abort; bad values follow the policy

An item that is neither a `Record` nor a three-element list is a caller bug,
not a bad row, so it aborts the build with `InvalidArgumentException` (after
the usual cleanup). Bad ids and bad vectors follow `onBadRecord`. With
`throw`, the `InvalidVectorException` is thrown as is, with the record's
index and id, and is not wrapped in `BuildFailedException`; source
exceptions are wrapped, with the original as `previous`. `Record` itself
does not validate, so the policy applies to it too.

### D13. Norms with scaling

Norms are computed in double precision as `max * sqrt(sum((v / max)^2))`,
so values near the float32 limit do not overflow and tiny values do not
underflow. The query is normalised once; stored vectors are normalised at
build time when `normalise` is on. An unnormalised file accumulates each
stored vector's norm in the same pass as the dot product.

### D14. Reads, short reads and buffers

The scanner disables PHP's read buffer on the handle
(`stream_set_read_buffer($h, 0)`), so `info()` reads exactly 40 bytes and
large reads go straight to the file. It sets the stream chunk size to 40 for
the header and to the read chunk for the payload, which also makes stream
wrappers see the same request sizes. A read loops until the whole chunk has
arrived, so a short read is never trusted to end on a record boundary; end of
file first is `short_read` (or `size` in the header). The size is checked
from `fstat` on the open handle before any payload is read.

### D15. `verify()` reports payload problems and throws on header problems

A file whose header or size is wrong cannot be interpreted, so `verify()`
throws as `info()` does. Payload problems (CRC mismatch, NaN or infinite
values) are reported in `VerifyReport` with the counts and the first bad
record. The CLI turns either into exit code 1.

### D16. Test seams on `Builder` and `Scanner`

The PRD asks for every class to be final unless noted. `Builder` and
`Scanner` are not final, so tests can override small protected seams
(`temporaryPath()`, `openTemporaryFile()`, `afterOpen()`, `afterChunk()`)
to force a temporary name, inject a failing stream, or rename and unlink
files at exact moments. The seams are marked `@internal`; treat both classes
as final. Everything else is final.

### D17. Fallback contract details

Ids must be integers above the last id, so the fallback cannot rank id 0. A
non-integer id, a non-increasing id or a page longer than the limit is an
`InvalidArgumentException`. Rows with the wrong length, a non-numeric,
non-finite or out-of-range value, a zero norm, a bad ref, or a non-array
vector (a JSON string, for instance) are skipped and counted. `scanned()`
counts every row the pages returned, including skipped ones. The fallback
scores in double precision; the packed scan reads float32 values, so scores
agree to about 1e-7, well inside the 1e-5 the tests require.

### D18. Errors without `@`

Every filesystem call that can warn runs through `Internal\Errors::capture()`,
which installs a temporary error handler, keeps the warning text for the
exception message and restores the previous handler. phpcs forbids the
silence operator (`Generic.PHP.NoSilencedErrors`).

### D19. Stale temporary cleanup

At the start of a build (after the lock), the builder lists the directory and
removes only regular files whose names match its own pattern for this target
(`<basename>.<16 lowercase hex>.tmp`, checked with `lstat`) and whose mtime is
older than `staleTempAgeSeconds`. Directories, symlinks, other targets'
temporaries and anything else are left alone. A failed removal is not fatal
(the new build uses a fresh random name); the report counts the files
removed.

### D20. Platform guard in two places

`bootstrap.php` refuses to load on a build where `PHP_INT_SIZE` is not 8.
Composer users do not run the bootstrap, so the `Builder`, `Scanner` and
`ChunkedScanner` constructors run the same guard. The guard takes the integer
size as a parameter so it can be tested on a 64-bit machine.

### D21. Memory measurement

Section 9 budgets are asserted in `tests/MemoryBudgetTest.php`, each test in
its own process, as `memory_get_peak_usage(true)` after minus
`memory_get_usage(true)` before (with `memory_reset_peak_usage()` where PHP
has it). The 100,000 x 512 file is about 205 MB, so set `TMPDIR` to a disk
with room. The group is tagged `slow`; `--exclude-group slow` skips it.

Measured with `memory_get_peak_usage(true)` on the same machine: a
100,000 x 512 build from a generator added less than the allocator's 2 MiB
step (16.3 s); a scan of that file under `memory_limit = 64M` added 10 MiB
(7.9 s); the fallback over the same records in pages of 400 added 4 MiB
(15.2 s).

### D22. Packaging

Composer package `indianopinion/packed-vectors`, PSR-4, zero runtime
dependencies, `bin/packed-vectors` exposed as a Composer binary. The lock file
is not committed, as usual for a library; CI resolves dependencies per PHP
version. `composer.json` pins the platform to PHP 8.1.0 so local installs
pick versions that work on the oldest supported PHP.
