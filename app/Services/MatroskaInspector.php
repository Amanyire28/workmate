<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;

/**
 * Checks an uploaded WebM or MKV video (Matroska / EBML structure) from its
 * header alone: the document type, the length (when the file says it), the
 * picture size and the codecs. Only small byte ranges are read into memory,
 * never the whole file.
 *
 * Videos recorded in browsers often don't state their length; it's then
 * unknown (null) rather than taken from what the phone claims.
 */
class MatroskaInspector
{
    private const FIRST_READ = 262144;
    private const MAX_ELEMENT_READ = 1048576;
    private const MAX_TOP_ELEMENTS = 64;

    // Element IDs (with their length markers, as written in the file)
    private const EBML = 0x1A45DFA3;
    private const DOC_TYPE = 0x4282;
    private const SEGMENT = 0x18538067;
    private const INFO = 0x1549A966;
    private const TIMECODE_SCALE = 0x2AD7B1;
    private const DURATION = 0x4489;
    private const TRACKS = 0x1654AE6B;
    private const TRACK_ENTRY = 0xAE;
    private const TRACK_TYPE = 0x83;
    private const CODEC_ID = 0x86;
    private const VIDEO = 0xE0;
    private const PIXEL_WIDTH = 0xB0;
    private const PIXEL_HEIGHT = 0xBA;
    private const CONTENT_ENCODINGS = 0x6D80;
    private const CLUSTER = 0x1F43B675;

    public const VIDEO_CODECS = ['V_VP8', 'V_VP9', 'V_AV1', 'V_MPEG4/ISO/AVC', 'V_MPEGH/ISO/HEVC'];

    /** @var callable */
    private $read;

    /** @var string */
    private $head = '';

    /** True when the file starts like EBML (WebM / MKV) */
    public static function looksLike(string $firstBytes): bool
    {
        return strncmp($firstBytes, "\x1A\x45\xDF\xA3", 4) === 0;
    }

    /**
     * @param  callable(int $offset, int $length): string  $read
     * @return array{duration: ?float, width: int, height: int, video_codec: string, audio_codec: ?string, container: string}
     * @throws BusinessRuleException
     */
    public function inspect(callable $read, int $size): array
    {
        $this->read = $read;
        $this->head = $read(0, min($size, self::FIRST_READ));

        // The EBML header: what kind of document this is
        [$id, $length, $at] = $this->element(0, $size);
        if ($id !== self::EBML || $length === null) {
            throw $this->reject('video_not_webm');
        }
        $docType = null;
        foreach ($this->children($this->bytes($at, $length)) as [$cid, $value]) {
            if ($cid === self::DOC_TYPE) {
                $docType = rtrim($value, "\0");
            }
        }
        if (! in_array($docType, ['webm', 'matroska'], true)) {
            throw $this->reject('video_not_webm');
        }

        // The segment: its Info and Tracks come before the first cluster of pictures
        $offset = $at + $length;
        [$id, $segmentLength, $offset] = $this->element($offset, $size);
        if ($id !== self::SEGMENT) {
            throw $this->reject('video_damaged');
        }
        $end = $segmentLength === null ? $size : min($size, $offset + $segmentLength);

        $info = null;
        $tracks = null;
        for ($i = 0; $i < self::MAX_TOP_ELEMENTS && $offset < $end && ($info === null || $tracks === null); $i++) {
            [$id, $length, $dataAt] = $this->element($offset, $end);
            if ($id === self::CLUSTER) {
                break;
            }
            if ($length === null) {
                throw $this->reject('video_damaged');
            }
            if ($id === self::INFO || $id === self::TRACKS) {
                if ($length > self::MAX_ELEMENT_READ) {
                    throw $this->reject('video_header_too_large');
                }
                ${$id === self::INFO ? 'info' : 'tracks'} = $this->bytes($dataAt, $length);
            }
            $offset = $dataAt + $length;
        }
        if ($tracks === null) {
            throw $this->reject('video_damaged');
        }

        // Length: Duration × TimecodeScale (nanoseconds); often missing in browser recordings
        $duration = null;
        if ($info !== null) {
            $scale = 1000000;
            $raw = null;
            foreach ($this->children($info) as [$cid, $value]) {
                if ($cid === self::TIMECODE_SCALE) {
                    $scale = self::uint($value) ?: 1000000;
                } elseif ($cid === self::DURATION) {
                    $raw = strlen($value) === 4 ? unpack('G', $value)[1] : (strlen($value) === 8 ? unpack('E', $value)[1] : null);
                }
            }
            if ($raw !== null && is_finite($raw) && $raw > 0) {
                $duration = round($raw * $scale / 1e9, 2);
            }
        }

        $video = null;
        $audio = null;
        foreach ($this->children($tracks) as [$cid, $entry]) {
            if ($cid !== self::TRACK_ENTRY) {
                continue;
            }
            $type = null;
            $codec = null;
            $width = 0;
            $height = 0;
            $encrypted = false;
            foreach ($this->children($entry) as [$tid, $value]) {
                if ($tid === self::TRACK_TYPE) {
                    $type = self::uint($value);
                } elseif ($tid === self::CODEC_ID) {
                    $codec = rtrim($value, "\0");
                } elseif ($tid === self::CONTENT_ENCODINGS) {
                    $encrypted = true;
                } elseif ($tid === self::VIDEO) {
                    foreach ($this->children($value) as [$vid, $v]) {
                        if ($vid === self::PIXEL_WIDTH) {
                            $width = self::uint($v);
                        } elseif ($vid === self::PIXEL_HEIGHT) {
                            $height = self::uint($v);
                        }
                    }
                }
            }
            if ($type === 1 && $video === null) {
                $video = compact('codec', 'width', 'height', 'encrypted');
            } elseif ($type === 2 && $audio === null) {
                $audio = $codec;
            }
        }

        if ($video === null) {
            throw $this->reject('video_no_picture');
        }
        if ($video['encrypted']) {
            throw $this->reject('video_encrypted');
        }
        if (! in_array($video['codec'], self::VIDEO_CODECS, true)) {
            throw $this->reject('video_codec');
        }
        if ($video['width'] < 16 || $video['height'] < 16 || $video['width'] > 8192 || $video['height'] > 8192) {
            throw $this->reject('video_size');
        }

        return [
            'duration' => $duration,
            'width' => $video['width'],
            'height' => $video['height'],
            'video_codec' => $video['codec'],
            'audio_codec' => $audio,
            'container' => $docType === 'webm' ? 'webm' : 'mkv',
        ];
    }

