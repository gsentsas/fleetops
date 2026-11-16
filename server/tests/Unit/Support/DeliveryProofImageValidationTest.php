<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Illuminate\Http\UploadedFile;

class FleetOpsProofImageValidationProbe extends OrderController
{
    public function inspectBinary(string $bytes): ?array
    {
        return $this->inspectProofImage($bytes);
    }

    public function inspectInput(UploadedFile|string $photo): ?array
    {
        return $this->inspectIncomingProofImage($photo);
    }
}

function fleetopsProofValidationPng(): string
{
    $bitmap = imagecreatetruecolor(2, 2);
    imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 10, 70, 150));
    ob_start();
    imagepng($bitmap);
    $bytes = ob_get_clean();
    imagedestroy($bitmap);

    return $bytes;
}

test('genuine PNG photo is accepted with canonical metadata', function () {
    $bytes = fleetopsProofValidationPng();
    $image = (new FleetOpsProofImageValidationProbe())->inspectInput(base64_encode($bytes));

    expect($image)->not->toBeNull()
        ->and($image['contents'])->toBe($bytes)
        ->and($image['content_type'])->toBe('image/png')
        ->and($image['extension'])->toBe('png')
        ->and($image['size'])->toBe(strlen($bytes));
});

test('binary-only image inspection accepts a valid PNG', function () {
    $bytes = fleetopsProofValidationPng();
    $image = (new FleetOpsProofImageValidationProbe())->inspectBinary($bytes);

    expect($image['content_type'])->toBe('image/png')
        ->and($image['size'])->toBe(strlen($bytes));
});

test('malformed base64 is rejected', function () {
    expect((new FleetOpsProofImageValidationProbe())->inspectInput('!!not-base64!!'))->toBeNull();
});

test('valid base64 containing nonimage content is rejected', function () {
    expect((new FleetOpsProofImageValidationProbe())->inspectInput(base64_encode('not an image')))->toBeNull();
});

test('truncated PNG is rejected even if it contains a PNG header', function () {
    $bytes = fleetopsProofValidationPng();

    expect((new FleetOpsProofImageValidationProbe())->inspectInput(base64_encode(substr($bytes, 0, 30))))->toBeNull();
});

test('a valid uploaded PNG is accepted', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fleetops-proof-');
    try {
        file_put_contents($tmp, fleetopsProofValidationPng());
        $file  = new UploadedFile($tmp, 'photo.png', 'image/png', null, true);
        $image = (new FleetOpsProofImageValidationProbe())->inspectInput($file);

        expect($image)->not->toBeNull()
            ->and($image['content_type'])->toBe('image/png')
            ->and($image['extension'])->toBe('png');
    } finally {
        @unlink($tmp);
    }
});

test('uploaded text disguised as image is rejected', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fleetops-proof-');
    try {
        file_put_contents($tmp, 'not a valid png');
        $file = new UploadedFile($tmp, 'photo.png', 'image/png', null, true);
        expect((new FleetOpsProofImageValidationProbe())->inspectInput($file))->toBeNull();
    } finally {
        @unlink($tmp);
    }
});

test('oversized decoded photo is rejected', function () {
    $oversized = str_repeat('x', 10 * 1024 * 1024 + 1);
    expect((new FleetOpsProofImageValidationProbe())->inspectInput(base64_encode($oversized)))->toBeNull();
});
