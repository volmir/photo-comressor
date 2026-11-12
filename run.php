<?php

declare(strict_types=1);

function options(array $arguments): array
{
    $options = [
        'input' => __DIR__ . '/input',
        'output' => __DIR__ . '/output',
        'width' => '1920',
        'height' => '1080',
        'coefficient' => '1',
        'quality' => '85',
        'timezone' => 'UTC',
        'overwrite' => false,
    ];
    for ($i = 1; $i < count($arguments); $i++) {
        [$name, $value] = array_pad(explode('=', $arguments[$i], 2), 2, null);
        $name = substr($name, 0, 2) === '--' ? substr($name, 2) : '';
        if ($name === 'help') {
            return ['help' => true];
        }
        if (!array_key_exists($name, $options)) {
            throw new InvalidArgumentException('Unknown argument: ' . $arguments[$i]);
        }
        if ($name === 'overwrite') {
            if ($value !== null) {
                throw new InvalidArgumentException('--overwrite does not take a value');
            }
            $options[$name] = true;
            continue;
        }
        $value ??= $arguments[++$i] ?? null;
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException("Missing value for --$name");
        }
        $options[$name] = $value;
    }
    foreach (['width', 'height', 'quality'] as $name) {
        $maximum = $name === 'quality' ? 100 : 10000;
        $value = filter_var($options[$name], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $maximum],
        ]);
        if ($value === false) {
            throw new InvalidArgumentException("--$name must be an integer between 1 and $maximum");
        }
        $options[$name] = $value;
    }
    if (!is_numeric($options['coefficient']) || !is_finite((float) $options['coefficient'])
        || (float) $options['coefficient'] <= 0 || (float) $options['coefficient'] > 10) {
        throw new InvalidArgumentException('--coefficient must be a number greater than 0 and at most 10');
    }
    $options['coefficient'] = (float) $options['coefficient'];
    if (!in_array($options['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        throw new InvalidArgumentException('Unknown timezone: ' . $options['timezone']);
    }
    return $options;
}

function usage(): string
{
    return "Usage: php run.php [--input DIR] [--output DIR] [--width 1920] [--height 1080]\n"
        . "                   [--coefficient 1] [--quality 85] [--timezone UTC] [--overwrite]\n"
        . "JPEG only; non-recursive. Existing files are skipped unless --overwrite is set.\n";
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
}

function getExifTimestamp(array $exif): int|false
{
    $value = $exif['DateTimeOriginal'] ?? null;
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', trim($value));
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        return false;
    }
    return $date->getTimestamp();
}

function fixImageOrientation(GdImage $image, int $orientation): GdImage
{
    if (in_array($orientation, [2, 5, 7], true)) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
    } elseif ($orientation === 4) {
        imageflip($image, IMG_FLIP_VERTICAL);
    }
    $angle = match ($orientation) {
        3 => 180,
        5, 8 => 90,
        6, 7 => -90,
        default => 0,
    };
    if ($angle !== 0) {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            throw new RuntimeException('Cannot rotate image');
        }
        return $rotated;
    }
    return $image;
}

function checkImageMemory(int $width, int $height, array $options): void
{
    $limit = ini_parse_quantity(ini_get('memory_limit'));
    // Decode + EXIF rotation + resampling may hold several GD buffers simultaneously.
    $largestTarget = max($options['width'], $options['height']) * $options['coefficient'];
    $estimated = $width * $height * 12 + min($width * $height, $largestTarget ** 2) * 4 + 16 * 1024 ** 2;
    if ($limit > 0 && $estimated > $limit - memory_get_usage(true)) {
        throw new RuntimeException('Image exceeds the memory budget; increase PHP_MEMORY_LIMIT');
    }
}

