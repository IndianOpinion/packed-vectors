# packed-vectors: product requirements

Status: draft for review. Target release: 0.1.0.
Package: `indianopinion/packed-vectors`. Namespace: `IndianOpinion\PackedVectors`.

## 1. Problem

A PHP application that keeps embeddings in database rows (usually as JSON text) and answers "which stored rows are most similar to this query vector?" hits two walls as the corpus grows.

- Memory. Fetching every vector in one result set materialises each row at least twice (the driver buffer and the returned array). Measured: at about 8,000 rows of 512-dimension vectors stored as JSON text, one result set fatalled a 256 MB `memory_limit`.
- Time. Decoding a JSON vector costs about 1 ms. Measured: at 8,000 and more rows, the similarity step alone passed 10 s per request and kept growing with the corpus. Reading the same vectors as packed float32 with `unpack()` was about 50 times faster.

A vector database is often not available on shared or small hosts, and for tens of thousands of rows it is not needed. What is needed is a flat file of packed float32 records that can be rebuilt atomically from the source of truth and scanned in bounded memory, plus a safe fallback when that file is missing or unusable.

Without this library, teams write the packed file by hand and fall into the same traps: `unpack()` arrays that start at index 1, machine-order floats, unchecked writes that rename a truncated file into place, a trailing partial record silently dropped, and similarity code that quietly truncates vectors of different length.

## 2. Goals and non-goals

Goals:

1. A documented, versioned binary format: a small fixed header followed by fixed-size records of two unsigned 32-bit integers and `dim` little-endian float32 values.
2. An atomic rebuild from any iterable source, streaming, in bounded memory, that either replaces the file completely or leaves the old one untouched.
3. A brute-force cosine scan with top-k, an optional minimum score, and an optional "best per reference" mode, whose memory use does not depend on file size.
4. Optional normalisation at build time so the scan computes only dot products.
5. Strict dimension and value checks on every vector, at build and at query time.
6. Corruption detection: magic, version, header checksum, exact file size, and a payload checksum computed during the scan at no extra pass.
7. Safe concurrency: a scan running while a rebuild replaces the file always sees one whole file, old or new, never a mix.
8. A fallback chunked scanner with the same ranking rules, for when the packed file is unusable and the caller must read from its own store page by page.
9. Typed exceptions for every failure. No silent failure. No `@` error suppression.
10. Zero runtime dependencies. PHP 8.1 or later, 64-bit.

Non-goals:

- Approximate nearest neighbour search, indexes, quantisation, or GPU work. The scan is exact and linear.
- Metadata filtering inside the scan (by date, status, owner). The caller over-fetches and filters, or builds a file per filter.
- Updating records in place, appending, or deleting single records. The file is always rebuilt whole.
- Producing embeddings. The library never calls a model.
- Any retrieval or text-generation logic built on top of the results.
- Running a scheduler. The library offers a staleness check and a rebuild call; the caller decides when to run them (cron, queue, request hook).
- Windows support for replacing a file that a reader holds open (see section 10).

## 3. Users and setting

- PHP developers who store embeddings in MySQL, PostgreSQL, SQLite or a key-value store and need similarity search on a host without a vector database. WordPress sites are a common case, but the library knows nothing about WordPress.
- Corpus size: hundreds to roughly 100,000 vectors, dimension 64 to 4,096. Above that, a real vector index is the better tool, and the README says so.
- Runs in two places: a background job (cron, queue worker, CLI) that rebuilds the file, and request handlers (web, CLI) that scan it. Both run on the same host and see the same local filesystem.
- The file lives on local disk, outside the web root, readable by the web server user and writable by the job user.

## 4. Behaviour

### 4.1 File format

All integers are unsigned and little-endian. All floats are IEEE 754 binary32, little-endian. Byte order is fixed by the format, not by the machine.

Header, 40 bytes:

| Offset | Size | Field | Meaning |
|---|---|---|---|
| 0 | 4 | magic | ASCII `PVEC` |
| 4 | 2 | version | Format version. This PRD defines version 1 |
| 6 | 2 | flags | Bit 0: vectors are unit-normalised. Bits 1 to 15: must be zero |
| 8 | 4 | dim | Values per vector, 1 to 65,535 |
| 12 | 8 | count | Number of records |
| 20 | 8 | built_at | Unix seconds (UTC) when the build finished |
| 28 | 4 | payload_crc | CRC-32 (the `crc32b` polynomial) of every byte after the header |
| 32 | 4 | header_crc | CRC-32 of bytes 0 to 31 |
| 36 | 4 | reserved | Must be zero |

