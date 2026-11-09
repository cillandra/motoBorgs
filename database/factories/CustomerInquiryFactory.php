<?php

namespace Database\Factories;

use App\Models\CustomerInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomerInquiry> */
class CustomerInquiryFactory extends Factory
{
    public function definition(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'fixture'), 'name' => fake()->name(), 'email' => fake()->safeEmail(), 'topic' => 'general', 'subject' => 'General inquiry', 'message' => 'Demonstration inquiry for a motorcycle part.', 'status' => 'new', 'version' => 1];
    }
}
