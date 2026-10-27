<?php

namespace App\Http\Controllers;

use App\Services\Reporting\DashboardQuery;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardQuery $query): mixed
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $filters['date'] ?? now()->timezone('Asia/Manila')->format('Y-m-d');
        try {
            $data = $query->for($request->user(), $date);

            return view('dashboard.index', compact('date', 'data'));
        } catch (QueryException $error) {
            report($error);

            return response()->view('dashboard.index', ['date' => $date, 'unavailable' => true], 503);
        }
    }
}
