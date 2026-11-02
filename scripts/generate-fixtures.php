<?php

declare(strict_types=1);

require __DIR__ . '/../tests/support.php';

$directory = $argv[1] ?? __DIR__ . '/../tests/fixtures';
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    throw new RuntimeException('Cannot create fixture directory');
}

foreach ([
    ['landscape.jpg', 3200, 1800, 1, '2024:06:15 12:30:00'],
    ['portrait-exif.jpg', 3000, 2000, 6, '2024:12:03 09:15:00'],
] as [$name, $width, $height, $orientation, $date]) {
    $path = $directory . '/' . $name;
    if (file_exists($path)) {
        echo "Skipped (exists): $path\n";
        continue;
    }
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
    echo "Created: $path ({$width}x{$height}, orientation=$orientation)\n";
}
