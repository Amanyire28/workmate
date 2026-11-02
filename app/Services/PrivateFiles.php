<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private uploads (verification documents, report evidence) on the local
 * disk, never under public/. Photos are re-drawn as JPEG, which strips hidden
 * data such as GPS positions; PDFs are kept as uploaded when allowed.
 */
class PrivateFiles
{
    private const MAX_IMAGE_SIDE = 2400;

    /**
     * Saves one file under $directory and returns its details.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $directory, bool $allowPdf = true): array
    {
        $bytes = file_get_contents($file->getRealPath());

        if ($allowPdf && strncmp($bytes, '%PDF-', 5) === 0) {
            $contents = $bytes;
            $mime = 'application/pdf';
            $extension = 'pdf';
        } else {
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                throw new BusinessRuleException('file_unreadable', 'We couldn’t read '.$this->safeName($file).'. Use a '.($allowPdf ? 'JPG, PNG or PDF' : 'JPG or PNG').' file.', 422);
            }
            $contents = $this->reencode($image, $file);
            $mime = 'image/jpeg';
            $extension = 'jpg';
        }

        $path = $directory.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return ['path' => $path, 'name' => $this->safeName($file), 'mime' => $mime, 'size' => strlen($contents)];
    }

    /** @param string[] $paths Removes files that nothing refers to (e.g. after a failed save) */
    public function discard(array $paths): void
    {
        Storage::disk('local')->delete($paths);
    }

    /** Serves a stored file inline; never cached */
    public function response(array $file)
    {
        abort_unless(Storage::disk('local')->exists($file['path']), 404);

        $response = response()->file(Storage::disk('local')->path($file['path']), [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.addcslashes($file['name'], '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // File responses default to public caching; private files must never be cached
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /** @param resource|\GdImage $image */
    private function reencode($image, UploadedFile $file): string
    {
        if (function_exists('exif_read_data') && in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($file->getRealPath());
            $angles = [3 => 180, 6 => -90, 8 => 90];
            $orientation = $exif['Orientation'] ?? 1;
            if (isset($angles[$orientation])) {
                $image = imagerotate($image, $angles[$orientation], 0);
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_IMAGE_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        imagedestroy($image);

        ob_start();
        imagejpeg($canvas, null, 88);
        imagedestroy($canvas);

        return ob_get_clean();
    }

    private function safeName(UploadedFile $file): string
    {
        $name = preg_replace('/[^\w.\- ]+/u', '', $file->getClientOriginalName());

        return Str::limit($name ?: 'file', 80, '');
    }
}
