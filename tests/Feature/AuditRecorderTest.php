<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Services\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_retains_safe_context_and_removes_secrets(): void
    {
        $event = app(AuditRecorder::class)->record(null, 'Stock adjusted', 'inventory', 'variant', '1', 'success', [
            'reason' => 'Physical count',
            'password' => 'do-not-record',
            'changes' => ['role' => ['staff', 'manager'], 'db_password' => 'also-secret'],
        ]);
        $this->assertSame('Physical count', $event->context['reason']);
        $this->assertSame(['staff', 'manager'], $event->context['changes']['role']);
        $this->assertStringNotContainsString('do-not-record', $event->toJson());
        $this->assertStringNotContainsString('also-secret', $event->toJson());
    }

    public function test_business_audit_rolls_back_with_its_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                app(AuditRecorder::class)->record(null, 'Sale completed', 'sales', 'sale', '1', 'success');
                throw new \DomainException('Simulated business failure');
            });
        } catch (\DomainException) {
        }
        $this->assertSame(0, AuditEvent::count());
    }
}
