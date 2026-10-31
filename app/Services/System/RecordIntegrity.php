<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;

class RecordIntegrity
{
    /** @return array{ok:bool,summary:array,checks:array<string,int>} */
    public function inspect(): array
    {
        return app(OperationGuard::class)->administrative(fn () => DB::connection()->transaction(function (): array {
            $queries = [
                'catalog_lock' => "SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM catalog_locks WHERE name='identifiers')",
                'sales_without_lines' => 'SELECT s.id FROM sales s WHERE NOT EXISTS (SELECT 1 FROM sale_lines l WHERE l.sale_id=s.id)',
                'sales_without_payments' => 'SELECT s.id FROM sales s WHERE NOT EXISTS (SELECT 1 FROM payment_records p WHERE p.sale_id=s.id)',
                'sale_arithmetic' => 'SELECT id FROM sales WHERE subtotal_centavos + tax_centavos <> total_centavos + discount_centavos',
                'sale_line_amount' => 'SELECT id FROM sale_lines WHERE quantity < 1 OR quantity * price_centavos <> total_centavos + discount_centavos',
                'sale_line_summary' => 'SELECT s.id FROM sales s LEFT JOIN (SELECT sale_id,SUM(quantity * price_centavos) AS gross,SUM(discount_centavos) AS discount,SUM(total_centavos) AS net FROM sale_lines GROUP BY sale_id) l ON l.sale_id=s.id WHERE s.subtotal_centavos<>COALESCE(l.gross,0) OR s.discount_centavos<>COALESCE(l.discount,0) OR s.total_centavos<>COALESCE(l.net,0)+s.tax_centavos',
                'payment_amount' => 'SELECT p.id FROM payment_records p JOIN sales s ON s.id=p.sale_id WHERE p.amount_centavos<>s.total_centavos OR p.verified<>1',
                'payment_change' => "SELECT id FROM payment_records WHERE (method='cash' AND (received_centavos<amount_centavos OR received_centavos<>amount_centavos+change_centavos)) OR (method<>'cash' AND (received_centavos<>amount_centavos OR change_centavos<>0 OR reference IS NULL OR TRIM(reference)=''))",
                'cart_state' => "SELECT s.id FROM sales s JOIN carts c ON c.id=s.cart_id WHERE c.status<>'completed'",
                'orphan_completed_cart' => "SELECT c.id FROM carts c WHERE c.status='completed' AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.cart_id=c.id)",
                'stock_balance' => 'SELECT v.id FROM variants v LEFT JOIN (SELECT variant_id,SUM(delta) AS balance FROM stock_movements GROUP BY variant_id) m ON m.variant_id=v.id WHERE v.stock<>COALESCE(m.balance,0)',
                'movement_balance' => 'SELECT v.id FROM variants v JOIN stock_movements m ON m.id=(SELECT MAX(last_m.id) FROM stock_movements last_m WHERE last_m.variant_id=v.id) WHERE m.balance_after<>v.stock',
                'return_quantity' => "SELECT l.id FROM sale_lines l JOIN (SELECT rl.sale_line_id,SUM(rl.quantity) AS returned FROM return_lines rl JOIN return_requests r ON r.id=rl.return_request_id WHERE r.status='accepted' GROUP BY rl.sale_line_id) a ON a.sale_line_id=l.id WHERE a.returned>l.quantity",
                'return_restock' => 'SELECT id FROM return_lines WHERE restock_quantity>quantity',
                'refund_limit' => "SELECT r.id FROM return_requests r JOIN (SELECT return_request_id,SUM(amount_centavos) AS reserved FROM refund_records WHERE status IN ('paid','pending') GROUP BY return_request_id) f ON f.return_request_id=r.id LEFT JOIN (SELECT return_request_id,SUM(refundable_centavos) AS eligible FROM return_lines GROUP BY return_request_id) l ON l.return_request_id=r.id WHERE f.reserved>COALESCE(l.eligible,0) OR r.status<>'accepted'",
                'sale_refund_limit' => "SELECT s.id FROM sales s JOIN (SELECT r.sale_id,SUM(f.amount_centavos) AS reserved FROM refund_records f JOIN return_requests r ON r.id=f.return_request_id WHERE f.status IN ('paid','pending') GROUP BY r.sale_id) f ON f.sale_id=s.id WHERE f.reserved>s.total_centavos",
                'refund_paid_at' => "SELECT id FROM refund_records WHERE status='paid' AND paid_at IS NULL",
                'ambiguous_codes' => 'SELECT a.id FROM variants a JOIN variants b ON a.sku=b.barcode AND a.id<>b.id JOIN products pa ON pa.id=a.product_id JOIN products pb ON pb.id=b.product_id WHERE a.archived_at IS NULL AND b.archived_at IS NULL AND pa.archived_at IS NULL AND pb.archived_at IS NULL',
            ];
            $checks = [];
            foreach ($queries as $name => $sql) {
                $checks[$name] = (int) DB::selectOne('SELECT COUNT(*) AS violations FROM ('.$sql.') integrity_issues')->violations;
            }
            $dates = DB::table('sales')->selectRaw('MIN(created_at) AS first_sale_utc,MAX(created_at) AS latest_sale_utc')->first();

            return ['ok' => array_sum($checks) === 0, 'summary' => ['sales' => DB::table('sales')->count(), 'variants' => DB::table('variants')->count(), 'returns' => DB::table('return_requests')->count(), 'first_sale_utc' => $dates->first_sale_utc, 'latest_sale_utc' => $dates->latest_sale_utc], 'checks' => $checks];
        }));
    }
}