Record, `8 + 4 * dim` bytes:

| Offset | Size | Field |
|---|---|---|
| 0 | 4 | id: the caller's primary key, 0 to 4,294,967,295 |
| 4 | 4 | ref: a second caller key (for example the document a chunk belongs to), 0 if unused |
| 8 | 4 * dim | dim float32 values |

Cases:

1. A valid file has exactly `40 + count * (8 + 4 * dim)` bytes. Any other size is corrupt.
2. A file with `count = 0` is valid and empty. A scan of it returns no hits and no error. Empty is not the same as missing.
3. Records are written in the order the source yields them. The format does not require sorted or unique ids. Uniqueness of `id` is the caller's contract; the scan never relies on it except for tie-breaking (case 37).
4. The reserved field and unknown flag bits must be zero. A reader that finds them set treats the file as corrupt, so a future version cannot be misread by version 1 code.
5. A reader that finds a version other than 1 throws an unsupported-version error and does not try to guess the layout.

### 4.2 Building (atomic rebuild)

6. The builder takes a target path, a dimension, options, and an iterable that yields records. Each record is an id, a ref and a vector (a PHP list of floats or ints).
7. The builder writes to a temporary file in the same directory as the target. The temporary name is the target name plus a random suffix (at least 64 bits from `random_bytes`) and a `.tmp` ending. It is opened with exclusive-create mode so an existing file or symlink of that name is never followed or overwritten.
8. The file mode (default `0640`) is applied immediately after creation and before any data is written, so the vectors are never briefly world-readable.
9. The builder writes a placeholder header, streams the records, then seeks to offset 0 and writes the final header with the real count, `built_at` and both checksums.
10. Every write is checked. A short or failed write (disk full, quota, I/O error) aborts the build: the temporary file is closed and removed, the target is untouched, and a build-failed exception is thrown that names the path and the number of bytes that were expected and written.
11. After the last write the builder flushes, calls `fsync` where the platform supports it, and closes the handle. Only then does it `rename()` the temporary file over the target. A failed close or rename is a build failure (case 10).
12. Because the temporary file is in the same directory, the rename is atomic on POSIX filesystems. A rename across filesystems is never attempted: if the directory of the target does not exist or is not writable, the build fails before reading any record.
13. If the source iterable throws, the build aborts as in case 10 and rethrows the source's exception wrapped in a build-failed exception (the original is kept as `previous`).
14. The builder never holds more than one write buffer (default 1 MiB) of packed records in memory. The source is consumed one record at a time. A source that pages through a database with keyset pagination therefore keeps the whole build in bounded memory.
15. A record whose vector length is not `dim` is a bad record.
16. A record with any value that is NaN, infinite, or outside the float32 range (absolute value above about 3.4e38) is a bad record. Values that would round to a float32 subnormal are allowed.
17. A record whose id or ref is negative, not an integer, or above 4,294,967,295 is a bad record.
18. When normalisation is on (the default), a record whose vector has zero length (all zeros, or a norm that underflows to zero) is a bad record, because it cannot be normalised.
19. Bad-record policy is an option. `skip` (default) leaves the record out and counts it by reason in the build report. `throw` aborts the build as in case 10 with an invalid-vector exception that names the record index, the id and the reason.
20. With normalisation on, each vector is divided by its Euclidean norm, computed in double precision, before packing. Flag bit 0 is set.
21. With normalisation off, vectors are packed as given and flag bit 0 is clear.
22. A build that yields zero good records still produces a valid empty file (case 2) and reports it, because an empty corpus is a real state (for example, right after the embedding dimension changed).
23. Concurrent builders of the same target are serialised by an advisory lock on a sibling lock file (target name plus `.lock`), taken with a non-blocking exclusive `flock`. If the lock is held, the second builder does not wait by default: it returns a report marked "skipped, lock busy" and does not touch the source. A wait timeout can be set instead.
24. The lock file is never the data file. Readers never take the lock.
25. At the start of a build, the builder removes stale temporary files that match its own naming pattern for that target and are older than a configurable age (default one hour). It never removes anything else. These are left behind only by a crashed build.
26. The build report contains: records written, records skipped by reason, bytes written, duration, dim, whether normalised, and `built_at`.
27. A convenience call rebuilds only if the file is missing, unreadable as a header, or its `built_at` is older than a given age (the production default was one hour). It returns whether a rebuild ran and the report if so.

### 4.3 Scanning

