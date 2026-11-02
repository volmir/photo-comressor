<?php

declare(strict_types=1);

// Minimal little-endian EXIF: IFD0 Orientation + ExifIFD DateTimeOriginal.
function addTestExif(string $path, int $orientation, string $date): void
{
    $date = str_pad(substr($date, 0, 19), 19, ' ') . "\0";
    $tiff = "II" . pack('vV', 42, 8)
        . pack('v', 2)
        . pack('vvV', 0x0112, 3, 1) . pack('v', $orientation) . "\0\0"
        . pack('vvVV', 0x8769, 4, 1, 38)
        . pack('V', 0)
        . pack('v', 1)
        . pack('vvVV', 0x9003, 2, 20, 56)
        . pack('V', 0) . $date;
    $payload = "Exif\0\0" . $tiff;
    $jpeg = file_get_contents($path);
    if ($jpeg === false || substr($jpeg, 0, 2) !== "\xff\xd8") {
        throw new RuntimeException('Cannot read test JPEG');
    }
    if (file_put_contents($path, substr($jpeg, 0, 2) . "\xff\xe1"
        . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2)) === false) {
        throw new RuntimeException('Cannot write EXIF');
    }
}
