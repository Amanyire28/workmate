<?php

namespace App\Services;

use RuntimeException;

/**
 * Draws the WorkMate stamp (logo and wm.resnetsystems.com) into a photo's
 * bottom-right corner (config/watermark.php). It becomes part of the
 * picture itself, so it stays when the photo is saved, shared or
 * screenshotted. Only PhotoVariants calls it, for public photos.
 */
class ImageWatermark
{
    /** @var resource|\GdImage|null the stamp, read once */
    private $stamp;

    public function enabledFor(?string $category): bool
    {
        return $category !== null && config('watermark.enabled') && ! empty(config('watermark.categories.'.$category));
    }

    /**
     * Where the stamp goes on a photo of this size: always inside it.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function placement(int $width, int $height): array
    {
        [$stampW, $stampH] = $this->stampSize();
        $short = min($width, $height);
        $margin = max(6, (int) round($short * (float) config('watermark.margin')));

        // A share of the width: opened on a phone, photos of every shape fill the
        // screen's width, so the stamp reads the same on all of them
        $w = $width * (float) config('watermark.size');
        $w = max($w, (float) config('watermark.min_width'));
        $w = min($w, $width * (float) config('watermark.max_share'), $width - 2 * $margin);
        // Never too tall on wide photos (panoramas)
        $maxH = min($height * (float) config('watermark.max_height_share'), $height - 2 * $margin);
        $h = $w * $stampH / $stampW;
        if ($h > $maxH) {
            $h = max(1, $maxH);
            $w = $h * $stampW / $stampH;
        }
        $w = max(1, (int) floor($w));
        $h = max(1, (int) floor($h));

        return [
            'x' => max(0, $width - $margin - $w),
            'y' => max(0, $height - $margin - $h),
            'width' => min($w, $width),
            'height' => min($h, $height),
        ];
    }

    /**
     * Draws the stamp onto the image (changed in place).
     *
     * @param resource|\GdImage $image
     * @throws RuntimeException when the stamp can't be read
     */
    public function apply($image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $at = $this->placement($width, $height);

        $mark = imagecreatetruecolor($at['width'], $at['height']);
        imagealphablending($mark, false);
        imagesavealpha($mark, true);
        imagefill($mark, 0, 0, imagecolorallocatealpha($mark, 0, 0, 0, 127));
        imagecopyresampled($mark, $this->stamp(), 0, 0, 0, 0, $at['width'], $at['height'], imagesx($this->stamp()), imagesy($this->stamp()));

        // The logo and address at the chosen opacity; the soft dark halo around
        // them a little stronger, so white letters stand out on bright photos
        $opacity = max(0.0, min(1.0, (float) config('watermark.opacity')));
        $halo = max(0.0, min(1.0, (float) config('watermark.shadow_opacity', $opacity)));
        for ($y = 0; $y < $at['height']; $y++) {
            for ($x = 0; $x < $at['width']; $x++) {
                $rgba = imagecolorat($mark, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha === 127) {
                    continue;
                }
                // Dark pixels are the halo, light or coloured ones the logo and letters
                $light = (0.299 * (($rgba >> 16) & 0xFF) + 0.587 * (($rgba >> 8) & 0xFF) + 0.114 * ($rgba & 0xFF)) / 255;
                $mix = min(1.0, $light / 0.35);
                $strength = $halo + ($opacity - $halo) * $mix;
                $alpha = 127 - (int) round((127 - $alpha) * $strength);
                imagesetpixel($mark, $x, $y, ($alpha << 24) | ($rgba & 0xFFFFFF));
            }
        }

        imagealphablending($image, true);
        imagecopy($image, $mark, $at['x'], $at['y'], 0, 0, $at['width'], $at['height']);
        imagedestroy($mark);
    }

    /** @return array{0: int, 1: int} */
    private function stampSize(): array
    {
        return [imagesx($this->stamp()), imagesy($this->stamp())];
    }

    private function stamp()
    {
        if ($this->stamp) {
            return $this->stamp;
        }
        $path = (string) config('watermark.stamp');
        $stamp = is_file($path) ? @imagecreatefrompng($path) : false;
        if (! $stamp) {
            throw new RuntimeException('The watermark stamp can’t be read: '.$path);
        }
        imagealphablending($stamp, false);
        imagesavealpha($stamp, true);

        return $this->stamp = $stamp;
    }
}
