<?php

namespace App\Http\Controllers;

use App\Models\CustomerInquiry;
use App\Services\Customer\InquiryService;
use Illuminate\Http\Request;

class CustomerInquiryController extends Controller
{
    public function index(Request $request): mixed
    {
        $filters = $request->validate(['status' => ['nullable', 'in:new,handled,all']]);
        $status = $filters['status'] ?? 'new';
        $inquiries = CustomerInquiry::with('handler:id,name')->when($status !== 'all', fn ($query) => $query->where('status', $status))->latest('id')->paginate(15)->withQueryString();

        return response()->view('inquiries.index', ['inquiries' => $inquiries, 'status' => $status, 'newCount' => CustomerInquiry::where('status', 'new')->count()])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, CustomerInquiry $inquiry, InquiryService $service): mixed
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:new,handled']]);
        $service->handle($request->user(), $inquiry, (int) $data['version'], $data['status']);

        return $request->expectsJson() ? response()->json(['message' => 'Inquiry updated.'])->header('Cache-Control', 'private, no-store') : back()->with('status', 'Inquiry updated.');
    }
}
