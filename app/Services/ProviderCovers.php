<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cover photos across the top of provider profiles. Like profile photos,
 * every upload is re-drawn as a JPEG (3:1 here), which removes hidden data
 * such as GPS positions. Files get random names, and the old cover is
 * deleted when replaced.
 */
class ProviderCovers
{
    public const DIRECTORY = 'covers';
    private const WIDTH = 1200;
    private const HEIGHT = 400;

    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    public function replace(User $owner, UploadedFile $file): ProviderProfile
    {
        $profile = $this->profileOf($owner);
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));

        if (! $image) {
            throw new BusinessRuleException('image_unreadable', 'We couldn’t read that photo. Try a JPG or PNG file.', 422);
        }

        $image = $this->cropToCover(ProfilePhotos::upright($image, $file));

        ob_start();
        imagejpeg($image, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $path = self::DIRECTORY.'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $jpeg);
        $old = $profile->cover_path;

        DB::transaction(function () use ($profile, $path, $owner) {
            $profile->forceFill(['cover_path' => $path])->save();
            $this->audit->record('provider.cover_changed', $profile, [], [], null, $owner);
        });

        $this->deleteFile($old);

        return $profile;
    }

    public function remove(User $owner): ProviderProfile
    {
        $profile = $this->profileOf($owner);
        $old = $profile->cover_path;

        if ($old) {
            DB::transaction(function () use ($profile, $owner) {
                $profile->forceFill(['cover_path' => null])->save();
                $this->audit->record('provider.cover_removed', $profile, [], [], null, $owner);
            });
            $this->deleteFile($old);
        }

        return $profile;
    }

    /** Only the person who owns the provider profile changes its cover */
    private function profileOf(User $owner): ProviderProfile
    {
        $profile = $owner->providerProfile;
        if (! $profile) {
            throw new BusinessRuleException('no_provider_profile', 'Set up your provider profile first.', 409);
        }

        return $profile;
    }

    /** @param resource|\GdImage $image */
    private function cropToCover($image)
    {
        // Centre crop to 3:1, then scale to the stored size
        $width = imagesx($image);
        $height = imagesy($image);
        $cropWidth = min($width, (int) round($height * self::WIDTH / self::HEIGHT));
        $cropHeight = (int) round($cropWidth * self::HEIGHT / self::WIDTH);

        $cover = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        // White behind transparent PNGs instead of black
        imagefill($cover, 0, 0, imagecolorallocate($cover, 255, 255, 255));
        imagecopyresampled(
            $cover, $image, 0, 0,
            (int) (($width - $cropWidth) / 2), (int) (($height - $cropHeight) / 2),
            self::WIDTH, self::HEIGHT, $cropWidth, $cropHeight
        );
        imagedestroy($image);

        return $cover;
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Str::startsWith($path, self::DIRECTORY.'/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
