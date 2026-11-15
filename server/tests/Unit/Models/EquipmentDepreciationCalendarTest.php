<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Equipment;

test('equipment depreciation uses completed calendar anniversaries on any Carbon version', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));
    try {
        $equipment = new Equipment();
        $equipment->setRawAttributes([
            'purchase_price' => 1000,
            'purchased_at'   => Carbon::parse('2024-07-26 12:00:00'),
            'meta'           => ['depreciation_rate' => 0.25],
        ], true);

        expect($equipment->depreciated_value)->toBe(500.0);
    } finally {
        Carbon::setTestNow();
    }
});

test('equipment depreciation is proportional between anniversaries', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-26 12:00:00'));
    try {
        $equipment = new Equipment();
        $equipment->setRawAttributes([
            'purchase_price' => 1000,
            'purchased_at'   => Carbon::parse('2024-07-26 12:00:00'),
            'meta'           => ['depreciation_rate' => 0.25],
        ], true);
        $expected = 1000 - 250 * (1 + 184 / 365);

        expect(abs($equipment->depreciated_value - $expected))->toBeLessThan(0.0000001);
    } finally {
        Carbon::setTestNow();
    }
});

test('future equipment purchases do not accumulate depreciation', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    try {
        $equipment = new Equipment();
        $equipment->setRawAttributes([
            'purchase_price' => 1000,
            'purchased_at'   => Carbon::parse('2026-07-26 12:00:00'),
            'meta'           => ['depreciation_rate' => 0.25],
        ], true);

        expect($equipment->depreciated_value)->toBe(1000.0);
    } finally {
        Carbon::setTestNow();
    }
});
