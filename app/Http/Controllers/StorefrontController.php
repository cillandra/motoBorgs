<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Customer\InquiryService;
use App\Services\Customer\StorefrontCatalog;
use App\Services\Customer\StorefrontService;
use App\Services\System\RecoveryFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StorefrontController extends Controller
{
    public function index(Request $request, StorefrontService $storefront, StorefrontCatalog $catalog): mixed
    {
        try {
            $filters = $request->validateWithBag('catalog', ['search' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer', 'exists:categories,id'], 'availability' => ['nullable', 'in:all,available'], 'sort' => ['nullable', 'in:available,name,price_asc,price_desc'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        } catch (ValidationException $error) {
            $error->redirectTo = route('storefront.index');
            throw $error;
        }
        $products = $catalog->search($filters);
        if ($products->currentPage() > $products->lastPage()) {
            return redirect()->route('storefront.index', array_filter(array_replace($filters, ['page' => $products->lastPage()]), fn ($value) => $value !== null))->withFragment('products');
        }
        $shop = $storefront->content();

        return response()->view('storefront.index', ['products' => $products, 'categories' => $catalog->categories(), 'filters' => $filters, 'shop' => $shop, 'services' => $storefront->services()])->header('Cache-Control', 'no-store');
    }

    public function submit(Request $request, InquiryService $service): mixed
    {
        try {
            $inquiry = $service->submit($request->all());
        } catch (ValidationException $error) {
            $error->errorBag = 'inquiry';
            throw $error;
        }
        $message = 'Your inquiry is saved as '.$inquiry->reference().'. The shop can follow up using your phone number or email. Keep this reference for your concern.';

        return $request->expectsJson()
            ? response()->json(['reference' => $inquiry->reference(), 'message' => $message], $inquiry->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'no-store')
            : redirect()->route('storefront.index')->withFragment('contact')->with('inquiry_status', $message);
    }

    public function image(Product $product): mixed
    {
        abort_unless(! $product->archived_at && $product->variants()->whereNull('archived_at')->exists(), 404);
        abort_unless($product->image_path && app(RecoveryFiles::class)->imageName($product->image_path) && Storage::disk('local')->exists($product->image_path), 404);

        return response()->file(Storage::disk('local')->path($product->image_path), ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}
