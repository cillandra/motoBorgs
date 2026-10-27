<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final readonly class ReportFilter
{
    private function __construct(public string $start, public string $end, public CarbonImmutable $startUtc, public CarbonImmutable $endUtc, public ?int $variantId, public ?int $categoryId, public ?int $cashierId = null) {}

    public static function from(array $input): self
    {
        $today = now()->timezone('Asia/Manila')->format('Y-m-d');
        $input['start'] = $input['start'] ?? $today;
        $input['end'] = $input['end'] ?? $input['start'];
        $values = Validator::make($input, ['start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'], 'variant_id' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1']])->validate();
        [$start,$end] = SalesQuery::bounds($values);
        Validator::make(['range' => $start->diffInDays($end)], ['range' => ['numeric', 'max:366']], ['range.max' => 'Choose at most 366 business dates per report.'])->validate();

        return new self($values['start'], $values['end'], $start, $end, empty($values['variant_id']) ? null : (int) $values['variant_id'], empty($values['category_id']) ? null : (int) $values['category_id']);
    }

    public function forCashier(int $id): self
    {
        return new self($this->start, $this->end, $this->startUtc, $this->endUtc, $this->variantId, $this->categoryId, $id);
    }

    public function values(): array
    {
        return array_filter(['start' => $this->start, 'end' => $this->end, 'variant_id' => $this->variantId, 'category_id' => $this->categoryId], fn ($value) => $value !== null);
    }
}