    /**
     * The element at $offset: [id, data length (null = unknown), where its data starts].
     *
     * @return array{0: int, 1: ?int, 2: int}
     */
    private function element(int $offset, int $limit): array
    {
        $header = $this->bytes($offset, (int) min(12, $limit - $offset));
        if (strlen($header) < 2) {
            throw $this->reject('video_damaged');
        }
        [$id, $idLength] = self::vint($header, 0, true);
        [$length, $lengthLength] = self::vint($header, $idLength, false);
        if ($id === null || $lengthLength === 0) {
            throw $this->reject('video_damaged');
        }
        $dataAt = $offset + $idLength + $lengthLength;
        if ($length !== null && $dataAt + $length > $limit) {
            // Only the segment may run to the end of the file
            if ($id !== self::SEGMENT) {
                throw $this->reject('video_damaged');
            }
        }

        return [$id, $length, $dataAt];
    }

    /**
     * The elements inside a master element's data: [id, value bytes].
     *
     * @return \Generator<array{0: int, 1: string}>
     */
    private function children(string $data): \Generator
    {
        $offset = 0;
        $length = strlen($data);
        for ($i = 0; $i < 512 && $offset < $length; $i++) {
            [$id, $idLength] = self::vint($data, $offset, true);
            [$size, $sizeLength] = self::vint($data, $offset + $idLength, false);
            if ($id === null || $sizeLength === 0 || $size === null) {
                return;
            }
            $start = $offset + $idLength + $sizeLength;
            if ($start + $size > $length) {
                return;
            }
            yield [$id, (string) substr($data, $start, $size)];
            $offset = $start + $size;
        }
    }

    /**
     * A variable-length integer: an element ID (marker kept) or a size (marker
     * removed; null when "unknown"). Returns [value, bytes used] (0 bytes = invalid).
     *
     * @return array{0: ?int, 1: int}
     */
    private static function vint(string $s, int $at, bool $isId): array
    {
        if ($at >= strlen($s)) {
            return [null, 0];
        }
        $first = ord($s[$at]);
        $length = 1;
        while ($length <= 8 && ! ($first & (0x80 >> ($length - 1)))) {
            $length++;
        }
        if ($length > ($isId ? 4 : 8) || $at + $length > strlen($s)) {
            return [null, 0];
        }
        $value = $isId ? $first : ($first & (0xFF >> $length));
        $allOnes = ! $isId && $value === (0xFF >> $length);
        for ($i = 1; $i < $length; $i++) {
            $byte = ord($s[$at + $i]);
            $value = ($value << 8) | $byte;
            $allOnes = $allOnes && $byte === 0xFF;
        }

        return [$allOnes ? null : $value, $length];
    }

    private static function uint(string $s): int
    {
        $value = 0;
        foreach (str_split($s) as $c) {
            $value = ($value << 8) | ord($c);
        }

        return $value;
    }

    private function bytes(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if ($offset + $length <= strlen($this->head)) {
            return (string) substr($this->head, $offset, $length);
        }

        return ($this->read)($offset, $length);
    }

    private function reject(string $code): BusinessRuleException
    {
        $messages = [
            'video_not_webm' => 'This file isn’t a video format we can check. Choose an MP4, MOV or WebM video.',
            'video_damaged' => 'This video’s file looks damaged and can’t be used. Try recording it again.',
            'video_header_too_large' => 'We couldn’t check this video. Try a shorter clip.',
            'video_no_picture' => 'This file has no picture. Choose a video.',
            'video_encrypted' => 'This video is protected (encrypted) and can’t be shared.',
            'video_codec' => 'This video uses a format we don’t recognise. Choose a video recorded with your phone’s camera.',
            'video_size' => 'This video’s picture size isn’t supported.',
        ];

        return new BusinessRuleException($code, $messages[$code], 422);
    }
}