28. The scanner opens the file once, reads and checks the header, and checks the file size from the open handle (`fstat`), never from the path. All later reads use the same handle.
29. If the file does not exist, the scanner throws a file-missing exception. If it exists but cannot be opened, it throws an unreadable-file exception. Neither is a corrupt-file case, so the caller can tell "not built yet" from "broken".
30. The query is a PHP list of `dim` numbers. An array that is not a list (for example the 1-indexed array that `unpack()` returns) is rejected with an invalid-vector exception that says so. A query whose length differs from the file's `dim` raises a dimension-mismatch exception that carries both numbers. The scanner never truncates or pads.
31. A query containing NaN or infinite values, or with zero norm, is rejected with an invalid-vector exception.
32. The query is normalised once, in double precision, before the scan.
33. If the file is normalised, the score of a record is the dot product of the normalised query and the stored vector. If not, the score is the full cosine: dot product divided by the stored vector's norm, computed during the same pass. A stored vector with zero norm in an unnormalised file scores 0.0.
34. Scores are clamped to the range -1.0 to 1.0 to absorb float32 rounding.
35. The scanner reads the payload in chunks of a whole number of records, sized from a byte budget (default 4 MiB, at least one record). A short read is not trusted to end on a record boundary: leftover bytes are carried into the next read. If the handle reaches end of file with leftover bytes, or before `count` records were read, the file is corrupt.
36. Values are unpacked one record at a time with a format that yields exactly `dim` floats, and are re-indexed from 0 before any arithmetic, or are read with explicit 1-based offsets. Either way, a test proves that the first and last value of every record contribute to the score (case 30 and lesson 1 in section 14).
37. Top-k keeps the k best hits in a bounded structure while scanning, never a list of every record. Order: higher score first; for equal scores, lower id first, then lower ref. The order is deterministic for a given file and query.
38. `k` must be an integer from 1 to 10,000. Anything else is an invalid-argument exception.
39. An optional minimum score drops hits below it before they enter the top-k. Default: no minimum. The production caller used 0.30 for normalised text embeddings; that value is documented as an example, not a default.
40. Optional "best per ref" mode keeps at most one hit per ref: the highest-scoring record for that ref, ties broken as in case 37. Memory stays bounded by k: when a ref already in the top-k scores higher, its entry is replaced; when an entry is evicted, any later record with the same ref and a lower score would also be below the cut, so the result is exact. This replaces the production pattern of over-fetching twice the limit and de-duplicating afterwards.
41. The payload checksum is computed incrementally over the bytes as they are read. If it does not match at the end, the scan throws a corrupt-file exception and returns no hits. Partial or suspect results are never returned. Verification can be turned off for speed; the default is on.
42. An optional time budget (seconds, float). If the scan exceeds it, the scanner stops and throws a budget-exceeded exception that carries the records scanned so far. It does not return partial results. Default: no time budget.
43. A scan returns a result object with the hits (each with id, ref and score) and a report: records scanned, bytes read, elapsed time, the file's `dim`, `built_at`, whether normalised, and whether the checksum was verified.
44. The scanner never writes, locks, renames or deletes anything.

### 4.4 Inspection

45. An info call reads only the header and the file size and returns version, flags, dim, count, `built_at`, record size and file size, with the same validity checks as a scan (cases 1, 4, 5, header checksum). It does not read the payload.
46. A verify call reads the whole file once, checks the payload checksum and that every value is finite, and reports the result. It is for operators and tests, not for request paths.

### 4.5 Fallback chunked scan

47. A fallback scanner ranks vectors that the caller supplies page by page from its own store. The caller passes a page callback that receives the last id seen (0 at the start) and a page size, and returns a list of records (id, ref, vector) with ids strictly greater than the last id, in ascending id order. This is keyset pagination; the README explains why `OFFSET` pagination is not used (it slows down with depth and can skip or repeat rows while the table changes).
48. The fallback stops when a page returns fewer records than the page size. Default page size: 400.
49. The fallback applies the same query checks (cases 30 to 32), the same scoring, the same top-k, minimum score and best-per-ref rules, and returns the same result shape, so a caller can switch between the two without changing its ranking.
50. A record from the caller's store with the wrong length, non-finite values or a zero norm is skipped and counted in the report. It never stops the scan and never raises a dimension-mismatch error, because one bad row in a table must not take the feature down. A vector given as a JSON string is not accepted; the caller decodes it.
51. The fallback never keeps a page after it is scored. The README tells the caller to release its driver's result buffer after each page.
52. A page that returns ids not strictly greater than the last id is an invalid-argument exception, so a broken callback cannot loop forever.

