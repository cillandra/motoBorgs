# MOTO local development environment

- Project: `C:\xampp\htdocs\moto`, independent Git repository on `feat/moto-demonstration`.
- PHP: 8.4.27, official x64 NTS release, checksum-verified, installed in `C:\xampp\moto-runtime\php84`.
- Composer: 2.10.3 at `C:\xampp\moto-runtime\composer.phar`; installer checksum matched the official Composer download page.
- Laravel: 13.38.0; exact package versions are locked in `composer.lock`.
- Database: MariaDB 10.4.32 binaries, separate data directory `C:\xampp\moto-runtime\mysql-recovered-20261102-2220` (selected by `C:\xampp\moto-runtime\mysql-data\my.ini`), loopback-only port 3307. This is an academic development instance, not a production deployment.
- Runtime account: DML-only on `moto_demo`; migration/recovery account: only the MOTO demo and restore-candidate schemas. Test account: only the three MOTO test/benchmark schemas. Secrets are private environment/runtime files, never versioned.
- Frontend: bundled Node and pnpm 11.25.0. Use pnpm because npm is not installed; `pnpm-lock.yaml` records exact dependencies.

The pre-existing database instance on 3306 returned an Aria checksum error while applying a MOTO grant. No repair of its system tables or existing application records was attempted. The first setup attempt created empty `moto_*` databases/accounts there before failing. Do not use those for MOTO. That server, initially stopped, was stopped again after creating the independent instance.

Start the app with `powershell -ExecutionPolicy Bypass -File scripts\start-demo.ps1` from the MOTO root. It checks PHP/assets, starts the separate database if necessary, and serves the public directory at `http://127.0.0.1:8000`. Stop the web server with Ctrl+C. Stop only MOTO's database with `C:\xampp\mysql\bin\mysqladmin.exe --defaults-extra-file=C:\xampp\moto-runtime\mysql-admin.ini shutdown`.

Use the dedicated PHP executable for Artisan, Composer and tests; do not use XAMPP PHP 8.2.12. Schema setup uses `php artisan migrate --database=maintenance --force`; normal runtime uses restricted credentials. Tests use `.env.testing` and `moto_test`. Never run test reset commands against demo or unrelated schemas.

The root `.htaccess` rejects Apache requests to project source. Use the dedicated localhost URL; a later Apache/LAN configuration must set its document root to `public/` and serve HTTPS. No firewall or LAN exposure has been configured.

Verified recovery on 3 November 2026 preserved all business-row fingerprints across 23 populated tables, including 145 sales, 22 variants and one inquiry. Two personal preferences were restored from the verified prior backup. All 31 MOTO tables passed checks and a new backup was verified. The damaged prior directory and private pre-repair dump remain preserved; unrelated databases were not modified.
