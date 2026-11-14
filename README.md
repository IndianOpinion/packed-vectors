# packed-vectors

Stores embeddings in a packed float32 file with a tiny header, rebuilds it atomically, and scans it by exact cosine similarity in bounded memory, without loading rows from your database. It exists because similarity search over JSON vectors in database rows runs out of memory and time long before a corpus is big enough to justify a vector database.

Measured in production: at about 8,000 rows of 512-dimension JSON vectors the similarity step passed 10 s per request and one result set fatalled a 256 MB `memory_limit`; reading the same vectors packed as float32 was about 50 times faster. This library scans 10,000 x 512 in under a second with about 8 MiB of added memory (see [Performance](#performance)).

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

- PHP 8.1 or later, 64-bit. Zero runtime dependencies.
- Exact, linear scan. No index, no approximation.
- Typed exceptions for every failure. No `@` error suppression anywhere.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Setup: where the file lives](#setup-where-the-file-lives)
- [Configuration](#configuration)
- [Serving requests with a fallback](#serving-requests-with-a-fallback)
- [The fallback chunked scanner](#the-fallback-chunked-scanner)
- [Command line](#command-line)
- [How it works](#how-it-works)
- [File format](#file-format)
- [Performance](#performance)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

With Composer:

```sh
composer require indianopinion/packed-vectors
```

Without Composer, download a release and require the bootstrap file once. It registers an autoloader for the `IndianOpinion\PackedVectors` namespace and refuses to load on a 32-bit PHP build:

```php
require '/path/to/packed-vectors/bootstrap.php';
```

## Quick start

```php
use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;

$path = '/var/lib/myapp/chunks.vec';   // outside the web root
$dim  = 512;

// A background job rebuilds the file from your source of truth.
// Each record is [int $id, int $ref, list<float> $vector], or a Record.
$records = (function () use ($pdo): Generator {
    $last = 0;
    $stmt = $pdo->prepare('SELECT id, doc_id, embedding FROM chunks WHERE id > ? ORDER BY id LIMIT 500');
    do {
        $stmt->execute([$last]);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $stmt->closeCursor();
        foreach ($rows as [$id, $docId, $json]) {
            $last = (int) $id;
            yield [(int) $id, (int) $docId, json_decode($json, true)];
        }
    } while (count($rows) === 500);
})();

$report = (new Builder($path, $dim))->build($records);
error_log((string) $report);
// packed-vectors build /var/lib/myapp/chunks.vec: written=8113 skipped=2 (length=2) bytes=16681448 seconds=1.912 dim=512 normalised=yes

// A request scans it.
$result = (new Scanner($path, new ScanOptions(timeBudgetSeconds: 0.5)))->top($queryVector, 10);
foreach ($result->hits() as $hit) {
    echo $hit->id, ' ', $hit->ref, ' ', $hit->score, "\n";
}
```

More in [`examples/`](examples): `php examples/01-build-and-scan.php` runs as is.

## Setup: where the file lives

1. **Put the file outside any served directory.** Embeddings can leak information about the text they came from. A path such as `/var/lib/<app>/corpus.vec` is right; anything under the document root is wrong.
2. The directory must be writable by the job that builds the file, and the file readable by the web server user. The default file mode is `0640`: give the web server's group read access, and nobody else.
3. Keep the file on local disk (ext4, xfs, btrfs, APFS). Network filesystems may not give an atomic rename, or may not keep a replaced file alive for a reader that has it open.
4. The builder creates two kinds of sibling files: `<file>.lock` (empty, left in place) and `<file>.<16 hex>.tmp` during a build. Scanners never create anything, so the web server user only needs read access to the file itself.

Removing the library leaves the data file, its `.lock` file and any stale `.tmp` files from crashed builds in place. They are derived data: delete them through your normal process.

The vectors are derived from your content, which may be personal data. That is your data protection concern; the file stores only ids, refs and float values.

## Configuration

`BuildOptions` (immutable; every `with...()` returns a validated copy):

| Option | Default | What it does |
|---|---|---|
| `normalise` | `true` | Divide each vector by its Euclidean norm before packing, so a scan is one dot product per record and scores are comparable across files. Zero vectors are skipped (`zero_norm`). |
| `onBadRecord` | `'skip'` | `'skip'` leaves a bad record out and counts it by reason; `'throw'` aborts the build with `InvalidVectorException` naming the record index and id. |
| `fileMode` | `0640` | Applied to the temporary file before any data is written. |
| `writeBufferBytes` | `1048576` | Packed bytes held in memory before each write. |
| `lock` | `true` | Serialise builders of the same target with `flock` on `<file>.lock`. |
| `lockWaitSeconds` | `0.0` | `0` means do not wait: a second builder returns a report marked "skipped, lock busy". |
| `staleTempAgeSeconds` | `3600` | Remove this builder's own `.tmp` files older than this at the start of a build. |
| `fsync` | `true` | `fsync` the file before the rename and the directory after it, so a power cut cannot leave an empty target. |

`ScanOptions` (shared by `Scanner` and `ChunkedScanner`):

| Option | Default | What it does |
|---|---|---|
| `minScore` | `null` | Drop hits below this score before they enter the top-k. Thresholds depend on the model: 0.30 worked for one set of normalised text embeddings, as an example only. |
| `bestPerRef` | `false` | Keep at most one hit per `ref` (for example one chunk per document), exactly, inside the top-k. |
| `verifyChecksum` | `true` | Compute the payload CRC-32 during the scan and refuse the result if it does not match. |
| `chunkBytes` | `4194304` | Bytes read per `fread`, rounded down to whole records (at least one). |
| `timeBudgetSeconds` | `null` | Abort with `BudgetExceededException` once the scan runs longer. Request paths should set one. |

Other settings: `top($query, $k)` takes `k` from 1 to 10,000. `ChunkedScanner` takes a `pageSize` (default 400). `Builder::rebuildIfStale($maxAgeSeconds, $factory)` takes the age you choose; hourly worked for a news corpus.

### Build reports

`BuildReport` has `written()`, `skipped()` (every reason, zero if unused: `bad_id`, `not_list`, `length`, `not_numeric`, `non_finite`, `out_of_range`, `zero_norm`), `skippedTotal()`, `bytes()`, `seconds()`, `dim()`, `normalised()`, `builtAt()`, `skippedForLock()` and `staleTempsRemoved()`. Cast it to a string for one log line. Log the skipped counts: a build that skips rows in silence can hide a dimension change until results quietly get worse.

`ScanReport` has `scanned()`, `skipped()`, `bytesRead()`, `seconds()`, `dim()`, `builtAt()`, `normalised()` and `checksumVerified()`. Log slow scans, and alert on `time() - builtAt()` to notice a rebuild job that has stopped.

### Exceptions

Every exception implements `Exception\PackedVectorsError`. The ones that mean "this file cannot answer, fall back and rebuild" also implement `Exception\UnavailableException`:

| Exception | When | `UnavailableException` |
|---|---|---|
| `FileMissingException` | No file at the path ("not built yet") | yes |
| `UnreadableFileException` | Exists but cannot be opened or read | yes |
| `CorruptFileException` | `$reason`: `magic`, `header_crc`, `reserved`, `dim`, `size`, `payload_crc`, `short_read`, `non_finite` | yes |
| `UnsupportedVersionException` | Header version is not 1; carries `$version` | yes |
| `DimensionMismatchException` | Query length differs from the file; carries `$expected`, `$given` | yes |
| `InvalidVectorException` | Query not a list, non-finite or zero; or a bad build record with `onBadRecord = throw` | no |
| `InvalidArgumentException` | Bad `k`, bad option, broken page callback (extends the SPL class) | no |
| `BudgetExceededException` | Time budget passed; carries `$scanned`, `$seconds` | no |
| `BuildFailedException` | A build did not complete; target untouched; carries `$path`, `$expectedBytes`, `$writtenBytes`, `getPrevious()` | no |
| `DirectoryNotWritableException` | Target directory missing or not writable (a `BuildFailedException`) | no |

## Serving requests with a fallback

Treat missing, corrupt and wrong-dimension the same way: use the slow, safe path for this request and queue one rebuild.

```php
use IndianOpinion\PackedVectors\ChunkedScanner;
use IndianOpinion\PackedVectors\Exception\UnavailableException;

try {
    $result = (new Scanner($path, $options))->top($query, 10);
} catch (UnavailableException $e) {
    error_log('packed-vectors: ' . $e->getMessage());
    queue_rebuild_once();   // debounce: a burst of requests must not start a burst of builds
    $result = (new ChunkedScanner($fetchPage, $dim, $options))->top($query, 10);
}
```

And in the job:

```php
$report = (new Builder($path, $dim))->rebuildIfStale(3600, fn () => $records());
```

`rebuildIfStale()` rebuilds when the file is missing, unreadable, corrupt, of another version, built for another dimension or normalisation setting, or older than the age. A dimension change always needs a full rebuild; until your source is re-embedded, the rebuild skips every old-dimension row (`length`) and writes a valid empty file. Do not loop on rebuilding it. A stale file is better than a slow request: keep serving the last good file while a rebuild is due.

## The fallback chunked scanner

`ChunkedScanner` ranks vectors your callback reads from your own store, page by page, with exactly the same checks, scoring, top-k, `minScore` and `bestPerRef` rules as `Scanner`, so switching between them does not change the ranking.

```php
$fetchPage = function (int $afterId, int $limit) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, doc_id, embedding FROM chunks WHERE id > ? ORDER BY id LIMIT ?');
    $stmt->bindValue(1, $afterId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $page = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $doc, $json]) {
        $page[] = [(int) $id, (int) $doc, json_decode($json, true)];
    }
    $stmt->closeCursor();   // release the driver's buffer before the next page
    return $page;
};
$result = (new ChunkedScanner($fetchPage, 512))->top($query, 10);
```

- The callback gets the last id seen (0 at the start) and the page size, and returns records with ids strictly greater, in ascending id order. So ids must be 1 or more.
- This is keyset pagination, not `OFFSET`: offset pages get slower with depth and skip or repeat rows when the table changes underneath.
- Never fetch every vector in one result set. The driver buffer and the returned array each hold a full copy.
- Decode JSON yourself. A string vector is skipped, as is a row with the wrong length, a non-finite value or a zero norm; `report()->skipped()` counts them. One bad row never takes the feature down.
- A callback that returns an id not above the last one, or more rows than asked for, gets an `InvalidArgumentException`, so a broken callback cannot loop forever.

## Command line

```sh
vendor/bin/packed-vectors info /var/lib/myapp/chunks.vec     # header fields and file size; reads 40 bytes
vendor/bin/packed-vectors verify /var/lib/myapp/chunks.vec   # full read: payload checksum and finite values
```

Exit codes: `0` ok, `1` missing, unreadable, corrupt or failed verification, `2` usage error. There is no build command: the source of records is your application code.

## How it works

**Build.** The builder checks the target directory is writable before it touches your source, takes a non-blocking `flock` on `<file>.lock`, and removes its own stale temporary files. It then creates `<file>.<16 random hex>.tmp` in the same directory with exclusive create (`x` mode: an existing file or symlink of that name is never followed), sets the mode, writes a placeholder header, and streams records through a 1 MiB buffer while it updates a CRC-32. Every write is checked; a short write aborts. At the end it writes the real header, flushes, `fsync`s, closes, checks the size, `rename()`s over the target, and `fsync`s the directory. Any failure removes the temporary file and leaves the old target byte for byte.

**Scan.** The scanner opens the file once and takes the size from that handle (`fstat`), never from the path, which may already name a newer file. It checks magic, version, header checksum, reserved bits, dimension and the exact size before reading a single record. It reads whole-record chunks, carries on after short reads until a chunk is complete, and decodes floats with one `unpack()` per block of records using 1-based offset arithmetic (see the trap below). The query is normalised once in double precision; each record costs one dot product (plus its norm in an unnormalised file). Scores are clamped to -1..1. A bounded min-heap keeps the best `k`; only candidates that beat its floor have their id and ref decoded. The payload CRC is checked at the end, and a mismatch returns nothing.

**Concurrency.** The target is only ever replaced by `rename()`. A scan that opened the old file keeps reading the old inode until it closes it; a scan that opens later sees the whole new file. Nobody ever sees a half-written file, and scanners never take the lock.

**The `unpack()` trap.** `unpack()` returns an array whose keys start at 1. Indexing it from 0 reads `null` for the first value and drops the last, and the scores come out wrong with no error. The scanner uses explicit 1-based offsets, the tests use vectors whose only signal is in the first or last slot, and a query array that is not a list is refused with a message that says to wrap it in `array_values()`.

## File format

Version 1. All integers unsigned little-endian; floats IEEE 754 binary32 little-endian (`pack('g')`), so a file moves between machines.

| Offset | Size | Field |
|---|---|---|
| 0 | 4 | magic `PVEC` |
| 4 | 2 | version (1) |
| 6 | 2 | flags: bit 0 set when vectors are unit-normalised; other bits zero |
| 8 | 4 | dim, 1 to 65,535 |
| 12 | 8 | count |
| 20 | 8 | built_at, Unix seconds |
| 28 | 4 | CRC-32 of every byte after the header |
| 32 | 4 | CRC-32 of bytes 0 to 31 |
| 36 | 4 | reserved, zero |

Then `count` records of `8 + 4 * dim` bytes: `u32 id`, `u32 ref`, `dim` float32 values. A valid file is exactly `40 + count * (8 + 4 * dim)` bytes. `Format::recordSize()` and `Format::expectedFileSize()` do the arithmetic.

## Performance

Section 9 budgets, and how to check them on your machine: `php benchmarks/bench.php [records] [dim] [dir]`.

| Measure | Budget | Measured (PHP 8.3, no JIT, shared 2-core box under load) |
|---|---|---|
| Scan 10,000 x 512, top 10, checksum on | under 1.0 s | 0.84 s |
| Peak memory added by a scan | under 16 MiB | 8 MiB |
| Scan 100,000 x 512 under `memory_limit = 64M` | completes | completes in 7.9 s, 10 MiB added |
| Peak memory added by a 100,000-record build from a generator | under 16 MiB | below the 2 MiB allocator step (16.3 s) |
| Fallback scan memory, 100,000 x 512, pages of 400 | one page plus the top-k | 4 MiB added (15.2 s) |

The memory budgets are asserted by `tests/MemoryBudgetTest.php`; timing is printed, not asserted, because shared CI runners are noisy. The scan is linear: 100,000 x 512 takes about ten times as long as 10,000.

## Limitations

- Exact linear scan only. Good for hundreds to about 100,000 vectors. Above that, or if you need filters inside the search, use a real vector index.
- No metadata filtering in the scan: over-fetch and filter, or build one file per filter.
- No appends, updates or deletes: the file is always rebuilt whole.
- Ids and refs are unsigned 32-bit integers in format version 1.
- 64-bit PHP only. Local POSIX filesystems only for concurrent build and scan; on Windows, renaming over a file a reader holds open can fail, which is reported as `BuildFailedException` and leaves the old file.
- The library never produces embeddings and writes no logs itself; the reports carry what you need to log.

## FAQ

**Why not keep the vectors in the database?** Decoding a JSON vector costs about 1 ms; at a few thousand rows a request spends seconds just decoding, and fetching them all at once runs out of memory. The database stays the source of truth; this file is derived data.

**Is an empty file an error?** No. A build with no good records writes a valid empty file, and a scan of it returns no hits. Missing is different: that throws `FileMissingException`.

**What happens if the embedding model changes?** The dimension changes, so a scan throws `DimensionMismatchException` (an `UnavailableException`): fall back, and rebuild once the source is re-embedded. The scanner never truncates or pads a vector.

**Can two jobs build at once?** The lock makes the second one return straight away with `skippedForLock() === true`. Set `lockWaitSeconds` to wait instead. With the lock off, the last rename wins and both files were complete.

**Do readers need the lock or write access?** No. Scanners only open the file for reading.

**Why are some scores exactly 1.0?** Float32 rounding can push a self-match a hair above 1; scores are clamped to the range -1 to 1.

**Can I turn off the checksum?** Yes, `verifyChecksum: false`, but the cost is small next to the arithmetic. With it on, a damaged file returns nothing rather than wrong neighbours.

**How do I check a file by hand?** `vendor/bin/packed-vectors verify <file>`.

## Licence

MIT. See [LICENSE](LICENSE). Contributions welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues as described in [SECURITY.md](SECURITY.md).
