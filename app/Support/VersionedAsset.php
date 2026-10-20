<?php

namespace App\Support;

final class VersionedAsset
{
    /** Small fingerprint for the shared shell, used only to offer a reload (never force one). */
    public static function coreRelease(): string
    {
        $hashes = [];
        foreach (['css/app.css', 'css/ui.css', 'js/nav.js', 'js/release-notice.js'] as $path) {
            $file = public_path($path);
            $hashes[] = is_file($file) ? hash_file('sha256', $file) : $path;
        }

        return substr(hash('sha256', implode('|', $hashes)), 0, 16);
    }

    /** Content, not mtime: deploy archives may preserve timestamps. Paths are developer-owned. */
    public static function url(string $path): string
    {
        $url = asset($path);
        $file = public_path($path);
        if (! is_file($file)) {
            return $url;
        }

        return $url.'?v='.substr(hash_file('sha256', $file), 0, 16);
    }
}