### 4.6 Concurrency between a rebuild and a scan

53. A scan that opened the file before a rename keeps reading the old file through its handle until it finishes. On POSIX systems the old inode stays alive until the last handle closes. The scan sees one whole, consistent file.
54. A scan that opens the file after the rename sees the whole new file.
55. No scan ever sees a temporary file, because scanners open only the target path.
56. A scan never sees a half-written target, because the target is only ever replaced by rename and never written in place.
57. A scan that starts while no file exists yet throws file-missing (case 29), even if a build is in progress.
58. Two builders racing are serialised by the lock (case 23). If a caller turns the lock off, the last rename wins and both files were complete, so readers still never see a torn file.
59. Removing the target by hand while a scan runs does not affect that scan.

### 4.7 Failure modes and what happens

| Failure | What the library does | Fails open or closed |
|---|---|---|
| File missing | Scan throws file-missing | Closed for the file. The caller falls back to the chunked scan and queues a rebuild, so the feature stays open |
| Bad magic, unknown version, bad header checksum, non-zero reserved bits | Throws corrupt-file or unsupported-version | Closed. Guessing a layout returns wrong neighbours with no error |
| File size does not match the header | Throws corrupt-file before scoring anything | Closed. A truncated file would silently drop the newest records |
| Payload checksum mismatch | Throws corrupt-file after the pass, returns nothing | Closed. Results from damaged bytes are not trusted |
| Query length differs from file dim | Throws dimension-mismatch | Closed. Usually means the embedding model changed; the caller falls back and rebuilds |
| Query not a list, non-finite or zero | Throws invalid-vector | Closed. It is a caller bug |
| Time budget exceeded | Throws budget-exceeded | Closed. The caller decides whether to retry or degrade |
| Disk full or write error during build | Removes the temporary file, throws build-failed, leaves the old file | Closed for the build, open for readers, who keep the previous good file |
| Source throws during build | As above | As above |
| Builder lock busy | Returns "skipped, lock busy" | Open. Another builder is already doing the work |
| Bad record during build | Skips and counts (default) or throws | Configurable. Skip keeps one bad row from blocking the whole file |
| Bad record in fallback | Skips and counts | Open, for the same reason |

## 5. Public interface

Names below are the public API the builder implements. All classes are `final` unless noted. All methods validate their arguments and throw the exceptions listed.

### 5.1 Classes

`Format` (constants and pure helpers, no I/O)

- `MAGIC = 'PVEC'`, `VERSION = 1`, `HEADER_SIZE = 40`, `FLAG_NORMALISED = 1`, `MAX_DIM = 65535`, `MAX_ID = 4294967295`.
- `recordSize(int $dim): int`
- `expectedFileSize(int $dim, int $count): int`

`Builder`

- `__construct(string $path, int $dim, ?BuildOptions $options = null)`
- `build(iterable $records): BuildReport` where each item is a `Record` or an array `[int $id, int $ref, list<float|int> $vector]`.
- `rebuildIfStale(int $maxAgeSeconds, callable $recordsFactory): ?BuildReport` returns null when the file is fresh. The factory is called only when a rebuild runs and returns the iterable.

`BuildOptions` (immutable, with-ers return a copy)

- `normalise: bool = true`
- `onBadRecord: 'skip'|'throw' = 'skip'`
- `fileMode: int = 0640`
- `writeBufferBytes: int = 1048576`
- `lock: bool = true`
- `lockWaitSeconds: float = 0.0` (0 means do not wait)
- `staleTempAgeSeconds: int = 3600`
- `fsync: bool = true`

`BuildReport` (read-only)

- `skippedForLock(): bool`, `written(): int`, `skipped(): array<string,int>` keyed by reason (`length`, `non_finite`, `out_of_range`, `zero_norm`, `bad_id`), `bytes(): int`, `seconds(): float`, `dim(): int`, `normalised(): bool`, `builtAt(): int`.

`Record` (read-only value object)

- `__construct(int $id, int $ref, array $vector)`; properties `id`, `ref`, `vector`.

`Scanner`

- `__construct(string $path, ?ScanOptions $options = null)`
- `top(array $query, int $k): ScanResult`
- `info(): FileInfo`
- `verify(): VerifyReport`

`ScanOptions` (immutable)

- `minScore: ?float = null`
- `bestPerRef: bool = false`
- `verifyChecksum: bool = true`
- `chunkBytes: int = 4194304`
- `timeBudgetSeconds: ?float = null`

