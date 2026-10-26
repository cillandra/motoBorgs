<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Catalog\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_replacement_retains_the_previous_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('products/original.png', 'existing image');
        $product = Product::factory()->create(['image_path' => 'products/original.png']);
        try {
            app(ProductImageService::class)->replace($product, UploadedFile::fake()->create('malicious.jpg', 1, 'image/jpeg'));
            $this->fail('An undecodable file was accepted.');
        } catch (ValidationException) {
            $this->assertSame('products/original.png', $product->fresh()->image_path);
            Storage::disk('local')->assertExists('products/original.png');
        }
    }

    public function test_valid_png_is_stored_with_safe_filename_without_distortion(): void
    {
        Storage::fake('local');
        $product = Product::factory()->create();
        $path = app(ProductImageService::class)->replace($product, UploadedFile::fake()->image('photo.png', 80, 60));
        $this->assertStringStartsWith('products/', $path);
        $this->assertStringNotContainsString('photo.png', $path);
        Storage::disk('local')->assertExists($path);
        $size = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([80, 60], [$size[0], $size[1]]);
        $this->assertSame($path, $product->fresh()->image_path);
    }

    public function test_image_over_five_megabytes_is_rejected(): void
    {
        Storage::fake('local');
        $this->expectException(ValidationException::class);
        app(ProductImageService::class)->replace(Product::factory()->create(), UploadedFile::fake()->image('photo.png')->size(5121));
    }
}
