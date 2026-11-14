# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Packed vector file format version 1: 40-byte header with magic, version,
  flags, dim, count, build time, payload and header CRC-32, then fixed-size
  records of a 32-bit id, a 32-bit ref and float32 little-endian values.
- `Builder`: atomic rebuild from any iterable in bounded memory, with
  checked writes, file mode set before data, exclusive-create temporary
  files, fsync of the file and the directory, an advisory build lock with an
  optional wait, stale temporary cleanup, optional normalisation, and a
  skip-or-throw bad-record policy with counts by reason.
- `Builder::rebuildIfStale()` and `staleReason()`: rebuild when missing,
  corrupt, of another version, dimension or normalisation, or too old.
- `Scanner`: exact cosine top-k in bounded memory with a min-heap, minimum
  score, best-per-ref mode, payload checksum verification, a time budget,
  and a report; `info()` and `verify()`.
- `ChunkedScanner`: fallback over caller pages with keyset pagination and
  the same ranking rules.
- Typed exceptions, all implementing `PackedVectorsError`; the "fall back
  and rebuild" family implements `UnavailableException`.
- `bin/packed-vectors info` and `verify`.
- `bootstrap.php` for use without Composer, with a 64-bit guard.
- Examples, a benchmark script, and tests for every PRD acceptance case.

[Unreleased]: https://github.com/IndianOpinion/packed-vectors/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/packed-vectors/releases/tag/v0.1.0
