<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
date_default_timezone_set('UTC');

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }
}

function batch(string $input, string $output, array $arguments = [], array $phpArguments = []): array
{
    $command = array_merge([PHP_BINARY], $phpArguments,
        [__DIR__ . '/../run.php', '--input', $input, '--output', $output], $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start CLI');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function dimensions(string $file): array
{
    $size = getimagesize($file);
    return [$size[0], $size[1]];
}

function smallPhoto(string $file, int $width = 80, int $height = 40): void
{
    $image = imagecreatetruecolor($width, $height);
    $colors = [0xff0000, 0x00ff00, 0x0000ff, 0xffff00];
    foreach ($colors as $i => $color) {
        $x = ($i % 2) * (int) ($width / 2);
        $y = intdiv($i, 2) * (int) ($height / 2);
        imagefilledrectangle($image, $x, $y, $x + (int) ($width / 2) - 1,
            $y + (int) ($height / 2) - 1, $color);
    }
    imagejpeg($image, $file, 100);
}

function removeTestDirectory(string $path): void
{
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            removeTestDirectory($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/photo-compressor-' . bin2hex(random_bytes(6));
mkdir($root . '/input', 0775, true);
$input = $root . '/input';
$output = $root . '/output';

try {
    foreach (['landscape.jpg', 'portrait-exif.jpg'] as $file) {
        check(is_file(__DIR__ . '/fixtures/' . $file), "Fixture exists: $file");
        copy(__DIR__ . '/fixtures/' . $file, "$input/$file");
    }
    $originalHash = hash_file('sha256', "$input/landscape.jpg");
    [$code, $log, $error] = batch($input, $output);
    check($code === 0 && $error === '', "Batch succeeds: $log $error");
    $landscape = "$output/2024/2024-06-15/landscape.jpg";
    $portrait = "$output/2024/2024-12-03/portrait-exif.jpg";
    check(dimensions($landscape) === [1920, 1080], 'Landscape size and EXIF date');
    check(dimensions($portrait) === [1080, 1620], 'Portrait EXIF rotation and size');
    check(filesize($landscape) < filesize("$input/landscape.jpg"), 'Fixture shrinks');
    check(filemtime($landscape) === strtotime('2024-06-15 12:30:00'), 'Output mtime preserves capture date');
    check(hash_file('sha256', "$input/landscape.jpg") === $originalHash, 'Original is unchanged');
    $hash = hash_file('sha256', $landscape);
    [$code, $log] = batch($input, $output, ['--quality=10']);
    check($code === 0 && str_contains($log, 'skipped=2'), 'Repeat skips existing outputs');
    check(hash_file('sha256', $landscape) === $hash, 'Repeat does not overwrite');
    [$code, $log] = batch($input, $output, ['--quality=50', '--overwrite', '--coefficient=0.5']);
    check($code === 0 && str_contains($log, 'processed=2'), 'Explicit overwrite succeeds');
    check(dimensions($landscape) === [960, 540], 'Coefficient changes size');

    mkdir("$root/small");
    smallPhoto("$root/small/small.JPG");
    touch("$root/small/small.JPG", strtotime('2023-02-10 00:00:00'));
    smallPhoto("$root/small/square.jpeg", 100, 100);
    touch("$root/small/square.jpeg", strtotime('2023-02-10 00:00:00'));
    [$code, $log] = batch("$root/small", "$root/copied");
    check($code === 0 && str_contains($log, 'copied=2'), 'Small JPEG and uppercase extension supported');
    check(hash_file('sha256', "$root/small/small.JPG") === hash_file('sha256',
        "$root/copied/2023/2023-02-10/small.JPG"), 'Small image copied byte for byte; mtime date fallback');
    [$code, $log] = batch("$root/small", "$root/boundary", ['--width=80', '--height=40']);
    check($code === 0 && str_contains($log, 'copied=1')
        && hash_file('sha256', "$root/small/small.JPG") === hash_file('sha256',
            "$root/boundary/2023/2023-02-10/small.JPG"), 'Image exactly on size boundary is copied');
    [$code] = batch("$root/small", "$root/square", ['--width=50', '--height=30']);
    check($code === 0 && dimensions("$root/square/2023/2023-02-10/square.jpeg") === [50, 50],
        'Square uses width on both axes, as in original script');

    // Expected source corner order after applying each EXIF orientation.
    $corners = [1 => [0, 1, 2, 3], 2 => [1, 0, 3, 2], 3 => [3, 2, 1, 0],
        4 => [2, 3, 0, 1], 5 => [0, 2, 1, 3], 6 => [2, 0, 3, 1],
        7 => [3, 1, 2, 0], 8 => [1, 3, 0, 2]];
    mkdir("$root/oriented");
    foreach ($corners as $orientation => $expected) {
        $file = "$root/oriented/$orientation.jpg";
        smallPhoto($file);
        addTestExif($file, $orientation, '2022:03:04 05:06:07');
    }
    [$code, $log, $error] = batch("$root/oriented", "$root/rotated");
    check($code === 0, "All EXIF orientations succeed: $error");
    $palette = [0xff0000, 0x00ff00, 0x0000ff, 0xffff00];
    foreach ($corners as $orientation => $expected) {
        $file = "$root/rotated/2022/2022-03-04/$orientation.jpg";
        $image = imagecreatefromjpeg($file);
        $w = imagesx($image);
        $h = imagesy($image);
        check([$w, $h] === ($orientation >= 5 ? [40, 80] : [80, 40]), "EXIF $orientation dimensions");
        foreach ($expected as $i => $sourceCorner) {
            $color = imagecolorat($image, (int) ($w * (($i % 2) ? .75 : .25)),
                (int) ($h * (intdiv($i, 2) ? .75 : .25)));
            $target = $palette[$sourceCorner];
            $difference = 0;
            foreach ([0, 8, 16] as $shift) {
                $difference += abs((($color >> $shift) & 255) - (($target >> $shift) & 255));
            }
            check($difference < 80, "EXIF $orientation corner $i is correct");
        }
        unset($image);
    }

    file_put_contents("$root/small/broken.jpg", 'not a JPEG');
    file_put_contents("$root/small/readme.txt", 'ignored');
    mkdir("$root/small/directory.jpg");
    [$code, $log, $error] = batch("$root/small", "$root/mixed");
    check($code === 1 && str_contains($log, 'copied=2') && str_contains($log, 'errors=1')
        && str_contains($log, 'ignored=2') && str_contains($error, 'broken.jpg'),
        'Corrupt file reported; batch continues; directories and non-JPEG ignored');
    check(glob("$root/mixed/*/*/.photo-*") === [], 'No partial output files');
    smallPhoto("$root/small/invalid-date.jpg");
    addTestExif("$root/small/invalid-date.jpg", 1, '2024:02:30 10:00:00');
    touch("$root/small/invalid-date.jpg", strtotime('2020-01-02'));
    batch("$root/small", "$root/invalid-date");
    check(is_file("$root/invalid-date/2020/2020-01-02/invalid-date.jpg"), 'Invalid EXIF date falls back to mtime');
    smallPhoto("$root/small/thin.jpg", 1, 4000);
    [$code, $log, $error] = batch("$root/small", "$root/thin", ['--width=1', '--height=1']);
    check(str_contains($log, 'thin.jpg') && !str_contains($error, 'thin.jpg'), 'Extreme aspect ratio never produces zero dimensions');
    foreach ([['--quality=0'], ['--coefficient=0'], ['--width=-1'], ['--timezone=invalid'],
        ['--unknown'], ['--height'], ['--overwrite=yes'], ['--coefficient=INF']] as $arguments) {
        [$code] = batch($input, "$root/invalid", $arguments);
        check($code === 2, 'Invalid argument rejected: ' . implode(' ', $arguments));
    }
    [$code] = batch("$root/missing", $output);
    check($code === 1, 'Missing input returns failure');
    [$code] = batch($input, $input);
    check($code === 1, 'Input cannot equal output');
    mkdir("$root/locked");
    $lock = fopen("$root/locked/.compressor.lock", 'c');
    flock($lock, LOCK_EX);
    [$code] = batch($input, "$root/locked");
    check($code === 1, 'Concurrent batch to same archive rejected');
    fclose($lock);
    mkdir("$root/readonly", 0555);
    [$code] = batch($input, "$root/readonly");
    check($code === 1, 'Unwritable output returns failure');
    chmod("$root/readonly", 0755);
    [$code, $log, $error] = batch($input, "$root/memory", [], ['-d', 'memory_limit=32M']);
    check($code === 1 && str_contains($log, 'errors=2') && str_contains($error, 'memory budget'),
        'Oversized images fail gracefully before allocation');
    echo "OK: $checks checks\n";
} finally {
    removeTestDirectory($root);
}
