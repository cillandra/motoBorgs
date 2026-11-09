# Feature and verification checklist

The approved Figma flows are functioning modules. Repeated design frames are real component states. Main modules use sidebar navigation; contextual details, forms, receipts, approvals, returns and protected actions open as modals, with authenticated direct-route fallbacks.

| Figma area / actions | Access | Implementation / routes | Evidence |
|---|---|---|---|
| Login, show passphrase, logout, expired/disabled access | Both | AuthController, SessionService; `/login`, `/logout`, `/access/*` | AuthAccessTest; real Manager/Staff sign-in; passive idle-expiry regression |
| Shop and personal dashboards | Manager shop / Staff own | DashboardQuery; `/dashboard` | SalesDashboardTest; both role browser captures and true empty-day state |
| POS catalog, filters, exact SKU/barcode keyboard lookup | Both | CatalogQuery, CartService; `/pos*` | CatalogTest, CartApprovalTest; browser checks |
| Add/remove/quantity, hold/resume, normal cancel | Owner | CartService; `/pos/carts/*` | CartApprovalTest; persisted hold/resume rehearsal; stock not reserved |
| Discount/void approval, reason, expiry | Manager verifies; cashier retained | ApprovalService; cart approval endpoint | CartApprovalTest; version-bound/single-use approval and browser 10% example |
| Cash, GCash, QR Ph, debit/credit records; checkout status/retry | Both / own cart | CheckoutService; `/checkout/*` | CheckoutTest; exact retries, timeout recovery and independent last-unit race |
| Original/copy receipt, saved logo/footer, 58/80 mm and copies | Manager any / Staff own | ReceiptController; sale receipt route | CheckoutTest, SettingsTest, ModalFlowTest; receipt/sample modal previews. Physical output pending |
| Inventory cards/table, combined filters, active/low/archive | Both active; Manager archives | CatalogQuery; `/inventory` | CatalogTest; both views and roles in browser |
| Product/variants, categories/units, image validation | Manager edits; Staff safe read | ProductService, ProductImageService; `/products/*` | CatalogTest, ProductImageTest; modal add/edit/archive; duplicate SKU and invalid image retention |
| Receive, count/relative adjustment, movements | Manager | StockLedger; variant stock routes | StockLedgerTest; idempotence/stale/negative rejection; browser receive |
| Sale filters/detail/payment/receipt/history | Manager any / Staff own | SalesQuery; `/sales/*` | SalesDashboardTest; scoped totals/search and receipt modal |
| Return request, sellable/damaged review | Staff own request; Manager review | ReturnService; `/returns/*` | ReturnRefundTest; partial/mixed conditions, exact per-unit cents; browser request→accept |
| Separate pending/failed/paid payout record | Manager; Staff own outcome | RefundService; refund endpoint | ReturnRefundTest; stable reference/caps/terminal payout; browser same-record retry to paid |
| Overview, Sales summary, Payments, Products, Returns reports | Manager | ReportQuery / ReportFilter; `/reports` | ReportExportTest; Manila dates, snapshots and exact partial-payout allocation |
| Filtered CSV, printable report modal | Manager | CsvExporter; reports export/print | ReportExportTest; formula escaping and streamed content. Native file/PDF save pending |
| Employees, status, roles/permissions | Manager | EmployeeService; `/employees/*` | EmployeeActivityTest; self/last-Manager guards and concurrent-demotion workers |
| Assisted one-use recovery, required first change, revocation | Manager issues; employee chooses | RecoveryService; `/access/*` | EmployeeActivityTest; expiry/replay/supersession/forced routing and own-username logout |
| Activity combined filters/detail/safe CSV | Manager | ActivityQuery / AuditRecorder; `/activity/*` | EmployeeActivityTest, AuditRecorderTest; immutable dates and redaction; detail modal |
| General, Sales, Receipts, Inventory, Notifications, Security | Manager | SettingsService; `/settings` | SettingsTest; validated/versioned Save/Discard, inline errors and reauthentication |
| Preferences, My account, About | Both, own only | PreferenceService / AccountController | SettingsTest; theme/density/rows/motion/sound/session revocation; three Staff categories checked |
| In-app inbox and stock/request/system producers | Relevant recipient | NotificationService; `/notifications/*` | NotificationTest; dedupe, changed-role privacy, ownership/muting/idle expiry; real backup notification |
| Create/download/inspect/restore, safety copy | Manager | BackupService / RestoreService; `/backups/*` | BackupRestoreTest; corrupt/schema/path/link/size rejection; staged counts/stock/sale totals; isolated restore and resumed interruption; real UI backup |
| Maintenance, measured diagnostics | Manager | OperationGuard / DiagnosticsService | MaintenanceRaceTest; independent shared/exclusive workers, nested ownership; protected modal |
| Optional local daily scheduler, seven regular files | Manager policy / local OS | `moto:backup*`, installer | Install/heartbeat/enable tests and retention. OS task deliberately not installed |

RouteCoverageTest enumerates every named endpoint, checks its authentication/role contract, rejects guests for every private route and Staff for every Manager route, and renders both roles' main/contextual screens. Specialized tests exercise valid mutation payloads, ownership and business integrity. Public health reveals only application/database availability.

Available now: one-branch academic operation, Manager/Staff roles, new private database, manual payment records, browser receipt layouts and keyboard SKU lookup. Future validation: physical scanner/printer, live providers, compliant tax invoicing, second-device LAN/HTTPS and production hosting. No screen reports those integrations as completed.
