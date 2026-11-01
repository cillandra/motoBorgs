<?php

namespace Tests\Feature;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class InputAccessibilityTest extends TestCase
{
    public function test_nested_field_errors_are_attached_to_the_actual_input_name(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['variants.0.sku' => 'This SKU already belongs to another variant.']));
        view()->share('errors', $errors);
        $view = $this->blade('<x-input id="sku-0" name="variants[0][sku]" label="SKU" aria-describedby="sku-help" />', compact('errors'));
        $view->assertSee('aria-invalid="true"', false);
        $view->assertSee('aria-describedby="sku-help sku-0-error"', false);
        $view->assertSee('id="sku-0-error"', false);
        $view->assertSee('This SKU already belongs to another variant.');
    }

    public function test_renamed_input_uses_its_submission_name_for_validation(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Enter a product name.']));
        view()->share('errors', $errors);
        $view = $this->blade('<x-input id="product-name" name="name" label="Product name" />', compact('errors'));
        $view->assertSee('aria-invalid="true"', false);
        $view->assertSee('aria-describedby="product-name-error"', false);
        $view->assertSee('Enter a product name.');
    }
}
