<?php

function r235CoverageGateRun(int $covered, int $total, float $threshold): array
{
    $file = tempnam(sys_get_temp_dir(), 'r235-clover-gate-');
    $xml  = sprintf(
        '<?xml version="1.0"?><coverage><project name="synthetic"><metrics statements="%d" coveredstatements="%d" methods="1" coveredmethods="1" classes="1" coveredclasses="1"/></project></coverage>',
        $total,
        $covered,
    );
    file_put_contents($file, $xml);
    try {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(getcwd() . '/scripts/coverage-summary.php')
            . ' ' . escapeshellarg($file) . ' --fail-under=' . $threshold . ' 2>&1';
        $lines  = [];
        $status = 0;
        exec($command, $lines, $status);

        return [$status, implode("\n", $lines)];
    } finally {
        unlink($file);
    }
}

test('hard 100 percent coverage gate rejects a single uncovered statement even when display rounds to 100.00', function () {
    [$status, $text] = r235CoverageGateRun(45459, 45460, 100);
    expect($status)->toBe(1)
        ->and($text)->toContain('45459/45460 statements')
        ->and($text)->toContain('99.9978%');
});

test('exactly covered statements pass a hard 100 percent line gate', function () {
    [$status, $text] = r235CoverageGateRun(45460, 45460, 100);
    expect($status)->toBe(0)
        ->and($text)->toContain('Line coverage: 100.00% (45460/45460 statements)');
});

test('a lower configured coverage target uses the unrounded line ratio', function () {
    [$status] = r235CoverageGateRun(45459, 45460, 99.9);
    expect($status)->toBe(0);
});

test('a positive threshold cannot pass with zero executable statements', function () {
    [$status] = r235CoverageGateRun(0, 0, 100);
    expect($status)->toBe(1);
});
