# MOTO implementation specification

Prepared: 25 October 2026, Asia/Singapore. Delivery target: 30 October 2026.
Status: approved by the user on 25 October 2026; native implementation is underway.

## 1. Intended result and confirmed decisions

Build a working school demonstration of Management of Transactions and Order for BORG'S Motorcycle Parts and Accessories. Include every application page/menu in the Figma design and the applicable features in the project documentation. Use one branch, two fixed roles (Manager and Staff), a new database and demonstration records. This is a functioning database application, not a collection of linked mockups.

The user accepted PHP/Laravel, a compatible updated PHP environment and a separate project at `C:\xampp\htdocs\moto`. The existing `C:\xampp\htdocs\lpg_store` directory is a read-only layout reference: do not create files there, edit its files, copy its code/branding or reuse its database.

Initially one computer hosts the app, database and browser. Design for multiple browser clients using that host over an existing LAN. No paid hosting/domain or required subscription. Internet is unnecessary for normal application operation after dependencies/assets are installed. LAN clients require connectivity to the host; disconnected-client transaction synchronization is excluded. The host must remain on and awake.

Payments are recorded and manually verified; there is no gateway connection. There is currently no barcode scanner or printer. Implement SKU entry, barcode lookup where catalog values exist, receipt preview and browser printing/PDF. Prepare 80 mm and 58 mm print layouts; do not claim physical hardware verification.

## 2. Sources and precedence