`ChunkedScanner` (the fallback)

- `__construct(callable $fetchPage, int $dim, ?ScanOptions $options = null, int $pageSize = 400)`. `$fetchPage(int $afterId, int $limit): iterable` yields `Record` or `[id, ref, vector]`.
- `top(array $query, int $k): ScanResult`

`ScanResult`

- `hits(): list<Hit>`, `report(): ScanReport`

`Hit` (read-only): `id`, `ref`, `score`. The name `Match` is not used because `match` is a reserved word in PHP 8.

`ScanReport` (read-only): `scanned(): int`, `skipped(): int` (fallback only, else 0), `bytesRead(): int`, `seconds(): float`, `dim(): int`, `builtAt(): ?int` (null for the fallback), `normalised(): bool`, `checksumVerified(): bool`.

`FileInfo`, `VerifyReport` (read-only): the fields named in cases 45 and 46.

### 5.2 Exceptions

All extend `PackedVectorsException`, which extends `RuntimeException`, except `InvalidArgumentException` which extends the SPL class of the same name and implements the package's marker interface `PackedVectorsError`. Every exception implements `PackedVectorsError`, so a caller can catch the whole family in one clause.

- `FileMissingException`
- `UnreadableFileException`
- `CorruptFileException` (with a reason string: `magic`, `header_crc`, `reserved`, `size`, `payload_crc`, `short_read`)
- `UnsupportedVersionException` (carries the version found)
- `DimensionMismatchException` (carries expected and given)
- `InvalidVectorException` (carries the reason, and for builds the record index and id)
- `InvalidArgumentException`
- `BudgetExceededException` (carries records scanned and elapsed seconds)
- `BuildFailedException` (carries the target path, and `previous` when wrapping)

A helper `UnavailableException` interface is implemented by `FileMissingException`, `UnreadableFileException`, `CorruptFileException`, `UnsupportedVersionException` and `DimensionMismatchException`. Catching it is the documented way to decide "fall back and rebuild".

### 5.3 CLI

`bin/packed-vectors` (no dependencies, exits non-zero on any failure):

- `packed-vectors info <file>` prints the header fields and file size.
- `packed-vectors verify <file>` runs case 46 and prints the result.

There is no CLI build command, because the source of records is application code.

### 5.4 Usage without Composer

`require 'path/to/packed-vectors/bootstrap.php';` registers a small autoloader for the namespace.

## 6. Data

- One data file per corpus, at a path the caller chooses. Contents: ids, refs and vectors only. No text, no metadata.
- One lock file beside it (empty, created on first build, left in place).
- Temporary files beside it during a build, removed on success, on failure, or by the next build when stale (case 25).
- Retention: the file is derived data. It can be deleted at any time and rebuilt from the source of truth. Retention policy for the vectors belongs to the caller's source.
- Uninstall: removing the library leaves the data file, the lock file and any stale temporaries in place. The README lists them so an operator can remove them through their normal process.

## 7. Configuration and sensible defaults

| Setting | Default | Why |
|---|---|---|
| normalise | on | Turns every score into one dot product, and makes scores comparable across files |
| onBadRecord | skip | One bad row must not block the whole rebuild; the report counts it |
| fileMode | 0640 | Readable by the web server group, not by other users |
| writeBufferBytes | 1 MiB | Few system calls, small memory |
| lock | on, no wait | Duplicate builds waste the job's time; the running one will finish |
| staleTempAgeSeconds | 3600 | Longer than any normal build |
| fsync | on | A power cut after rename must not leave an empty target |
| rebuild age (caller passes) | 3600 s in production | Hourly freshness was enough for a news corpus; pick to suit |
| minScore | none | Thresholds depend on the embedding model |
| bestPerRef | off | Only useful when several records share a ref |
| verifyChecksum | on | Cost is small compared with the arithmetic |
| chunkBytes | 4 MiB | Keeps peak memory flat; a few hundred records per read at 512 dims |
| timeBudgetSeconds | none | Request paths should set one |
| fallback pageSize | 400 | Bounded memory per page with JSON text of about 10 KB per vector |

The file path has no default. The README says to put it outside the web root, for example under `/var/lib/<app>/`.

## 8. Security and privacy

