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

function createTestPhotos(string $directory): void
{
    foreach ([
        ['landscape.jpg', 3200, 1800, 1, '2024:06:15 12:30:00'],
        ['portrait-exif.jpg', 3000, 2000, 6, '2024:12:03 09:15:00'],
    ] as [$name, $width, $height, $orientation, $date]) {
        $path = $directory . '/' . $name;
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $fraction = $y / $height;
            $color = imagecolorallocate($image, (int) (30 + 130 * $fraction),
                (int) (95 + 100 * $fraction), (int) (190 - 120 * $fraction));
            imageline($image, 0, $y, $width - 1, $y, $color);
        }
        $sun = imagecolorallocate($image, 255, 220, 100);
        imagefilledellipse($image, (int) ($width * .75), (int) ($height * .25), 320, 320, $sun);
        $mountain = imagecolorallocate($image, 50, 95, 85);
        imagefilledpolygon($image, [0, $height, (int) ($width * .38), (int) ($height * .35),
            (int) ($width * .8), $height], $mountain);
        mt_srand(42);
        for ($i = 0; $i < 30000; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $color = imagecolorallocate($image, mt_rand(30, 220), mt_rand(60, 220), mt_rand(60, 220));
            imagefilledellipse($image, $x, $y, 3, 3, $color);
        }
        $white = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 40, 40, "TEST JPEG / $name / EXIF Orientation=$orientation", $white);
        if (!imagejpeg($image, $path, 96)) {
            throw new RuntimeException("Cannot write $path");
        }
        unset($image);
        addTestExif($path, $orientation, $date);
        touch($path, strtotime('2025-01-01 00:00:00 UTC'));
    }
}
