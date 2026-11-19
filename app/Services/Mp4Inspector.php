<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;

/**
 * Checks an uploaded video from its MP4 header alone: the container, the real
 * length, the picture size and the codecs. Only a few small byte ranges are
 * read (into memory), never the whole file.
 *
 * What it can't check: that every frame decodes. A file whose header is
 * valid but whose picture data is damaged will pass and simply not play.
 *
 * For the pilot only H.264 video (avc1/avc3) with AAC sound (mp4a), or no
 * sound, in an MP4 file is accepted: what every phone browser can play.
 */
class Mp4Inspector
{
    private const FIRST_READ = 65536;
    private const MAX_TOP_BOXES = 64;

    /** @var callable */
    private $read;

    /** @var string */
    private $head = '';

    /**
     * @param  callable(int $offset, int $length): string  $read  bytes from the file
     * @return array{duration: float, width: int, height: int, video_codec: string, audio_codec: ?string, brand: string}
     * @throws BusinessRuleException when the file isn't a video we accept
     */
    public function inspect(callable $read, int $size, int $maxHeaderBytes): array
    {
        $this->read = $read;
        $this->head = $read(0, min($size, self::FIRST_READ));

        $brand = null;
        $moov = null;
        $offset = 0;
        for ($i = 0; $i < self::MAX_TOP_BOXES && $offset < $size; $i++) {
            $header = $this->bytes($offset, (int) min(16, $size - $offset));
            if (strlen($header) < 8) {
                throw $this->reject('video_damaged');
            }
            [$boxSize, $type, $headerSize] = $this->boxHeader($header, $size - $offset);
            if ($i === 0 && $type !== 'ftyp') {
                throw $this->reject('video_not_mp4');
            }
            if ($type === 'ftyp') {
                $brand = substr($this->bytes($offset + $headerSize, 4), 0, 4);
            } elseif ($type === 'moov') {
                if ($boxSize - $headerSize > $maxHeaderBytes) {
                    throw $this->reject('video_header_too_large');
                }
                $moov = $this->bytes($offset + $headerSize, $boxSize - $headerSize);
            } elseif ($type === 'moof') {
                // Split ("fragmented") MP4s don't say their length up front
                throw $this->reject('video_fragmented');
            }
            $offset += $boxSize;
        }

        if ($brand === null) {
            throw $this->reject('video_not_mp4');
        }
        if ($brand === 'qt  ') {
            throw $this->reject('video_mov');
        }
        if ($moov === null || strlen($moov) < 8) {
            throw $this->reject('video_damaged');
        }

        return $this->readMovie($moov) + ['brand' => $brand];
    }

    private function readMovie(string $moov): array
    {
        $duration = null;
        $video = null;
        $audio = [];
        foreach ($this->children($moov) as [$type, $payload]) {
            if ($type === 'mvhd') {
                $duration = $this->movieDuration($payload);
            } elseif ($type === 'trak') {
                $track = $this->readTrack($payload);
                if ($track['handler'] === 'vide' && $video === null) {
                    $video = $track;
                } elseif ($track['handler'] === 'soun') {
                    $audio[] = $track['format'];
                }
            }
        }

        if (! $duration || $duration <= 0) {
            throw $this->reject('video_damaged');
        }
        if ($video === null) {
            throw $this->reject('video_no_picture');
        }
        if (in_array($video['format'], ['hvc1', 'hev1'], true)) {
            throw $this->reject('video_hevc');
        }
        if (! in_array($video['format'], ['avc1', 'avc3'], true)) {
            throw $this->reject('video_codec');
        }
        foreach ($audio as $format) {
            if ($format !== 'mp4a') {
                throw $this->reject('video_audio_codec');
            }
        }
        if ($video['width'] < 16 || $video['height'] < 16 || $video['width'] > 4096 || $video['height'] > 4096) {
            throw $this->reject('video_size');
        }

        return [
            'duration' => round($duration, 2),
            'width' => $video['width'],
            'height' => $video['height'],
            'video_codec' => $video['format'],
            'audio_codec' => $audio[0] ?? null,
        ];
    }

