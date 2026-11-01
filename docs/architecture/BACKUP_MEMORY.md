# Database backup — memory characterization (v1.171.4)

`BackupService::databaseDump()` builds the **entire** SQL dump in one PHP string, then gzips it (second buffer); a sealed download (`DumpSeal::seal`) adds a third. It is **not** a streaming backup. This document records measured behaviour; no backup-engine change was made in v1.171.4.

Tool: `FLX_CONFIG=<test-config> php -d memory_limit=<X> tools/dump_memory_check.php <rows> <payload_bytes>` (refuses any database not named `flx_*`).

Environment: Claude.ai sandbox — PHP 8.4.21, MariaDB 10.11, database `flx_memtest`, one scratch table of random (incompressible, worst-case) 400-byte binary rows. **EXECUTED by Claude.ai; not yet executed independently by Claude Code.**

| Database size (InnoDB data+index) | memory_limit | Dump output (gz) | Peak memory | Result |
|---|---|---|---|---|
| 10.5 MB | 128M | 8.7 MB | 30.3 MB | OK |
| 50.6 MB | 128M | — | — | **FATAL: memory exhausted** |
| 50.6 MB | 256M | 43.4 MB | 141.8 MB | OK |
| 151.7 MB | 256M | — | — | **FATAL: memory exhausted** |
| 151.7 MB | 512M | 130.3 MB | 422.8 MB | OK |

Rule of thumb from this test: **peak ≈ 2.8 × database size** on incompressible data (real, text-heavy data compresses better, but the uncompressed SQL string is still held in memory). The sealed download did not raise the peak above the plain dump in these runs.

Practical reading: uploaded documents live on disk (not in the database), so a credentialing-tracker database is normally small; a typical cPanel `memory_limit` of 128–256M covers databases up to roughly 40–90 MB. Above that, the download fails with a PHP fatal error.

**Limitation (documented, not fixed):** very large databases need a higher `memory_limit` or `mysqldump`. **Proposed separate remediation if a real tenant approaches this size:** table-by-table streaming to `php://output` with incremental gzip (`deflate_init`/`deflate_add`) and chunked AES-GCM framing for the sealed format — this changes the sealed-dump format and needs its own release and review.
