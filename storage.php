<?php

namespace Dropage;

defined('ABSPATH') || exit;

const PUBLIC_DIRECTORY = 'extras';
const PAGE_MARKER = '.dropage-page';
const MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;
const MAX_EXTRACTED_BYTES = 200 * 1024 * 1024;
const MAX_ENTRIES = 5000;
const STATIC_EXTENSIONS = [
    'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'webmanifest', 'txt', 'xml',
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
    'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf', 'mp3', 'ogg', 'wav', 'mp4', 'webm', 'vtt',
];

function valid_component(string $name): bool
{
    return strlen($name) <= 255
        && preg_match('/^[\p{L}\p{N}_-][\p{L}\p{M}\p{N}_. -]*$/uD', $name)
        && !str_ends_with($name, '.') && !str_ends_with($name, ' ')
        && !preg_match('/^(?:con|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³]) *(?:\.|$)/iuD', $name);
}

function encode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function storage_root(): string
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $home = realpath(get_home_path());
    if ($home === false || dirname($home) === $home) {
        throw new \RuntimeException('サイトの公開ディレクトリを確認できませんでした。');
    }

    $root = $home . DIRECTORY_SEPARATOR . PUBLIC_DIRECTORY;
    if (is_link($root) || (file_exists($root) && !local_directory($root))) {
        throw new \RuntimeException('/extras を通常のディレクトリとして使用できません。');
    }
    return $root;
}

function local_directory(string $path): bool
{
    $resolved = realpath($path);
    return $resolved !== false && $resolved === $path && !is_link($path) && is_dir($path);
}

function archive_entry(\ZipArchive $zip, int $index): array
{
    $stat = $zip->statIndex($index);
    if ($stat === false || !$zip->getExternalAttributesIndex($index, $platform, $attributes)) {
        throw new \RuntimeException('ZIP内の項目を読み取れませんでした。');
    }

    $name = $stat['name'];
    $directory = str_ends_with($name, '/');
    $relative = $directory ? substr($name, 0, -1) : $name;
    $parts = explode('/', $relative);
    $kind = ($attributes >> 16) & 0170000;
    if (strlen($relative) > 1024 || count($parts) > 32 || in_array(false, array_map(__NAMESPACE__ . '\\valid_component', $parts), true)
        || ($platform === \ZipArchive::OPSYS_UNIX && $kind !== 0 && $kind !== ($directory ? 0040000 : 0100000))) {
        throw new \RuntimeException('ZIPに使用できないパスまたは特殊ファイルが含まれています。');
    }

    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (!$directory && (!in_array($extension, STATIC_EXTENSIONS, true)
        || preg_match('/\.(?:php[^.]*|phtml|phar|pht|phps|cgi|fcgi|pl|py|rb|asp|aspx|jsp|shtml|shtm)(?:\.|$)/iD', basename($relative)))) {
        throw new \RuntimeException('ZIPに公開できない種類のファイルが含まれています。');
    }

    return [$relative, $directory, $stat['size'], $extension];
}

function extract_archive(string $archive, string $stage): string
{
    $size = @filesize($archive);
    if ($size === false || $size > MAX_ARCHIVE_BYTES) {
        throw new \RuntimeException('ZIPは50 MiB以下にしてください。');
    }
    if (!class_exists(\ZipArchive::class)) {
        throw new \RuntimeException('PHP の zip 拡張が必要です。');
    }
    $zip = new \ZipArchive();
    if (@$zip->open($archive, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
        throw new \RuntimeException('有効なZIPを開けませんでした。');
    }

    try {
        if ($zip->numFiles > MAX_ENTRIES) {
            throw new \RuntimeException('ZIP内のファイルとフォルダーは合計5,000件以下にしてください。');
        }
        $total = 0;
        $html = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            [$relative, $directory, $bytes, $extension] = archive_entry($zip, $index);
            $target = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = $directory ? $target : dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                throw new \RuntimeException('ZIPのフォルダーを作成できませんでした。');
            }
            if ($directory) {
                continue;
            }
            if ($bytes < 0 || $bytes > MAX_EXTRACTED_BYTES - $total) {
                throw new \RuntimeException('展開後の合計サイズは200 MiB以下にしてください。');
            }
            copy_entry($zip, $index, $target, $bytes);
            $total += $bytes;
            if ($extension === 'html' || $extension === 'htm') {
                $html[] = $relative;
            }
        }
        if (!$html) {
            throw new \RuntimeException('ZIPにHTMLファイルを含めてください。');
        }
        usort($html, static function (string $left, string $right): int {
            $rank = static fn (string $path): array => [
                substr_count($path, '/'),
                !in_array(strtolower(basename($path)), ['index.html', 'index.htm'], true),
                $path,
            ];
            return $rank($left) <=> $rank($right);
        });
        return $html[0];
    } finally {
        $zip->close();
    }
}