    /** @return array{handler: ?string, format: ?string, width: int, height: int} */
    private function readTrack(string $trak): array
    {
        $track = ['handler' => null, 'format' => null, 'width' => 0, 'height' => 0];
        foreach ($this->children($trak) as [$type, $payload]) {
            if ($type === 'tkhd' && strlen($payload) >= 84) {
                // Width and height (16.16 fixed point) are the box's last 8 bytes
                $track['width'] = (int) (self::u32($payload, strlen($payload) - 8) >> 16);
                $track['height'] = (int) (self::u32($payload, strlen($payload) - 4) >> 16);
            } elseif ($type === 'mdia') {
                foreach ($this->children($payload) as [$mType, $mPayload]) {
                    if ($mType === 'hdlr' && strlen($mPayload) >= 12) {
                        $track['handler'] = substr($mPayload, 8, 4);
                    } elseif ($mType === 'minf') {
                        $track['format'] = $this->sampleFormat($mPayload);
                    }
                }
            }
        }

        return $track;
    }

    /** The first sample entry's format in minf → stbl → stsd (e.g. avc1, hvc1, mp4a) */
    private function sampleFormat(string $minf): ?string
    {
        foreach ($this->children($minf) as [$type, $payload]) {
            if ($type !== 'stbl') {
                continue;
            }
            foreach ($this->children($payload) as [$sType, $sPayload]) {
                if ($sType === 'stsd' && strlen($sPayload) >= 16) {
                    return substr($sPayload, 12, 4);
                }
            }
        }

        return null;
    }

    private function movieDuration(string $mvhd): ?float
    {
        if (strlen($mvhd) < 20) {
            return null;
        }
        if (ord($mvhd[0]) === 1) {
            if (strlen($mvhd) < 32) {
                return null;
            }
            $scale = self::u32($mvhd, 20);
            $length = self::u64($mvhd, 24);
        } else {
            $scale = self::u32($mvhd, 12);
            $length = self::u32($mvhd, 16);
        }

        return $scale > 0 ? $length / $scale : null;
    }

    /**
     * The boxes inside a box's payload.
     *
     * @return \Generator<array{0: string, 1: string}>
     */
    private function children(string $data): \Generator
    {
        $offset = 0;
        $length = strlen($data);
        for ($i = 0; $i < 256 && $offset + 8 <= $length; $i++) {
            [$size, $type, $headerSize] = $this->boxHeader(substr($data, $offset, 16), $length - $offset);
            yield [$type, (string) substr($data, $offset + $headerSize, $size - $headerSize)];
            $offset += $size;
        }
    }

    /** @return array{0: int, 1: string, 2: int} size, type, header size */
    private function boxHeader(string $header, int $remaining): array
    {
        $size = self::u32($header, 0);
        $type = substr($header, 4, 4);
        $headerSize = 8;
        if ($size === 1) {
            if (strlen($header) < 16) {
                throw $this->reject('video_damaged');
            }
            $size = self::u64($header, 8);
            $headerSize = 16;
        } elseif ($size === 0) {
            $size = $remaining;
        }
        if ($size < $headerSize || $size > $remaining) {
            throw $this->reject('video_damaged');
        }

        return [$size, $type, $headerSize];
    }

    private function bytes(int $offset, int $length): string
    {
        if ($offset + $length <= strlen($this->head)) {
            return (string) substr($this->head, $offset, $length);
        }

        return ($this->read)($offset, $length);
    }

    private static function u32(string $s, int $at): int
    {
        return unpack('N', substr($s, $at, 4))[1];
    }

    private static function u64(string $s, int $at): int
    {
        $parts = unpack('N2', substr($s, $at, 8));

        return ($parts[1] << 32) | $parts[2];
    }

    private function reject(string $code): BusinessRuleException
    {
        $messages = [
            'video_not_mp4' => 'This file isn’t an MP4 video. Choose an MP4 video.',
            'video_mov' => 'This is a MOV video. Choose an MP4 video, or on an iPhone set Camera → Formats → Most Compatible and record again.',
            'video_damaged' => 'This video looks damaged and can’t be used. Try recording it again.',
            'video_header_too_large' => 'We couldn’t check this video. Try a shorter clip.',
            'video_fragmented' => 'This kind of MP4 can’t be used yet. Try saving or recording the video again.',
            'video_no_picture' => 'This file has no picture. Choose a video.',
            'video_hevc' => 'This video uses the H.265 (HEVC) format, which many phones can’t play. Record in H.264 (“Most Compatible”) and try again.',
            'video_codec' => 'This video’s format can’t be played on most phones. Choose an MP4 recorded in H.264.',
            'video_audio_codec' => 'This video’s sound format can’t be played on most phones. Choose a video with standard (AAC) sound.',
            'video_size' => 'This video’s picture size isn’t supported.',
        ];

        return new BusinessRuleException($code, $messages[$code], 422);
    }
}