- The file must sit outside any served directory. Embeddings can leak information about the text they came from. The README states this first in the setup section.
- The file mode is set before any data is written (case 8).
- Temporary files use unpredictable names and exclusive-create (case 7), so a local attacker cannot pre-create or symlink them.
- Stale-temporary cleanup only matches the builder's own pattern for its own target (case 25).
- No `eval`, no shell, no network, no `unserialize`. The reader treats every byte as untrusted: sizes come from the header and are checked against `fstat` before any allocation, so a forged header cannot make the scanner allocate a huge buffer.
- `dim` and `count` from the header are bounded (case 1, `MAX_DIM`) before computing sizes, so the size arithmetic cannot overflow a 64-bit integer.
- No `@` suppression. Errors from `fopen`, `fread` and `rename` are caught and turned into the typed exceptions above, with the PHP warning text included in the message where available.
- Ids and refs are opaque integers. The library stores no personal data, but the caller's vectors may be derived from personal text; that is the caller's data protection concern, noted in the README.

## 9. Performance budget

Measured in production before this library existed: JSON vectors cost about 1 ms each to decode and pushed the similarity step past 10 s at 8,000 rows; packed float32 read with `unpack()` was about 50 times faster.

Budgets for 0.1.0, measured on PHP 8.3 without JIT on one current server core:

- Scan of 10,000 records at 512 dims, top 10: under 1.0 s, checksum on.
- Scan of 100,000 records at 512 dims: completes with `memory_limit = 64M`.
- Peak memory added by a scan (measured with `memory_get_peak_usage(true)` before and after): under 16 MiB with default options, independent of file size.
- Peak memory added by a build of 100,000 records from a generator: under 16 MiB with default options.
- Fallback scan memory: one page plus the top-k.

The repository ships a benchmark script that prints these numbers. CI runs the memory checks as tests; the timing budget is printed, not asserted, because shared CI runners are noisy.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3, 64-bit builds only (`PHP_INT_SIZE === 8`). The bootstrap throws a clear exception on a 32-bit build, because 64-bit header fields and ids above 2^31 need it.
- Extensions: none beyond the default build (`hash` for CRC-32, which is always present).
- Filesystems: local POSIX filesystems (ext4, xfs, btrfs, APFS). Network filesystems may not give atomic rename or keep a replaced file alive for open readers; the README says to keep the file on local disk.
- Windows: renaming over a file that another process holds open can fail. The builder reports this as build-failed and leaves the old file. Windows is not a supported target for concurrent build and scan.
- Endianness: the format is little-endian regardless of the machine, so files move between hosts.
- Frameworks: none. Works in WordPress, Laravel, Symfony or plain PHP. OPcache and page caches are irrelevant because the file is not PHP and is read per request.

## 11. Observability

- Every build returns a `BuildReport`. The README shows how to log it in one line: written, skipped by reason, bytes, seconds, dim.
- Every scan returns a `ScanReport` with records scanned, bytes read and seconds, so a caller can log slow scans.
- `built_at` in the header and in every report lets an operator see how stale the file is.
- `bin/packed-vectors info` and `verify` let an operator check a file by hand.
- The library writes no logs itself. It accepts no logger, to stay dependency-free; the reports carry everything a caller needs to log.
- Exceptions carry structured fields (reason, expected and given dim, path) so log lines are specific.

## 12. Acceptance tests

Each test names the cases it covers.

