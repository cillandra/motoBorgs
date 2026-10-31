<?php

namespace App\Http\Controllers;

use App\Models\SaleLine;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\ReportFilter;
use App\Services\Reporting\ReportingContext;
use App\Services\Reporting\ReportQuery;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private function data(ReportFilter $filter, ReportQuery $query): array
    {
        return ['summary' => $query->summary($filter), 'products' => $query->products($filter), 'payments' => $query->payments($filter), 'returns' => $query->returns($filter), 'timeline' => $query->timeline($filter, $filter->start === $filter->end), 'daily' => $query->timeline($filter)];
    }

    public function index(Request $request, ReportQuery $query, ReportingContext $periods): mixed
    {
        $options = $request->validate(['tab' => ['nullable', 'in:overview,summary,payments,products,returns'], 'sort' => ['nullable', 'in:net_desc,net_asc,units_desc']]);
        $filter = ReportFilter::from($request->query());
        $filters = array_merge($filter->values(), $options);
        $tab = $options['tab'] ?? 'overview';
        try {
            return DB::transaction(function () use ($request, $query, $periods, $filter, $filters, $tab, $options) {
                $data = $this->data($filter, $query);
                if (($options['sort'] ?? 'net_desc') !== 'net_desc') {
                    usort($data['products'], fn ($a, $b) => ($options['sort'] === 'units_desc' ? ($b['net_units'] <=> $a['net_units']) : ($a['net_sales'] <=> $b['net_sales'])) ?: ($a['variant_id'] <=> $b['variant_id']));
                }
                if ($request->expectsJson()) {
                    return response()->json($data);
                }
                $categories = SaleLine::select('category_id_snapshot', 'category_name')->whereNotNull('category_id_snapshot')->distinct()->orderBy('category_name')->get();
                $variants = SaleLine::select('variant_id', 'sku', 'product_name', 'variant_name')->distinct()->orderBy('product_name')->get();

                $context = $periods->for($request->user(), $filters);

                return view('reports.index', compact('filter', 'filters', 'tab', 'data', 'categories', 'variants', 'context'));
            });
        } catch (QueryException $error) {
            report($error);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Report data unavailable. Retry with the same filters.'], 503);
            }

            return response()->view('reports.index', compact('filter', 'filters', 'tab') + ['unavailable' => true], 503);
        }
    }

    public function export(Request $request, ReportQuery $query, CsvExporter $csv): mixed
    {
        $options = $request->validate(['type' => ['nullable', 'in:summary,products,payments,returns']]);
        $filter = ReportFilter::from($request->query());
        $type = $options['type'] ?? 'summary';
        try {
            return DB::transaction(function () use ($query, $filter, $type, $csv) {
                if ($type === 'summary') {
                    $rows = [];
                    foreach ($query->summary($filter) as $metric => $value) {
                        $rows[] = [$metric, $value];
                    }$columns = ['Metric (amounts in centavos)', 'Value'];
                } else {
                    $rows = $query->{$type}($filter);
                    $columns = match ($type) {
                        'products' => ['variant_id', 'sku', 'product_name', 'variant_name', 'category_name', 'gross_sales_centavos', 'discounts_centavos', 'paid_refunds_centavos', 'net_sales_centavos', 'sold_units', 'returned_units', 'net_units'],
                        'payments' => ['method', 'label', 'recorded_sales_centavos', 'paid_refunds_centavos', 'net_sales_centavos', 'completed_count'],
                        'returns' => ['event', 'return_id', 'sale_id', 'date_manila', 'product_name', 'sku', 'quantity', 'restock_quantity', 'approved_value_centavos', 'paid_refunds_centavos', 'condition', 'reviewer', 'reference'],
                    };
                }

                return $csv->stream('moto-'.$type.'-'.$filter->start.'-to-'.$filter->end.'.csv', $columns, $rows);
            });
        } catch (QueryException $error) {
            report($error);

            return response()->view('reports.export-error', ['filters' => $filter->values(), 'type' => $type], 503);
        }
    }

    public function print(Request $request, ReportQuery $query): mixed
    {
        $filter = ReportFilter::from($request->query());
        try {
            return DB::transaction(function () use ($query, $filter) {
                return view('reports.print', ['filter' => $filter, 'data' => $this->data($filter, $query)]);
            });
        } catch (QueryException $error) {
            report($error);

            return response()->view('reports.export-error', ['filters' => $filter->values(), 'type' => 'summary'], 503);
        }
    }
}