function processPhoto(string $inputPath, string $filename, array $options): array
{
    $exif = @exif_read_data($inputPath) ?: [];
    $timestamp = getExifTimestamp($exif);
    if ($timestamp === false) {
        $timestamp = @filemtime($inputPath);
    }
    if ($timestamp === false) {
        throw new RuntimeException('Cannot read file date');
    }
    $targetDir = $options['output'] . '/' . date('Y/Y-m-d', $timestamp);
    $outputPath = $targetDir . '/' . $filename;
    if (file_exists($outputPath) || is_link($outputPath)) {
        if (!$options['overwrite']) {
            echo "Skipped (exists): $filename\n";
            return ['skipped', 0, 0];
        }
        if (realpath($inputPath) === realpath($outputPath)) {
            throw new RuntimeException('Input and output refer to the same file');
        }
    }
    $size = @getimagesize($inputPath);
    if ($size === false || $size[2] !== IMAGETYPE_JPEG) {
        throw new RuntimeException('Not a valid JPEG');
    }
    checkImageMemory($size[0], $size[1], $options);
    $image = @imagecreatefromjpeg($inputPath);
    if ($image === false) {
        throw new RuntimeException('Cannot decode JPEG');
    }
    $exifOrientation = (int) ($exif['Orientation'] ?? 1);
    $image = fixImageOrientation($image, $exifOrientation);
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width > $height) {
        [$maxWidth, $maxHeight, $orientation] = [$options['width'], $options['height'], 'landscape'];
    } elseif ($height > $width) {
        [$maxWidth, $maxHeight, $orientation] = [$options['height'], $options['width'], 'portrait'];
    } else {
        [$maxWidth, $maxHeight, $orientation] = [$options['width'], $options['width'], 'square'];
    }
    $ratio = min($maxWidth * $options['coefficient'] / $width, $maxHeight * $options['coefficient'] / $height, 1);
    $newWidth = max(1, (int) floor($width * $ratio));
    $newHeight = max(1, (int) floor($height * $ratio));
    // Small, correctly oriented JPEGs keep their original bytes and metadata.
    $copy = $ratio >= 1.0 && !in_array($exifOrientation, range(2, 8), true);
    ensureDirectory($targetDir);
    $temporaryPath = $targetDir . '/.photo-' . bin2hex(random_bytes(8));
    $temporaryHandle = @fopen($temporaryPath, 'x');
    if ($temporaryHandle === false) {
        throw new RuntimeException("Cannot create temporary output file in: $targetDir");
    }
    fclose($temporaryHandle);
    try {
        if ($copy) {
            unset($image);
            $saved = @copy($inputPath, $temporaryPath);
        } else {
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            if ($newImage === false || !imagecopyresampled($newImage, $image, 0, 0, 0, 0,
                $newWidth, $newHeight, $width, $height)) {
                throw new RuntimeException('Cannot resize image');
            }
            unset($image);
            $saved = @imagejpeg($newImage, $temporaryPath, $options['quality']);
            unset($newImage);
        }
        if (!$saved || filesize($temporaryPath) === 0) {
            throw new RuntimeException('Cannot write JPEG (check disk space and permissions)');
        }
        if (!@touch($temporaryPath, $timestamp) || !@chmod($temporaryPath, 0664)
            || !@rename($temporaryPath, $outputPath)) {
            throw new RuntimeException('Cannot publish output file');
        }
    } finally {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
    $before = filesize($inputPath);
    $after = filesize($outputPath);
    $action = $copy ? 'Copied' : 'Processed';
    echo "$action: $filename ($orientation) -> {$newWidth}x{$newHeight}; $before -> $after bytes\n";
    return [$copy ? 'copied' : 'processed', $before, $after];
}

function main(array $arguments): int
{
    try {
        $options = options($arguments);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, 'Configuration error: ' . $exception->getMessage() . "\n");
        return 2;
    }
    if (!empty($options['help'])) {
        echo usage();
        return 0;
    }
    date_default_timezone_set($options['timezone']);
    foreach (['gd', 'exif'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException("Required PHP extension is missing: $extension");
        }
    }
    if (!is_dir($options['input']) || !is_readable($options['input'])) {
        throw new RuntimeException('Input directory is missing or unreadable: ' . $options['input']);
    }
    ensureDirectory($options['output']);
    if (realpath($options['input']) === realpath($options['output'])) {
        throw new RuntimeException('Input and output directories must differ');
    }
    $lock = @fopen($options['output'] . '/.compressor.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Output directory is not writable or another batch is running');
    }
    try {
        $files = @scandir($options['input']);
        if ($files === false) {
            throw new RuntimeException('Cannot list input directory');
        }
        $counts = ['processed' => 0, 'copied' => 0, 'skipped' => 0, 'errors' => 0, 'ignored' => 0];
        $before = $after = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $inputPath = $options['input'] . '/' . $file;
            if (!is_file($inputPath) || !preg_match('/\.(jpg|jpeg)$/i', $file)) {
                $counts['ignored']++;
                continue;
            }
            try {
                [$status, $originalBytes, $resultBytes] = processPhoto($inputPath, $file, $options);
                $counts[$status]++;
                $before += $originalBytes;
                $after += $resultBytes;
            } catch (Throwable $exception) {
                $counts['errors']++;
                fwrite(STDERR, "Failed: $file: " . $exception->getMessage() . "\n");
            }
        }
        echo 'Done: ' . implode(', ', array_map(
            static fn (string $key, int $value): string => "$key=$value", array_keys($counts), $counts
        )) . "\n";
        echo "Bytes: $before -> $after; saved=" . ($before - $after) . "\n";
        return $counts['errors'] > 0 ? 1 : 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    exit(main($argv));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
