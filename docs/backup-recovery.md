# Backup and recovery

MOTO creates real local ZIP snapshots through **Settings → Backup & recovery → Create backup**. The Manager can inspect and download completed snapshots in a modal. The archive contains the current schema, business records, employee password hashes and private product images. It excludes active sessions, reset codes, queued jobs and cache data. Environment credentials, APP_KEY, database client options and server paths are never included in the manifest. Treat every archive as private; it is signed, not encrypted.

Each file has a SHA-256 checksum. An HMAC signature binds the manifest to this installation's APP_KEY. The archive checksum is retained in a separately signed local catalog. Only the current compatible schema can be restored. Missing files, altered bytes, unexpected paths, links, duplicates and oversized archives are rejected. Limits are 256 MiB compressed, 512 MiB expanded and 20,002 entries. Copy the archive to a trusted separate drive; a copy on the same computer does not protect against device loss. Preserve the installation APP_KEY separately in a secure location; source control and screenshots are not suitable places for it.

Seven completed regular backup files are retained. Failed attempts remain visible. Retention never deletes sales or activity from the live database. A pre-restore **safety snapshot** and original image directory are retained separately and are not removed by regular retention. Review disk use with the local administrator before removing historical safety copies; never remove a copy referenced by a pending recovery journal.

## Restore

1. Finish any unconfirmed checkout; use its existing attempt status to determine whether it completed.
2. Open **Restore from backup / Inspect** for an existing completed local snapshot. Review its record counts and later-sale impact. Later records leave the active system when the snapshot is restored; the safety snapshot retains the pre-restore state.
3. Enter the current Manager passphrase and type `RESTORE`. The app waits for active writes, verifies the archive, imports a candidate into a separate fixed database and checks its schema, counts, product-image references and stock ledger. It then makes a safety snapshot.
4. The app replaces records in one InnoDB transaction using the unchanged schema. Product images switch under an exclusive filesystem operation lock. A signed journal outside the restored database and images tracks recovery. A marker committed with the data distinguishes completed transactions from transactions rolled back after interruption. Original images and the safety archive remain available.
5. Sign in again using credentials valid at the snapshot time. Sessions, password-reset grants and unused approvals are revoked. Confirm sales, stock/ledger, images and reports before resuming the demonstration.

This local build uses fixed `moto_demo` / `moto_restore_candidate` targets; tests use only disposable MOTO schemas. The runtime account cannot change schema. Recovery uses the separately scoped maintenance account. Database clients use argument arrays and short-lived private option files, never passwords in process arguments. Do not expose database port 3307 or the private backup directory on the LAN.

## Interrupted recovery

Do not delete `.runtime/restore-journal.json`, operation locks, safety archives or retained image directories. Pending recovery blocks business writes and session persistence. From the independent MOTO project, run:

```powershell
& 'C:\xampp\moto-runtime\php84\php.exe' artisan moto:recovery-resume
```

The command obtains the exclusive lock, verifies the journal and checks the transaction marker. If the transaction committed, it finishes session revocation and the durable recovery event. Otherwise, it restores the original image directory and retains current database records. A repeated successful command is harmless. If it reports failure, keep the journal and all safety files; inspect the private application log and database service locally. Do not bypass the block by deleting the journal. Recovery history is retained outside the restored database in `.runtime/recovery-history.jsonl` and a completion event is appended to the restored activity history.

The tests exercise before-swap failure, a successful isolated round-trip, interruption after commit, resumed completion, stale sessions, checksums and concurrent operation locks. Abrupt host power-loss behavior and physical drive failure still require a separate recovery rehearsal; no local snapshot replaces an off-device copy.

## Maintenance

**Settings → System & maintenance → Change maintenance mode** opens a protected modal. Enter the current Manager passphrase and type `MAINTENANCE`. Enabling waits for active operations and pauses business writes. Managers keep sign-in, Settings and recovery access. Disabling is a separate protected action. A pending restore journal must be resolved before maintenance can be changed.

## Optional Windows scheduler

Automatic backups are **off** until an actual Windows Task Scheduler task is installed, its action is verified and a recent heartbeat is observed. This development run does not install that OS task. To install later, execute locally under the intended Windows account:

```powershell
powershell.exe -NoProfile -File 'C:\xampp\htdocs\moto\scripts\install-backup-schedule.ps1'
& 'C:\xampp\moto-runtime\php84\php.exe' 'C:\xampp\htdocs\moto\artisan' moto:backup-scheduler --verify
```

The task invokes `artisan schedule:run` every minute with the dedicated PHP executable and the MOTO working directory. It stores no account password. Check the heartbeat after one minute, then enable automatic backups in Manager Settings. The default backup time is **20:00 Asia/Manila**; the saved local time is respected. The computer, Windows login task and local database must be running. Missing/stale heartbeat disables scheduled backup claims; the UI reports missed or failed attempts. On-demand Manager backups work without the scheduler or internet. The scheduled CLI is explicitly audited as System with its local OS identity.

For removal, disable automatic backups in Settings and remove only the `MOTO-Local-Backups` task through Windows Task Scheduler. No chat automation is involved.
