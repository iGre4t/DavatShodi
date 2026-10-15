<?php
declare(strict_types=1);

function windowsReleaseDirectory(): string { return defined('WINDOWS_RELEASE_DIRECTORY') ? WINDOWS_RELEASE_DIRECTORY : dirname(__DIR__, 2) . '/downloads/windows'; }
function windowsReleaseRead(): array
{
    $path = windowsReleaseDirectory() . '/latest.json';
    if (!is_file($path)) return ['version'=>''];
    $release = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    return is_array($release) ? $release : ['version'=>''];
}

/** Only a versioned, single-file Windows build is published. No archive paths are extracted. */
function windowsReleasePublish(string $version, string $notes, string $upload, string $extension): array
{
    if (!preg_match('/^[1-9]\d{0,3}\.\d{1,4}\.\d{1,4}$/D', $version)) throw new InvalidArgumentException('نسخه را مانند 3.1.0 وارد کنید.');
    if (strlen($notes) > 12000) throw new InvalidArgumentException('توضیحات نسخه بیش از حد طولانی است.');
    $directory = windowsReleaseDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('مسیر انتشار ساخته نشد.');
    $lock = fopen($directory . '/publish.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('انتشار دیگری در حال انجام است.');
    $temporary = tempnam($directory, '.release-');
    try {
        $current = windowsReleaseRead();
        if (!empty($current['version']) && version_compare($version, $current['version'], '<=')) throw new InvalidArgumentException('نسخه جدید باید بالاتر از نسخه منتشرشده باشد.');
        if ($extension === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($upload) !== true) throw new InvalidArgumentException('فایل زیپ معتبر نیست.');
            try {
                if ($zip->numFiles !== 1) throw new InvalidArgumentException('زیپ باید فقط یک فایل اجرایی برنامه داشته باشد.');
                $info = $zip->statIndex(0);
                if (!$info || basename($info['name']) !== $info['name'] || !preg_match('/\.exe$/i', $info['name']) || $info['size'] < 1024 || $info['size'] > 350 * 1024 * 1024) throw new InvalidArgumentException('فایل اجرایی داخل زیپ معتبر نیست.');
                $input = $zip->getStream($info['name']); $output = fopen($temporary, 'wb');
                if (!$input || !$output) throw new RuntimeException('خواندن نسخه جدید انجام نشد.');
                try { $copied = stream_copy_to_stream($input, $output, 350 * 1024 * 1024 + 1); }
                finally { fclose($input); fclose($output); }
                if ($copied !== (int)$info['size']) throw new InvalidArgumentException('فایل اجرایی کامل نیست.');
            } finally { $zip->close(); }
        } elseif ($extension === 'exe') {
            if (!copy($upload, $temporary)) throw new RuntimeException('کپی فایل انجام نشد.');
        } else throw new InvalidArgumentException('فقط فایل exe یا زیپ تک‌فایلی پذیرفته می‌شود.');
        $size = filesize($temporary);
        $handle = fopen($temporary, 'rb'); $magic = fread($handle, 2); fclose($handle);
        if ($size < 1024 || $size > 350 * 1024 * 1024 || $magic !== 'MZ') throw new InvalidArgumentException('فایل برنامه ویندوز معتبر نیست.');
        $hash = hash_file('sha256', $temporary);
        $filename = 'MCI-EGM-' . $version . '-' . substr($hash, 0, 12) . '.exe';
        if (!rename($temporary, $directory . '/' . $filename)) throw new RuntimeException('انتشار فایل انجام نشد.');
        chmod($directory . '/' . $filename, 0644);
        $release = ['version'=>$version, 'url'=>'https://davatshodi.ir/downloads/windows/' . $filename, 'sha256'=>$hash, 'size'=>$size, 'notes'=>trim($notes), 'published_at'=>gmdate('c')];
        $manifest = tempnam($directory, '.manifest-');
        if (file_put_contents($manifest, json_encode($release, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !rename($manifest, $directory . '/latest.json')) throw new RuntimeException('اطلاعات نسخه منتشر نشد.');
        chmod($directory . '/latest.json', 0644);
        return $release;
    } finally { if (is_file($temporary)) unlink($temporary); flock($lock, LOCK_UN); fclose($lock); }
}
