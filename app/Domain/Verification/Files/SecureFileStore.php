<?php

namespace App\Domain\Verification\Files;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores identity documents, vehicle photos and support attachments on a private disk.
 *
 * - The type is sniffed from the bytes (finfo), never taken from the name or the browser.
 * - Images (JPEG, PNG, WebP) are decoded and re-encoded with GD: EXIF (including GPS position),
 *   comments and anything appended to the file are dropped, the picture is turned upright from
 *   its EXIF orientation first, and very large images are scaled down. A decompression bomb is
 *   refused before decoding by a megapixel cap.
 * - PDFs must start with "%PDF-" and are stored as uploaded (metadata is not stripped); they are
 *   only ever streamed back as downloads with nosniff.
 * - There is NO virus scanning on this host (no ClamAV or scanning service is configured).
 */
class SecureFileStore
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param  list<string>  $kinds  'image' and/or 'pdf'
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $disk, string $directory, array $kinds, string $field = 'file'): StoredFile
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([$field => 'The upload did not complete. Please try again.']);
        }

        $realPath = (string) $file->getRealPath();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $size = (int) filesize($realPath);
        $originalName = $this->safeName($file->getClientOriginalName());

        if (in_array($mime, self::IMAGE_MIMES, true) && in_array('image', $kinds, true)) {
            $this->guardSize($size, (int) config('ehail_safety.uploads.max_image_kb'), $field);
            [$bytes, $outMime, $extension] = $this->reencode($realPath, $mime, $field);
        } elseif ($mime === 'application/pdf' && in_array('pdf', $kinds, true)) {
            $this->guardSize($size, (int) config('ehail_safety.uploads.max_pdf_kb'), $field);
            $bytes = (string) file_get_contents($realPath);

            if (! str_starts_with($bytes, '%PDF-')) {
                throw ValidationException::withMessages([$field => 'That file is not a valid PDF.']);
            }

            [$outMime, $extension] = ['application/pdf', 'pdf'];
        } else {
            $allowed = implode(' or ', array_map(fn (string $k) => $k === 'image' ? 'a JPEG, PNG or WebP photo' : 'a PDF', $kinds));

            throw ValidationException::withMessages([$field => "This must be {$allowed}."]);
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.'.$extension;
        Storage::disk($disk)->put($path, $bytes);

        return new StoredFile($disk, $path, $originalName, $outMime, strlen($bytes), hash('sha256', $bytes));
    }

    private function guardSize(int $size, int $maxKb, string $field): void
    {
        if ($size > $maxKb * 1024) {
            throw ValidationException::withMessages([$field => 'The file is larger than '.round($maxKb / 1024).' MB.']);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} bytes, mime, extension
     */
    private function reencode(string $path, string $mime, string $field): array
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::withMessages([$field => 'That image could not be read.']);
        }

        if ((int) config('ehail_safety.uploads.max_megapixels') * 1_000_000 < $info[0] * $info[1]) {
            throw ValidationException::withMessages([$field => 'That image is too large (too many pixels).']);
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages([$field => 'That image could not be read.']);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->orient($image, $path);
        }

        $image = $this->limitEdge($image, (int) config('ehail_safety.uploads.max_edge_px'));

        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
            [$outMime, $extension] = ['image/png', 'png'];
        } else {
            // JPEG and WebP come out as JPEG: universally viewable, no metadata written by GD.
            imagejpeg($image, null, (int) config('ehail_safety.uploads.jpeg_quality'));
            [$outMime, $extension] = ['image/jpeg', 'jpg'];
        }
        $bytes = (string) ob_get_clean();

        return [$bytes, $outMime, $extension];
    }

    private function orient(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function limitEdge(GdImage $image, int $maxEdge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($maxEdge < 1 || $longest <= $maxEdge) {
            return $image;
        }

        $scale = $maxEdge / $longest;
        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /** A display name only (never used as a path): no directories, no control characters, short. */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\pL\pN ._()-]+/u', '_', $name);

        return mb_substr(trim($name) !== '' ? $name : 'document', 0, 120);
    }
}
