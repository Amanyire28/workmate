<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile photos. Every upload is re-drawn as a square JPEG, which also
 * removes hidden data such as the GPS position phones store in photos.
 * Files get random names, and the old photo is deleted when replaced.
 */
class ProfilePhotos
{
    public const DIRECTORY = 'avatars';

    /** Small versions a page may ask for (?s=96): a list of people needs 40 pixels, not 512 */
    public const THUMBNAIL_SIZES = [64, 96, 128, 192];
    private const SIZE = 512;
    private const MAX_SOURCE_SIDE = 2048;

    /** @var AuditLogger */
    private $audit;

    /**
     * A small square copy of a stored profile photo (JPEG bytes), or null if it can't be made (the
     * original is served then). Made as it is asked for; the photo's address changes with every
     * upload, so browsers keep the result for good.
     */
    public function thumbnail(string $path, int $size): ?string
    {
        if (! in_array($size, self::THUMBNAIL_SIZES, true) || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $bytes = \Illuminate\Support\Facades\Storage::disk('local')->get($path);
        // Stored photos are 512 px squares. Anything far bigger is not decoded (a huge picture would need
        // hundreds of megabytes to open): it is served as it is
        $info = $bytes ? @getimagesizefromstring($bytes) : false;
        if (! $info || $info[0] > self::MAX_SOURCE_SIDE || $info[1] > self::MAX_SOURCE_SIDE) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }
        $square = imagecreatetruecolor($size, $size);
        imagecopyresampled($square, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
        ob_start();
        imagejpeg($square, null, 82);
        $out = ob_get_clean();
        imagedestroy($source);
        imagedestroy($square);

        return $out ?: null;
    }

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    public function replace(User $user, UploadedFile $file): User
    {
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));

        if (! $image) {
            throw new BusinessRuleException('image_unreadable', 'We couldn’t read that photo. Try a JPG or PNG file.', 422);
        }

        $image = $this->squareAndUpright($image, $file);

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $path = self::DIRECTORY.'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $jpeg);
        $old = $user->avatar_path;

        DB::transaction(function () use ($user, $path, $old) {
            $user->forceFill(['avatar_path' => $path])->save();
            $this->audit->record('account.photo_changed', $user, [], [], null, $user);
        });

        $this->deleteFile($old);

        return $user;
    }

    public function remove(User $user): User
    {
        $old = $user->avatar_path;

        if ($old) {
            $user->forceFill(['avatar_path' => null])->save();
            $this->audit->record('account.photo_removed', $user, [], [], null, $user);
            $this->deleteFile($old);
        }

        return $user;
    }

    /**
     * Phones often save photos sideways with an "orientation" note; apply it
     *
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    public static function upright($image, UploadedFile $file)
    {
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

    /** @param resource|\GdImage $image */
    private function squareAndUpright($image, UploadedFile $file)
    {
        $image = self::upright($image, $file);

        // Centre crop to a square, then scale to the stored size
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        // White behind transparent PNGs instead of black
        imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled(
            $square, $image, 0, 0,
            (int) (($width - $side) / 2), (int) (($height - $side) / 2),
            self::SIZE, self::SIZE, $side, $side
        );
        imagedestroy($image);

        return $square;
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Str::startsWith($path, self::DIRECTORY.'/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
