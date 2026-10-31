<?php

namespace App\Http\Controllers;

use App\Services\Reporting\DashboardQuery;
use App\Services\Reporting\ReportingContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardQuery $query, ReportingContext $periods): mixed
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $filters['date'] ?? now()->timezone('Asia/Manila')->format('Y-m-d');
        try {
            return DB::transaction(function () use ($request, $query, $periods, $date) {
                $data = $query->for($request->user(), $date);

                $context = $periods->for($request->user(), ['start' => $date, 'end' => $date]);

                return view('dashboard.index', compact('date', 'data', 'context'));
            });
        } catch (QueryException $error) {
            report($error);

            return response()->view('dashboard.index', ['date' => $date, 'unavailable' => true], 503);
        }
    }
}
