<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Setting;
use App\Services\System\OperationGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductImageService
{
    public function replace(Product $product, UploadedFile $file): string
    {
        return app(OperationGuard::class)->write(fn () => $this->replaceGuarded($product, $file));
    }

    private function replaceGuarded(Product $product, UploadedFile $file): string
    {
        $maximum = max(1, min(5, (int) (Setting::where('section', 'inventory')->first()?->value['max_image_mb'] ?? 5)));
        $mime = $file->getMimeType();
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        if ($file->getSize() > $maximum * 1024 * 1024 || ! isset($extensions[$mime]) || ! in_array(strtolower($file->getClientOriginalExtension()), $extensions[$mime], true)) {
            throw ValidationException::withMessages(['image' => 'Use a JPG, PNG or WebP image no larger than '.$maximum.' MB.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] > 4096 || $size[1] > 4096) {
            throw ValidationException::withMessages(['image' => 'The image could not be decoded or exceeds 4096 × 4096 pixels.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['image' => 'The image could not be decoded.']);
        }
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $safeBytes = ob_get_clean();
        imagedestroy($image);
        $path = 'products/'.Str::uuid().'.png';
        $old = $product->image_path;
        if (! Storage::disk('local')->put($path, $safeBytes)) {
            throw new RuntimeException('The product image could not be saved.');
        }
        try {
            app(OperationGuard::class)->transaction(function () use ($product, $path, $old): void {
                $product->update(['image_path' => $path]);
                if ($old && $old !== $path) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($old));
                }
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return $path;
    }
}