- [Figma design](https://www.figma.com/design/8Lj1mwqXcx9DO8aQ4iRla8): Foundations `0:1`, Components `19:2`, Prototype guides `95:2`, Manager `255:175130`, Staff `255:175131`.
- [Project documentation](https://docs.google.com/document/d/1-VKiacgPvfVMCywBqZLlFFFsECFzW9P2zaXbDKbVvgw/edit), read on 25 October 2026, including operating rules and critical acceptance scenarios.
- The user's decisions in this chat take precedence. Figma governs appearance and navigation; the documentation supplies business rules and real behavior beyond preset prototype examples. Any material conflict found during implementation is recorded for resolution rather than silently changing scope.

Live Figma inspection confirmed Login, Dashboard, POS/cart/payment/receipt/approvals/holds, Inventory/details/forms/stock/archive, Sales history/returns, Reports, Management, Activity and Settings. Manager Settings contains 59 frames; Staff Settings contains 25. Prototype sample data, identities and arbitrary canvas text are not application requirements. Example frames become states of reusable components and real workflows, not hundreds of separate hardcoded routes.

## 3. Technical architecture

- PHP 8.4, latest compatible patched release at installation; Laravel 13; Composer 2. Pin installed dependencies in lockfiles.
- Blade templates, semantic HTML, CSS variables mapped from Figma tokens and focused JavaScript modules. Use Vite for local build assets. No separate frontend application/API deployment is needed.
- MariaDB/InnoDB with Laravel migrations, explicit foreign keys, unique constraints and indexes. Use a new MOTO database and a dedicated application database account.
- The installed XAMPP database client identifies MariaDB 10.4.32; verify the actual running server and transaction behavior before migrations. Its reuse is limited to the local school demonstration. Operational deployment requires a maintained database/server environment and a separate review.
- Installed XAMPP PHP is 8.2.12 and cannot run Laravel 13. Provide a separate compatible PHP runtime rather than disrupting the LPG reference's runtime. Composer/PHP are not currently on the command path. Node and Git are available.
- Controllers handle requests; validation classes validate input; authorization policies enforce role/ownership; application services own checkout, stock, approvals, refunds and recovery. Shared query services supply dashboards/reports/exports.
- Server-rendered routes provide pages; authenticated JSON endpoints support POS lookup/cart operations and incremental UI updates. Session authentication and CSRF protection apply to both.
- Database sessions permit account deactivation/password changes to revoke access. Every request checks active status and role. Scheduled tasks use the host's local scheduler; no cloud scheduler is required.
- Fonts, icons, chart assets and scripts are served locally. Avoid CDNs, remote font requests and online-only validation. Use a locally bundled weak-password list for the demonstration.
- Server owns prices, calculations and state transitions. Browser values are requests, not trusted transaction data.
- Display Philippine pesos (PHP), use Asia/Manila business dates and consistent date-range boundaries, and store timestamps consistently in UTC.

## 4. Pages and role boundaries

| Area | Manager | Staff | Required behavior |
|---|---|---|---|
| Authentication | Own account | Own account | Required-field errors, generic rejection, throttling, session expiry, logout |
| Dashboard | Shop summaries | Own sales summaries | Database totals, charts, recent permitted records, stock alerts, loading/empty/failure states |
| Sales/POS | Sell and approve exceptions | Sell and request approval | Search/filter/sort, exact variants, quantities, holds, discounts, draft cancellation, payment, receipt |
| Sales history | All shop sales | Own sales | Combined filters, details, authorized reprints, return requests and refund status |
| Inventory | Maintain products and stock | Read active availability/prices | Cards/table, search/filter/sort, variant details; Manager forms, movements and archive/restore |
| Reports/analytics | View/export | No access | Overview, sales summary, payment breakdown, products and returns; date/product/SKU/category filters |
| Management hub | Manage accounts | No access | Search/filter, create/edit, fixed role assignment, deactivate/reactivate, assisted reset |
| Employee activity | View/export | No access | Employee/date/action/module/outcome filters, search, pagination, safe details |
| Settings | Global settings and own preferences/account | Own preferences/account and About | Real save/discard, validation, unsaved-change guards and role-appropriate navigation |

Manager global Settings covers General, Sales & payments, Receipts & printing, Inventory rules, Notifications, Security & access, Backup & recovery, System & maintenance. Own Preferences and My account appear for both roles. Preferences include theme, density, rows per page, reduced motion and sound where the application supplies audio feedback. Disabled sound means no app-generated sound; theme/density must apply consistently across the real app.

Common UI includes collapsed/expanded sidebar, topbar/profile menu, tabs, filters, tables/cards, pagination, dialogs, toasts, validation, skeletons and recovery states. Match Figma typography, spacing, colors and component geometry. Inspect individual design contexts and screenshots before implementing each module. Verify primarily at 1440×900 and 1280×720; no dedicated mobile/tablet interface is in scope.

## 5. Domain model and integrity

Core records: employees, sessions, products, sellable variants, categories, units, compatibility metadata, image metadata, carts/cart lines, approvals, sales/sale lines, payment records, stock movements, returns/return lines, refund records, audit events, notifications, global settings, user preferences and backup manifests.

- Each exact variant has a permanent identifier and globally unique SKU. Optional barcodes are unique when present. Keep product grouping separate from sellable variants. Store quantity in integer pieces, sets, pairs or sealed bottles; no bulk fractional units.
- Store money as integer centavos with one shared calculation/rounding policy. Snapshot SKU, product/variant name, category, unit, price, discount, cashier and configured tax treatment on sale records. Allocate cart discounts deterministically to lines so partial refunds reconcile exactly.
- An opening balance is a recorded stock movement. Receives, count adjustments, sales and eligible returns append movements; no direct editable balance without a movement. Cached balances reconcile to the ledger. Negative stock is prohibited.
- Archive products and deactivate employees rather than deleting history. Archived identities/SKUs are never reused. Staff cannot retrieve costs, other employees' transactions or global controls through endpoints/exports.
- Reports aggregate completed sales and accepted returns/verified refund payouts. Gross merchandise sales, discounts, net sales, sold/returned/net units, completed-sale count and refund count have explicit labels. Net sales is not profit; omit profit unless a costing method is separately defined. Historical category filters use sale snapshots.
- Demo tax calculation starts disabled and is visibly labeled unconfigured for the academic demonstration; this is not a declaration of tax exemption. Receipts are operational previews, not claimed compliant tax invoices. No invented tax rate or legal invoice registration.

## 6. Checkout, approvals and recovery from failure

1. A cart belongs to its cashier; Staff access only their own carts. Holding does not reserve stock. Resume revalidates active variants, prices and stock and prompts about changes.
2. Staff request a restricted action with a reason. A current active Manager verifies as themselves without changing cashier identity. Approval is bound to the cart/action and its version, is single-use and expires after five minutes. Cart changes invalidate approvals that depend on its contents/totals. Default discount cap is 10%; higher discounts are rejected unless a separately validated policy permits them.
3. Cash received must cover the total; calculate change. GCash, QR Ph, debit and credit require a manual verification outcome and a non-sensitive reference. Do not store card credentials or treat customer screenshots as independent verification. An internet outage does not imply external payments succeeded.
4. Checkout uses one unique request key, server-calculated totals, InnoDB locking/guarded stock updates and a transaction that commits sale, lines, payment record, movements and critical audit event together. Lock variants in stable order; retry deadlocks in a bounded manner and revalidate after retry.
5. Identical repeated requests return the existing result; a reused key with different content is rejected. Timeouts offer status lookup/retry of that attempt, never instructions to recollect payment. The server prevents overselling even when another cashier changes stock after the page loaded.
6. Print happens after commit. Reprints retain the sale identifier and are marked copies. A print failure cannot create a second sale.
7. Draft cancellation changes no stock. A completed sale is immutable. Returns reference original sale lines and enforce remaining quantity/value. Only accepted sellable return quantities restock, once; damaged returns do not. Manager approval and confirmed external refund payout are separate states. Pending/failed payouts remain visible and do not reduce settled refunds; prevent a second payout record on retry.

## 7. Accounts, audit and settings

Use Laravel's supported password hashing, server sessions, request validation, parameterized data access, output encoding, CSRF protection and authorization policies. No public registration or customer accounts. Protect the last active Manager and prevent self-deactivation/demotion. Manager-assisted resets use a single-use local reset workflow and force password change; they never reveal old passwords and do not require email/internet.

Defaults: minimum 15-character passwords, support long passphrases with no silent truncation, 15-minute idle expiry, 8-hour absolute session limit, and bounded rate limiting with a proposed five-attempt/15-minute cooldown. Security-policy changes and sensitive operations require reauthentication. Account deactivation revokes sessions. Password change revokes other sessions and follows the Figma re-login flow. Security defaults can be made stricter but essential integrity/role restrictions cannot be disabled.

Audit records include actor, requester/approver where applicable, event type, module, record identifier, time, outcome and safe reason/change summary. Successful critical business events commit with their business transaction. Failure/denial security events must survive rollback of the attempted operation. Logs are read-only in the application; do not claim tamper-proof storage. Exclude secrets and sensitive payment data. CSV exports escape spreadsheet-formula injection and apply the same authorization and filters as the screen.

Global settings validate before save, preserve errors/unsaved changes, require at least one enabled payment method and record safe audit changes. Fixed rules include no negative stock, no Staff inventory writes and retained history. In-app notifications cover relevant stock, approval/return and system events; external email/SMS integrations are excluded. Maintenance pauses business writes while allowing designated Manager recovery actions.

## 8. Backup, maintenance and local deployment

Backups include database, product images and a versioned manifest/checksums; protect required configuration/secrets separately. Store files outside the web root, scoped to the new application. Manager-only creation/download/listing must not expose filesystem paths or credentials to Staff. Retain seven recent completed daily backups; never delete sales or audit records as backup retention.

Daily backup target is 20:00 Asia/Manila when the host is running. Show automation as disabled until the local scheduler is actually installed and verified. Record missed/failed jobs rather than claiming success. On-demand backup must work independently of scheduling. A same-disk backup is demonstration recovery, not protection from loss of the computer; document manual copying to existing off-device media when available.

Restore requires reauthentication, a maintenance/write exclusion lock shared with checkout, no active checkout transaction, a safety snapshot, manifest/checksum/version validation, and a restore into an isolated temporary database first. Check tables, record counts, sale/stock totals and images before replacing application state. Explain that later transactions will be lost; preserve a rollback route and record recovery outcome after database replacement. Test using disposable demonstration data only. Invalid or interrupted backups cannot appear complete.

Single-computer demonstration uses the separate modern PHP runtime and local application services. Document start/stop, database creation, migrations, assets and recovery. LAN setup keeps the database server private; browsers access the app host. Configure only the necessary private-network access, use HTTPS for credentials across the LAN, and test with separate sessions. Do not expose default XAMPP or database ports publicly. No LAN/firewall changes are part of writing this specification.

## 9. Verification and completion criteria

Maintain a route/action checklist covering every Figma module for both roles. Every presented menu opens an implemented view or a clear hardware-dependent action; no silent placeholders or simulated save-success for unimplemented actions. Demonstration fixtures are marked as examples; use real stored operations during the walkthrough.

Required automated and manual evidence:

- Login, expiry, deactivation, last-Manager protection and password/reset workflows.
- Direct Staff calls to Manager endpoints, other-cashier receipts, exports and hidden fields are denied without leaks.
- One complete sale produces matching history, receipt, stock ledger, dashboard, report and audit records.
- Two concurrent sessions buy the last unit: one succeeds; stock never becomes negative. Test against MariaDB, not only SQLite.
- Duplicate submission, timeout/status retry and print failure never duplicate a sale, stock deduction or refund.
- Hold/resume price/stock changes, invalid approval reuse and cancellation preserve balances.
- Partial/damaged returns, pending/failed/paid refunds and cart-discount allocation reconcile.
- Product images validate extension/MIME/decoding, support JPG/PNG/WebP up to 5 MB, use safe filenames and retain the previous image on rejection.
- Combined report/activity filters and exports match displayed records and totals; empty data and failed loads remain distinct.
- Backup creation, checksum rejection, isolated restore, safety copy and restoration reconciliation pass in a test environment.
- All pages/assets work after disconnecting internet; actual LAN operation is verified only when another client/network is available. Concurrent independent browser sessions provide an initial server test on one computer.
- Dark/Light, keyboard focus, dialogs, density, unsaved settings and representative page screenshots match the intended Figma states.

Suggested performance benchmark from the documentation: 10,000 variants, 100,000 sales, five simultaneous sessions; proposed p95 searches/pages within two seconds and server checkout commit within three seconds. These are measurement targets, not promises. Record dataset, machine/server versions and results. Use a separate generated benchmark dataset; do not bloat default demo seed data.

Deliverables: separate source project with lockfiles/migrations/tests, new database, deterministic demo seed data without shared production credentials, local compiled assets, installation/startup guide, role-based demonstration script, feature/verification checklist and tested backup/restore instructions. Report any unresolved feature or unverified hardware honestly.

## 10. Delivery sequence toward 30 October

This is a target schedule; full functionality and visual fidelity in five days is ambitious. Report concrete blockers promptly and do not silently drop modules or weaken stock/security rules to meet the date.

| Date | Target outcome |
|---|---|
| 25 October | Review specification, write implementation plan, then set up isolated runtime/project, migrations, Figma tokens/layout and authentication |
| 26 October | Working vertical slice: SKU lookup → cart → manual payment verification → one committed sale → ledger → receipt/history/audit |
| 27 October | Complete inventory/forms/images, stock movements, holds/approvals, returns/refunds and both role dashboards |
| 28 October | Reports/exports, management hub, employee activity and all role-appropriate Settings pages/actions |
| 29 October | Backup/restore and maintenance verification; concurrency/authorization/retry tests; complete visual integration and demonstration data |
| 30 October | Final regression, internet-disconnected walkthrough, setup/recovery rehearsal, screenshots and school demonstration handoff |

The next artifact is an executable implementation plan with dependencies, file/module boundaries and checks. It should preserve the complete scope above and be reviewed before product scaffolding/dependency installation starts.

## 11. Explicit exclusions and assumptions

No customer/public website, online orders, CRM, supplier/purchasing/delivery subsystem, cross-branch operations, attendance/payroll, dedicated shift/cash drawer, full accounting, payment gateway, cloud deployment or disconnected-client synchronization. These exclusions match the documentation and do not remove any approved internal Figma page.

Assume demonstration shop contact details and product images may use clearly identified examples until real authorized assets are supplied. No physical printer/scanner, live payment integration, legal invoice compliance or production readiness claim is required for October 30. Hardware compatibility and real business configuration are later validation work.

The deadline is a delivery target, not authorization to create a scheduled automation or claim background work continues after a chat turn ends.

Execution update, 26 October: the user clarified that this release is a controlled soft deployment for evaluation; the approved feature scope remains unchanged and demonstration data is the current baseline.

## User clarification — 26 October 2026
This is a school demonstration and project for now, superseding the earlier soft-deployment wording. Include all Figma pages and menus, with functioning features applicable to the current record-only payment setup and available hardware. Keep future hardware/provider integration boundaries visible without claiming they are connected.

Use modals for contextual product details, create/edit forms, receives/count adjustments, receipt previews, return/refund actions, employee actions and confirmations where applicable. Main modules remain sidebar destinations. Keep authorization, persisted records, stock/money integrity and recovery requirements. Reuse authenticated routes as full-page fallbacks; normal interactions retain the current module, filters and list position. Save validation stays inside the modal, successful writes refresh the underlying data, and unsaved edits require a discard choice.