1. (1) A built file's size equals `40 + count * (8 + 4 * dim)`; appending one byte makes `info()` throw corrupt-file with reason `size`.
2. (2, 22) Building from an empty iterable gives a valid file with count 0; `top()` returns no hits and no exception.
3. (3) Records are read back in source order with duplicate ids allowed.
4. (4) Setting a reserved byte or flag bit 2 makes `info()` throw corrupt-file with reason `reserved`.
5. (5) Writing version 2 into the header (with a fixed header checksum) throws unsupported-version carrying 2.
6. (6) `build()` accepts both `Record` objects and three-element arrays.
7. (7) The temporary file is in the target directory, ends in `.tmp`, and a pre-existing symlink at a forced temporary name causes build-failed rather than following it (test injects the name through a protected seam).
8. (8) During a build, the temporary file's mode is already `0640` when the first record is written (checked from a source generator).
9. (9) After a build, the header count, `built_at` and both checksums are correct when recomputed independently in the test.
10. (10) A stream wrapper that fails a write halfway causes build-failed with expected and written byte counts; the old target is byte-identical afterwards; no temporary file remains.
11. (11) A failing rename (target is a directory) causes build-failed and removes the temporary file.
12. (12) A target in a missing or read-only directory fails before the source generator is advanced.
13. (13) A source generator that throws aborts the build; the exception is build-failed with the original as `previous`; the old file is unchanged.
14. (14) Building 100,000 records at 512 dims from a generator stays under the section 9 memory budget.
15. (15) A record with 511 values in a 512-dim build is skipped with reason `length`.
16. (16) NaN, INF, and 1e39 values are skipped with reasons `non_finite` and `out_of_range`; a value of 1e-40 is accepted.
17. (17) Ids of -1, 4294967296 and the string "5" are skipped with reason `bad_id`; 4294967295 is accepted and read back exactly.
18. (18) An all-zero vector is skipped with reason `zero_norm` when normalising, and accepted when not.
19. (19) With `onBadRecord = throw`, the first bad record aborts the build with invalid-vector carrying its index and id; the old file is unchanged.
20. (20) With normalisation on, every stored vector has norm within 1e-6 of 1.0 and flag bit 0 is set.
21. (21) With normalisation off, stored values equal the input rounded to float32 and flag bit 0 is clear.
22. (23) While one process holds the lock, a second `build()` returns a report with `skippedForLock() === true` and never calls the source; with a wait timeout it builds after the lock is released.
23. (24) A scan succeeds while the lock file is held exclusively by another process.
24. (25) A stale temporary file of the builder's pattern older than the limit is removed; a younger one and an unrelated file in the same directory are kept.
25. (26) The report's counts add up: written plus all skipped equals records yielded.
26. (27) `rebuildIfStale()` runs when the file is missing, when the header is corrupt and when `built_at` is older than the age, and does not call the factory when the file is fresh.
27. (28) Replacing the file on disk after the scanner has opened it does not change the size used for the check (seam that renames between `fopen` and the size check).
28. (29) A missing path throws file-missing; an unreadable path (mode 000, skipped when running as root) throws unreadable-file; neither is corrupt-file.
29. (30) A query from `unpack('g4', ...)` (keys 1 to 4) is rejected as not a list; a 3-value query against a 4-dim file throws dimension-mismatch carrying 4 and 3.
30. (31) Queries with NaN, INF or all zeros throw invalid-vector.
31. (32, 33) For a normalised and an unnormalised file built from the same random vectors, scores match a double-precision reference cosine within 1e-5.
32. (33) A zero vector stored in an unnormalised file scores 0.0.
33. (34) A query equal to a stored vector scores exactly 1.0, never above.
34. (35) A stream wrapper that returns reads of random short lengths (not record multiples) gives the same hits as a normal read; a file cut mid-record throws corrupt-file with reason `size`.
35. (36) The one-indexed trap: vectors whose only non-zero value is the first element, and vectors whose only non-zero value is the last element, are each found with score 1.0 by a matching query.
36. (37) Equal scores are ordered by lower id, then lower ref; running the same scan twice gives identical output.
37. (38) `k` of 0, -1 and 10,001 throws invalid-argument; 10,000 is accepted.
38. (39) With a minimum score of 0.5, no hit below 0.5 is returned, and fewer than k hits is not an error.
39. (40) With best-per-ref on, a file with five records sharing one ref and other refs returns that ref once, with its best record; the result equals a brute-force reference for 1,000 random files.
40. (41) Flipping one payload bit throws corrupt-file with reason `payload_crc` and no hits; with verification off the scan returns hits.
41. (42) A time budget of 0.000001 s on a 10,000-record file throws budget-exceeded carrying a scanned count below 10,000.
42. (43) The report fields match the file and the query (scanned equals count, bytes read equals payload size).
43. (44) Scanning a file in a read-only directory works and leaves the directory listing unchanged.
44. (45) `info()` on a valid file returns the header fields and does not read past byte 40 (stream wrapper counts bytes).
45. (46) `verify()` passes a good file and reports a bad payload checksum and a stored NaN (written by hand) as failures.
46. (47, 48) The fallback over an in-memory table of 1,001 records with page size 400 calls the page callback three times with after-ids 0, 400 and 800 (for sequential ids) and stops.
47. (49) The fallback and the packed scan return identical hits (ids, refs and scores within 1e-5) for the same records and query, with and without best-per-ref and minimum score.
48. (50) The fallback skips a wrong-length row, a NaN row, a zero row and a JSON string row, counts four skipped, and still returns the right hits.
49. (51) Fallback peak memory over 100,000 generated records is bounded by one page plus top-k (section 9).
50. (52) A page callback that returns an id not above the last id throws invalid-argument.
51. (53) A child process opens the scanner and pauses after the first chunk; the parent rebuilds with different vectors; the child completes with hits from the old file only and a valid checksum.
52. (54, 55, 56) Running scans in a loop while a builder rebuilds 50 times never produces a corrupt-file exception or a mix of old and new records.
53. (57) A scan of a missing target during a slow build throws file-missing.
54. (58) Two builders with the lock off both finish; the final file is one of the two complete outputs.
55. (59) Unlinking the target mid-scan does not affect that scan's result.
56. (section 10, 64-bit) The bootstrap refuses to load on a 32-bit build (tested by a unit test of the guard function with the integer size injected).
57. (5.3) `bin/packed-vectors info` and `verify` exit 0 on a good file and non-zero on a corrupt one.

