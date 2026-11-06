<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos people add: community posts and photos of a job on a request. Each
 * one is re-drawn as JPEG, which drops hidden data such as GPS positions and
 * anything that isn't a picture, in a full size and a thumbnail. They live on
 * the local disk, never under public/, and are served only through the API,
 * to the people allowed to see them.
 */
class PhotoVariants
{
    /** Where each kind of photo is kept, under storage/app */
    public const DIRECTORIES = ['community', 'request-photos'];

    private const FULL_SIDE = 1600;
    private const THUMB_SIDE = 720;

    /** @return array{uuid: string, path: string, thumb_path: string, width: int, height: int} */
    public function store(UploadedFile $file, string $directory): array
    {
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw new BusinessRuleException('image_unreadable', 'We couldn’t read one of the photos. Use JPG, PNG or WebP photos.', 422);
        }
        $image = $this->upright($image, $file);

        $uuid = (string) Str::uuid();
        [$full, $width, $height] = $this->scaled($image, self::FULL_SIDE, 84);
        [$thumb] = $this->scaled($image, self::THUMB_SIDE, 78);
        imagedestroy($image);

        $path = $directory.'/'.$uuid.'.jpg';
        $thumbPath = $directory.'/'.$uuid.'-t.jpg';
        Storage::disk('local')->put($path, $full);
        Storage::disk('local')->put($thumbPath, $thumb);

        return ['uuid' => $uuid, 'path' => $path, 'thumb_path' => $thumbPath, 'width' => $width, 'height' => $height];
    }

    /** @param string[] $paths Removes files nothing refers to (after a failed save) */
    public function discard(array $paths): void
    {
        Storage::disk('local')->delete(array_filter($paths, function ($p) {
            return Str::startsWith($p, array_map(function ($d) {
                return $d.'/';
            }, self::DIRECTORIES));
        }));
    }

    /** @param resource|\GdImage $image */
    private function upright($image, UploadedFile $file)
    {
        // Phones often save photos sideways with an "orientation" note; apply it
        if (function_exists('exif_read_data') && in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($file->getRealPath());
            $angles = [3 => 180, 6 => -90, 8 => 90];
            $orientation = $exif['Orientation'] ?? 1;

            if (isset($angles[$orientation])) {
                $image = imagerotate($image, $angles[$orientation], 0);
            }
        }

        return $image;
    }

    /**
     * @param resource|\GdImage $image
     * @return array{0: string, 1: int, 2: int} JPEG bytes, width, height
     */
    private function scaled($image, int $side, int $quality): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $side / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        // White behind transparent PNGs instead of black
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        imageinterlace($canvas, true);

        ob_start();
        imagejpeg($canvas, null, $quality);
        imagedestroy($canvas);

        return [ob_get_clean(), $w, $h];
    }
}
