<?php

namespace App\Domain\Verification\Files;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a private file after the caller has authorised the viewer. Images are shown inline,
 * everything else downloads; the type is the one sniffed at upload; nothing is cached; the
 * response cannot run script even if a file were crafted to look like HTML.
 */
class PrivateFileResponse
{
    public static function make(string $disk, string $path, string $name, string $mime): Response
    {
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($path), 404, 'The file is no longer available.');

        $inline = str_starts_with($mime, 'image/');

        return $storage->response($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'X-Robots-Tag' => 'noindex, nofollow',
        ], $inline ? 'inline' : 'attachment');
    }
}