## 13. Open questions

The builder answers these in `docs/ARCHITECTURE.md`.

1. Top-k structure: `SplMinHeap` subclass, or a small sorted array with binary insertion? Pick by benchmark at k = 10 and k = 1,000.
2. Unpack strategy: one `unpack('g' . $dim)` per record followed by `array_values`, or one `unpack` per chunk with 1-based offsets? Pick by benchmark; either must pass test 35.
3. Should the dot product loop be unrolled by 4 or 8? Only if the benchmark shows a gain of more than 15 percent.
4. `fsync`: PHP 8.1 has `fsync()`; confirm behaviour on the CI runners and on a directory, and decide whether to also fsync the directory after rename where the platform allows.
5. Should a future format version allow 64-bit ids? If so, reserve the plan now (a flag bit or version 2) and note it.
6. Time budget check frequency: per chunk or every N records? Keep the overhead under 1 percent.
7. Should `rebuildIfStale()` also rebuild when the requested dim differs from the file's dim? Production behaviour suggests yes; confirm and document.
8. Lock file location when the target directory is not writable by the scanner user but is by the builder: confirm the scanner never needs to create it.

## 14. Lessons from production

1. `unpack()` returns an array whose keys start at 1, not 0. Indexing it from 0 reads a null for the first value and drops the last one, and the scores come out wrong with no error. Re-index with `array_values()` or use 1-based offsets, and test with vectors whose only signal is in the first or last slot.
2. Use the little-endian float format (`g`), never the machine-order one (`f`). A file written on one host must read the same on another.
3. Never compute similarity over `min(len(a), len(b))`. A length mismatch means a different model or a bug. Refuse it loudly.
4. Never `rename()` a file whose writes were not all checked. A full disk produces a short file, and renaming it into place replaces a good file with a broken one.
5. Never trust a single `fread()` to end on a record boundary. Carry leftover bytes forward and fail if the file ends mid-record. Dropping the tail silently loses the newest records.
6. Check the file size against the header before scoring. A truncated file must be an error, not a smaller corpus.
7. Write the temporary file in the same directory as the target. A rename across filesystems is a copy, and readers can see it half done.
8. Set the file mode at creation, not after the data is written. A predictable temporary name (a process id) is not good enough: use random bytes and exclusive-create.
9. Keep the file outside the web root. It is derived from your content and must never be servable.
10. Read the header and the size through the open handle. Asking the path after a rename describes a different file.
11. Never fetch every vector in one database result set. The driver buffer and the returned array each hold a full copy; at about 8,000 JSON vectors that broke a 256 MB limit. Page by key, release each page, and keep one page in memory.
12. Page by `id > last_id`, not by `OFFSET`. Offset pages get slower with depth and skip or repeat rows when the table changes under you.
13. JSON is the wrong storage for a vector you read on every request. Decode cost was about 1 ms per vector; packed float32 was about 50 times faster.
14. Treat "missing", "corrupt" and "wrong dimension" the same way at the call site: fall back to the slow, safe path for this request and queue one rebuild. Debounce the rebuild so a burst of requests does not start a burst of builds.
15. An empty file is a valid state, not a failure. After an embedding model change, the rebuild skips every old-dimension row and writes an empty file until the source is re-embedded. Do not loop on rebuilding it.
16. Count the rows a build skips and report them. A build that skips rows in silence can hide a dimension change or a broken source until results quietly get worse.
17. Rank inside the scan with a bounded top-k. Collecting every hit above a threshold and sorting afterwards grows with the corpus.
18. If several records can point to the same document, de-duplicate by document inside the top-k. Over-fetching a multiple of k and de-duplicating afterwards can still return fewer than k documents.
19. A stale file is better than a slow request. Serve the last good file while a rebuild is due, and expose its age so an operator can see when the rebuild job has stopped running.
20. Never use `@` to silence `fopen()` or `chmod()`. A suppressed error turns a permissions problem into a mysterious fallback on every request.
21. `match` is a reserved word in PHP 8. Do not name a class `Match`.
