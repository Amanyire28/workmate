<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos people add: community posts, photos of a job on a request, a
 * provider's recent work and photos sent in chats. Each
 * one is re-drawn as JPEG, which drops hidden data such as GPS positions and
 * anything that isn't a picture, in a full size and a thumbnail. They live on
 * the local disk, never under public/, and are served only through the API,
 * to the people allowed to see them.
 *
 * Public kinds (community, work: config/watermark.php) get the WorkMate
 * watermark in both sizes. A clean copy of the full size is kept privately
 * (original_path) so the watermark can be redone without stacking; it is
 * never served.
 */
class PhotoVariants
{
    /** Where each kind of photo is kept, under storage/app */
    public const DIRECTORIES = ['community', 'request-photos', 'work', 'chat', 'chat-files'];

    private const FULL_SIDE = 1600;
    private const THUMB_SIDE = 720;

    /** @var ImageWatermark */
    private $watermark;

    public function __construct(ImageWatermark $watermark)
    {
        $this->watermark = $watermark;
    }

    /**
     * @param  string|null  $category  the kind of photo, for the watermark (community, work)
     * @return array{uuid: string, path: string, thumb_path: string, width: int, height: int, original_path?: string, watermarked_at?: \Illuminate\Support\Carbon}
     */
    public function store(UploadedFile $file, string $directory, ?string $category = null): array
    {
        $image = $this->decode($file->getRealPath());
        $image = $this->upright($image, $file);
        $marked = $this->watermark->enabledFor($category);

        $uuid = (string) Str::uuid();
        $full = $this->resized($image, self::FULL_SIDE);
        $thumb = $this->resized($image, self::THUMB_SIDE);
        imagedestroy($image);
        $width = imagesx($full);
        $height = imagesy($full);

        $files = [];
        try {
            if ($marked) {
                // The clean copy first, then the stamp on each size (drawn for its own size, so it stays sharp)
                $files[$directory.'/'.$uuid.'-o.jpg'] = $this->jpeg($full, 90);
                $this->watermark->apply($full);
                $this->watermark->apply($thumb);
            }
            $files[$directory.'/'.$uuid.'.jpg'] = $this->jpeg($full, $marked ? (int) config('watermark.quality.full') : 84);
            $files[$directory.'/'.$uuid.'-t.jpg'] = $this->jpeg($thumb, $marked ? (int) config('watermark.quality.thumb') : 78);
        } catch (\Throwable $e) {
            // Never publish a photo without its watermark
            Log::error('Photo not processed: '.$e->getMessage(), ['category' => $category]);
            throw new BusinessRuleException('image_processing_failed', 'We couldn’t prepare one of the photos. Please try again.', 500);
        } finally {
            imagedestroy($full);
            imagedestroy($thumb);
        }

        // Written only once everything is ready
        foreach ($files as $path => $bytes) {
            Storage::disk('local')->put($path, $bytes);
        }

        $stored = ['uuid' => $uuid, 'path' => $directory.'/'.$uuid.'.jpg', 'thumb_path' => $directory.'/'.$uuid.'-t.jpg', 'width' => $width, 'height' => $height];
        if ($marked) {
            $stored += ['original_path' => $directory.'/'.$uuid.'-o.jpg', 'watermarked_at' => now()];
        }

        return $stored;
    }

    /**
     * Watermarks a photo that's already stored (photos from before the
     * watermark, or redoing one). Works from the clean copy, so a photo never
     * gets two stamps; the clean copy is never changed or removed.
     *
     * @param  Model  $media  a CommunityPostMedia or ProviderWorkPhoto
     * @return bool false when it was already done (and not redone)
     */
    public function watermarkStored(Model $media, bool $redo = false): bool
    {
        if ($media->watermarked_at && ! $redo) {
            return false;
        }
        $disk = Storage::disk('local');
        $legacy = ! $media->original_path;
        $source = $legacy ? $media->path : $media->original_path;
        if (! $source || ! $disk->exists($source)) {
            throw new \RuntimeException('The photo file is missing: '.$source);
        }

        $image = $this->decode($disk->path($source));
        $full = $this->resized($image, self::FULL_SIDE);
        $thumb = $this->resized($image, self::THUMB_SIDE);
        imagedestroy($image);
        try {
            $this->watermark->apply($full);
            $this->watermark->apply($thumb);
            $fullBytes = $this->jpeg($full, (int) config('watermark.quality.full'));
            $thumbBytes = $this->jpeg($thumb, (int) config('watermark.quality.thumb'));
        } finally {
            imagedestroy($full);
            imagedestroy($thumb);
        }

        // Older photos: the existing file becomes the clean copy, untouched; the watermarked ones are new files
        $base = dirname($source).'/'.$media->uuid;
        $path = $legacy ? $base.'-w.jpg' : $media->path;
        $thumbPath = $legacy ? $base.'-wt.jpg' : $media->thumb_path;
        $this->replace($disk->path($path), $fullBytes);
        $this->replace($disk->path($thumbPath), $thumbBytes);

        $media->forceFill([
            'path' => $path,
            'thumb_path' => $thumbPath,
            'original_path' => $source,
            'watermarked_at' => now(),
            'watermark_error' => null,
        ])->save();

        return true;
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

    /** @param array<int, array> $stored what store() returned: every file it wrote */
    public function discardStored(array $stored): void
    {
        $this->discard(array_values(array_filter(array_merge(
            array_column($stored, 'path'), array_column($stored, 'thumb_path'), array_column($stored, 'original_path')
        ))));
    }

    /**
     * Reads a photo safely: the size is checked before decoding, so a small
     * file claiming a huge picture can't exhaust the server's memory.
     *
     * @return resource|\GdImage
     */
    private function decode(string $path)
    {
        $info = @getimagesize($path);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new BusinessRuleException('image_unreadable', 'We couldn’t read one of the photos. Use JPG, PNG or WebP photos.', 422);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > (int) config('watermark.max_side') || $info[1] > (int) config('watermark.max_side')
            || $info[0] * $info[1] > (int) config('watermark.max_pixels')) {
            throw new BusinessRuleException('image_too_large', 'One of the photos is too large. Use a photo up to '.(int) config('watermark.max_side').' pixels across.', 422);
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image) {
            throw new BusinessRuleException('image_unreadable', 'We couldn’t read one of the photos. Use JPG, PNG or WebP photos.', 422);
        }

        return $image;
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
                $rotated = imagerotate($image, $angles[$orientation], 0);
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }

    /**
     * A copy no larger than $side on its longest edge, on white (for transparent PNGs).
     *
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    private function resized($image, int $side)
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

        return $canvas;
    }

    /** @param resource|\GdImage $image */
    private function jpeg($image, int $quality): string
    {
        imageinterlace($image, true);
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Swaps a file in whole (never half-written while someone is looking at it) */
    private function replace(string $path, string $bytes): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $path.'.'.Str::random(8).'.tmp';
        file_put_contents($tmp, $bytes);
        if (! @rename($tmp, $path)) {
            // Windows won't replace an existing file by renaming
            @unlink($path);
            rename($tmp, $path);
        }
    }
}
