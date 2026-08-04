<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class OrderSchemaCompatibilityTest extends TestCase
{
    use DatabaseMigrations;

    public function test_legacy_order_columns_remain_readable_with_the_canonical_key(): void
    {
        $order = Order::create([
            'ebay_order_id' => '13-14975-00010',
            'ebay_order_number' => '13-14975-00010',
            'ebay_created_at' => '2026-08-02 12:00:00',
        ]);

        $this->assertSame('13-14975-00010', $order->fresh()->ebay_order_id);
        $this->assertSame('13-14975-00010', $order->fresh()->ebay_order_number);
        $this->assertNull($order->fresh()->printifyOrder);
    }
}