function copy_entry(\ZipArchive $zip, int $index, string $target, int $bytes): void
{
    $source = @$zip->getStreamIndex($index);
    if ($source === false) {
        throw new \RuntimeException('ZIP内のファイルを読み取れませんでした。');
    }
    try {
        $output = @fopen($target, 'xb');
        if ($output === false) {
            throw new \RuntimeException('ZIP内の名前が重複しているか、ファイルを作成できませんでした。');
        }
        try {
            $written = @stream_copy_to_stream($source, $output, $bytes + 1);
            if ($written !== $bytes || !fflush($output)) {
                throw new \RuntimeException('ZIP内のファイルを完全に展開できませんでした。');
            }
        } finally {
            fclose($output);
        }
    } finally {
        fclose($source);
    }
}

function with_storage_lock(string $root, callable $operation): void
{
    $path = $root . DIRECTORY_SEPARATOR . '.dropage-lock';
    $lock = is_link($path) || (file_exists($path) && !is_file($path)) ? false : @fopen($path, 'c');
    if ($lock === false) {
        throw new \RuntimeException('/extras に書き込めません。');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new \RuntimeException('/extras をロックできませんでした。');
        }
        $operation();
    } finally {
        fclose($lock);
    }
}

function publish_archive(string $archive, string $name): void
{
    if (!valid_component($name)) {
        throw new \RuntimeException('公開先の名前を使用できません。');
    }
    $root = storage_root();
    if (!is_dir($root) && !@mkdir($root, 0755) && !local_directory($root)) {
        throw new \RuntimeException('/extras を作成できません。サイトの公開ディレクトリの書き込み権限を確認してください。');
    }

    with_storage_lock($root, static function () use ($archive, $name, $root): void {
        $target = $root . DIRECTORY_SEPARATOR . $name;
        clearstatcache(true, $target);
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('同じ名前の公開先が既にあります。ZIPの名前を変更してください。');
        }

        $stage = $root . DIRECTORY_SEPARATOR . '.dropage-' . bin2hex(random_bytes(16));
        if (!@mkdir($stage, 0700)) {
            throw new \RuntimeException('展開用のディレクトリを作成できませんでした。');
        }
        try {
            $entry = extract_archive($archive, $stage);
            if (@file_put_contents($stage . DIRECTORY_SEPARATOR . PAGE_MARKER, $entry) !== strlen($entry)
                || !@chmod($stage, 0755) || !@rename($stage, $target)) {
                throw new \RuntimeException('展開したページを公開できませんでした。');
            }
        } finally {
            if (file_exists($stage) && !remove_tree($stage)) {
                throw new \RuntimeException('展開用ファイルを完全に削除できませんでした。');
            }
        }
    });
}

function read_entry(string $directory): ?string
{
    $marker = $directory . DIRECTORY_SEPARATOR . PAGE_MARKER;
    if (!local_directory($directory) || is_link($marker) || !is_file($marker)) {
        return null;
    }
    $entry = @file_get_contents($marker, false, null, 0, 1025);
    if ($entry === false || strlen($entry) > 1024
        || in_array(false, array_map(__NAMESPACE__ . '\\valid_component', explode('/', $entry)), true)) {
        return null;
    }
    return $entry;
}

function published_pages(string $root): array
{
    $names = @scandir($root);
    if ($names === false) {
        throw new \RuntimeException('/extras の一覧を取得できませんでした。');
    }
    $pages = [];
    foreach ($names as $name) {
        if (valid_component($name)) {
            $entry = read_entry($root . DIRECTORY_SEPARATOR . $name);
            if ($entry !== null) {
                $pages[$name] = $entry;
            }
        }
    }
    return $pages;
}

function unpublish(string $name): void
{
    if (!valid_component($name)) {
        throw new \RuntimeException('削除対象を確認できませんでした。');
    }
    $root = storage_root();
    with_storage_lock($root, static function () use ($name, $root): void {
        $target = $root . DIRECTORY_SEPARATOR . $name;
        clearstatcache(true, $target);
        if (read_entry($target) === null) {
            throw new \RuntimeException('削除対象の公開ページが見つかりません。');
        }
        $stage = $root . DIRECTORY_SEPARATOR . '.dropage-' . bin2hex(random_bytes(16));
        if (!@rename($target, $stage)) {
            throw new \RuntimeException('ページの公開を停止できませんでした。');
        }
        if (!remove_tree($stage)) {
            throw new \RuntimeException('公開は停止しましたが、一部のファイルを削除できませんでした。');
        }
    });
}

function remove_tree(string $path): bool
{
    if (!local_directory($path)) {
        // Do not traverse links or filesystem junctions.
        return @unlink($path) || (is_dir($path) && @rmdir($path));
    }
    $names = @scandir($path);
    if ($names === false) {
        return false;
    }
    $removed = true;
    foreach ($names as $name) {
        if ($name !== '.' && $name !== '..') {
            $removed = remove_tree($path . DIRECTORY_SEPARATOR . $name) && $removed;
        }
    }
    return @rmdir($path) && $removed;
}
