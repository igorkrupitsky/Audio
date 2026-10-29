<?php
/**
 * AudioChapters.php - audio file types the player accepts, and chapter markers read
 * on the server.
 *
 *  - MP4 family (.m4b .m4a .mp4): the QuickTime chapter track (what Apple Books and
 *    most tools write) or, failing that, the Nero 'chpl' box.
 *  - Ogg (.opus .ogg): CHAPTER001=00:00:00.000 / CHAPTER001NAME=... comments.
 *
 * Only the headers are read, by seeking straight to them, so this is fast even for a
 * 1 GB book and works whether the 'moov' header is at the front or the end of the file.
 * Results are cached in the system temp folder, keyed by path, size and modified time.
 */

/** Extensions the player lists and plays (lowercase). Keep in sync with AUDIO_TYPES in Player.js. */
const AUDIO_EXTENSIONS = ['mp3', 'm4b', 'm4a', 'aac', 'opus', 'ogg'];

const CHAPTER_CACHE_VERSION = 'v1';
const MOOV_MAX_BYTES = 200 * 1024 * 1024;   // refuse absurd headers
const OGG_HEADER_MAX_BYTES = 64 * 1024 * 1024; // comment headers can hold cover art

function is_audio_filename(string $name): bool {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), AUDIO_EXTENSIONS, true);
}

/**
 * Chapters of one audio file: [['title' => string, 'start' => float, 'end' => float], ...]
 * in seconds. Returns [] when the file has fewer than two chapters or can't be read.
 */
function read_audio_chapters(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['m4b', 'm4a', 'mp4', 'opus', 'ogg'], true)) {
        return [];
    }
    $st = @stat($path);
    if ($st === false) {
        return [];
    }

    $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'audiobook-chapters';
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR
        . md5(CHAPTER_CACHE_VERSION . '|' . $path . '|' . $st['size'] . '|' . $st['mtime']) . '.json';

    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
        $data = json_decode($cached, true);
        if (is_array($data)) {
            return $data;
        }
    }

    try {
        $chapters = ($ext === 'opus' || $ext === 'ogg') ? ogg_read_chapters($path) : mp4_read_chapters($path);
    } catch (Throwable $ex) {
        error_log('[AudioChapters] ' . $path . ': ' . $ex->getMessage());
        $chapters = [];
    }

    // Caching is only an optimisation; a read-only temp folder just means re-reading.
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    @file_put_contents($cacheFile, json_encode($chapters), LOCK_EX);

    return $chapters;
}

/** Sort, drop markers past the end, fill in end times, keep only real multi-chapter files. */
function finish_chapters(array $marks, float $duration): array {
    usort($marks, function ($a, $b) { return $a['start'] <=> $b['start']; });

    $out = [];
    foreach ($marks as $m) {
        $start = max(0.0, (float)$m['start']);
        if ($duration > 0 && $start >= $duration - 0.5) {
            continue;
        }
        // Two markers at the same moment: keep the first.
        if ($out && abs($start - $out[count($out) - 1]['start']) < 0.001) {
            continue;
        }
        $out[] = ['title' => clean_chapter_title((string)$m['title']), 'start' => round($start, 3)];
    }

    $n = count($out);
    if ($n < 2) {
        return [];
    }
    for ($i = 0; $i < $n; $i++) {
        $end = $i + 1 < $n ? $out[$i + 1]['start'] : $duration;
        $out[$i]['end'] = round(max($out[$i]['start'], (float)$end), 3);
    }
    return $out;
}

function clean_chapter_title(string $s): string {
    if (strncmp($s, "\xFE\xFF", 2) === 0) {
        $s = mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
    } elseif (strncmp($s, "\xFF\xFE", 2) === 0) {
        $s = mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE');
    } elseif (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    $s = str_replace("\0", '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

function read_exact($fh, int $length): string {
    $buf = '';
    while ($length > 0 && !feof($fh)) {
        $chunk = fread($fh, min($length, 1 << 20));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
        $length -= strlen($chunk);
    }
    return $buf;
}

// ---------------------------------------------------------------------------
// MP4 / M4B / M4A
// ---------------------------------------------------------------------------

function be32(string $b, int $p): int { return unpack('N', $b, $p)[1]; }
function be16(string $b, int $p): int { return unpack('n', $b, $p)[1]; }
function be64(string $b, int $p): int { return unpack('J', $b, $p)[1]; }

/** Child boxes of the byte range [start, end) of $buf: list of [type, payloadStart, end]. */
function mp4_children(string $buf, int $start, int $end): array {
    $out = [];
    $p = $start;
    while ($p + 8 <= $end) {
        $size = be32($buf, $p);
        $type = substr($buf, $p + 4, 4);
        $hdr = 8;
        if ($size === 1) {
            if ($p + 16 > $end) break;
            $size = be64($buf, $p + 8);
            $hdr = 16;
        } elseif ($size === 0) {
            $size = $end - $p;
        }
        if ($size < $hdr || $p + $size > $end) break;
        $out[] = [$type, $p + $hdr, $p + $size];
        $p += $size;
    }
    return $out;
}

/** Follow a path of box types, e.g. mp4_box($buf, $trak, 'mdia', 'minf', 'stbl'). */
function mp4_box(string $buf, array $box, string ...$types): ?array {
    foreach ($types as $type) {
        $found = null;
        foreach (mp4_children($buf, $box[1], $box[2]) as $child) {
            if ($child[0] === $type) { $found = $child; break; }
        }
        if ($found === null) return null;
        $box = $found;
    }
    return $box;
}

function mp4_read_chapters(string $path): array {
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    try {
        $fileSize = filesize($path);
        $moov = null;
        $pos = 0;
        while ($pos + 8 <= $fileSize) {
            fseek($fh, $pos);
            $h = read_exact($fh, 16);
            if (strlen($h) < 8) break;
            $size = be32($h, 0);
            $type = substr($h, 4, 4);
            $hdr = 8;
            if ($size === 1) {
                if (strlen($h) < 16) break;
                $size = be64($h, 8);
                $hdr = 16;
            } elseif ($size === 0) {
                $size = $fileSize - $pos;
            }
            if ($size < $hdr || !preg_match('/^[\x20-\x7E]{4}$/', $type)) break; // not an MP4 file
            if ($type === 'moov') {
                if ($size > MOOV_MAX_BYTES) return [];
                fseek($fh, $pos);
                $moov = read_exact($fh, $size);
                if (strlen($moov) !== $size) return [];
                break;
            }
            $pos += $size;
        }
        if ($moov === null) return [];

        $root = ['moov', $hdr, strlen($moov)];
        $duration = 0.0;
        $mvhd = mp4_box($moov, $root, 'mvhd');
        if ($mvhd) {
            $v = ord($moov[$mvhd[1]]);
            $timescale = $v === 1 ? be32($moov, $mvhd[1] + 20) : be32($moov, $mvhd[1] + 12);
            $dur = $v === 1 ? be64($moov, $mvhd[1] + 24) : be32($moov, $mvhd[1] + 16);
            if ($timescale > 0) $duration = $dur / $timescale;
        }

        $marks = mp4_chapter_track($moov, $root, $fh);
        if (count($marks) < 2) {
            $chpl = mp4_box($moov, $root, 'udta', 'chpl');
            if ($chpl) $marks = mp4_parse_chpl($moov, $chpl);
        }
        return finish_chapters($marks, $duration);
    } finally {
        fclose($fh);
    }
}

/** Nero chapters: [ver/flags][v1: reserved u8 + count u32 | v0: count u8] then [start u64 (100 ns)][len u8][title]. */
function mp4_parse_chpl(string $b, array $box): array {
    $p = $box[1];
    $end = $box[2];
    if ($p + 5 > $end) return [];
    $version = ord($b[$p]);
    $p += 4;
    if ($version === 1) {
        // ffmpeg writes 4 zero bytes + u8 count; mp4v2 writes u8 reserved + u32 count.
        // Skipping one byte and reading a u32 gives the right count for both.
        if ($p + 5 > $end) return [];
        $count = be32($b, $p + 1);
        $p += 5;
    } else {
        $count = ord($b[$p]);
        $p += 1;
    }
    $out = [];
    for ($i = 0; $i < $count && $i < 10000 && $p + 9 <= $end; $i++) {
        $start = be64($b, $p) / 1e7;
        $len = ord($b[$p + 8]);
        $p += 9;
        if ($p + $len > $end) break;
        $out[] = ['title' => substr($b, $p, $len), 'start' => $start];
        $p += $len;
    }
    return $out;
}

/** QuickTime chapters: the audio track's tref 'chap' points at a text track whose samples are the titles. */
function mp4_chapter_track(string $b, array $root, $fh): array {
    $traks = [];
    $chapIds = [];
    foreach (mp4_children($b, $root[1], $root[2]) as $box) {
        if ($box[0] !== 'trak') continue;
        $tkhd = mp4_box($b, $box, 'tkhd');
        $hdlr = mp4_box($b, $box, 'mdia', 'hdlr');
        if (!$tkhd || !$hdlr) continue;
        $id = ord($b[$tkhd[1]]) === 1 ? be32($b, $tkhd[1] + 20) : be32($b, $tkhd[1] + 12);
        $handler = substr($b, $hdlr[1] + 8, 4);
        $traks[$id] = $box;
        if ($handler === 'soun') {
            $chap = mp4_box($b, $box, 'tref', 'chap');
            if ($chap) {
                for ($p = $chap[1]; $p + 4 <= $chap[2]; $p += 4) $chapIds[] = be32($b, $p);
            }
        }
    }

    foreach ($chapIds as $id) {
        if (!isset($traks[$id])) continue;
        $marks = mp4_text_samples($b, $traks[$id], $fh);
        if (count($marks) >= 2) return $marks;
    }
    return [];
}

function mp4_text_samples(string $b, array $trak, $fh): array {
    $mdhd = mp4_box($b, $trak, 'mdia', 'mdhd');
    $stbl = mp4_box($b, $trak, 'mdia', 'minf', 'stbl');
    if (!$mdhd || !$stbl) return [];
    $timescale = ord($b[$mdhd[1]]) === 1 ? be32($b, $mdhd[1] + 20) : be32($b, $mdhd[1] + 12);
    if ($timescale <= 0) return [];

    $stts = mp4_box($b, $stbl, 'stts');
    $stsz = mp4_box($b, $stbl, 'stsz');
    $stsc = mp4_box($b, $stbl, 'stsc');
    $stco = mp4_box($b, $stbl, 'stco');
    $co64 = $stco ? null : mp4_box($b, $stbl, 'co64');
    if (!$stts || !$stsz || !$stsc || (!$stco && !$co64)) return [];

    // Sample start times
    $times = [];
    $t = 0;
    $n = be32($b, $stts[1] + 4);
    for ($i = 0, $p = $stts[1] + 8; $i < $n && $p + 8 <= $stts[2] && count($times) < 10000; $i++, $p += 8) {
        $cnt = be32($b, $p);
        $delta = be32($b, $p + 4);
        for ($k = 0; $k < $cnt && count($times) < 10000; $k++) {
            $times[] = $t;
            $t += $delta;
        }
    }

    // Sample sizes
    $fixed = be32($b, $stsz[1] + 4);
    $sampleCount = min(be32($b, $stsz[1] + 8), count($times));
    $sizes = [];
    for ($i = 0; $i < $sampleCount; $i++) {
        $p = $stsz[1] + 12 + $i * 4;
        $sizes[] = $fixed ?: ($p + 4 <= $stsz[2] ? be32($b, $p) : 0);
    }

    // Chunk offsets
    $chunks = [];
    if ($stco) {
        $c = be32($b, $stco[1] + 4);
        for ($i = 0, $p = $stco[1] + 8; $i < $c && $p + 4 <= $stco[2]; $i++, $p += 4) $chunks[] = be32($b, $p);
    } else {
        $c = be32($b, $co64[1] + 4);
        for ($i = 0, $p = $co64[1] + 8; $i < $c && $p + 8 <= $co64[2]; $i++, $p += 8) $chunks[] = be64($b, $p);
    }

    // Sample-to-chunk map -> each sample's file offset
    $map = [];
    $e = be32($b, $stsc[1] + 4);
    for ($i = 0, $p = $stsc[1] + 8; $i < $e && $p + 12 <= $stsc[2]; $i++, $p += 12) {
        $map[] = [be32($b, $p), be32($b, $p + 4)];
    }
    $offsets = [];
    $s = 0;
    for ($i = 0; $i < count($map) && $s < $sampleCount; $i++) {
        $first = $map[$i][0];
        $last = isset($map[$i + 1]) ? $map[$i + 1][0] - 1 : count($chunks);
        for ($c = $first; $c <= $last && $s < $sampleCount; $c++) {
            if (!isset($chunks[$c - 1])) break 2;
            $off = $chunks[$c - 1];
            for ($k = 0; $k < $map[$i][1] && $s < $sampleCount; $k++) {
                $offsets[$s] = $off;
                $off += $sizes[$s];
                $s++;
            }
        }
    }

    // Each sample: u16 length + text (optionally followed by style boxes)
    $out = [];
    for ($i = 0; $i < $sampleCount; $i++) {
        $title = '';
        if (isset($offsets[$i]) && $sizes[$i] >= 2) {
            fseek($fh, $offsets[$i]);
            $data = read_exact($fh, min($sizes[$i], 4096));
            if (strlen($data) >= 2) {
                $title = substr($data, 2, min(be16($data, 0), strlen($data) - 2));
            }
        }
        $out[] = ['title' => $title, 'start' => $times[$i] / $timescale];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Ogg (Opus / Vorbis)
// ---------------------------------------------------------------------------

/** Reads the first two packets (identification + comments) of the first logical stream. */
function ogg_read_header_packets($fh): array {
    $packets = [];
    $current = '';
    $serial = null;
    $read = 0;
    while (count($packets) < 2 && $read < OGG_HEADER_MAX_BYTES) {
        $h = read_exact($fh, 27);
        if (strlen($h) < 27 || substr($h, 0, 4) !== 'OggS') break;
        $pageSerial = unpack('V', $h, 14)[1];
        $nseg = ord($h[26]);
        $table = read_exact($fh, $nseg);
        if (strlen($table) < $nseg) break;
        $bodyLen = array_sum(array_map('ord', str_split($table)));
        $body = $bodyLen ? read_exact($fh, $bodyLen) : '';
        $read += 27 + $nseg + $bodyLen;
        if ($serial === null) $serial = $pageSerial;
        if ($pageSerial !== $serial) continue;

        $p = 0;
        for ($i = 0; $i < $nseg; $i++) {
            $len = ord($table[$i]);
            $current .= substr($body, $p, $len);
            $p += $len;
            if ($len < 255) {
                $packets[] = $current;
                $current = '';
                if (count($packets) === 2) break;
            }
        }
    }
    return [$packets, $serial];
}

function ogg_parse_time(string $s): ?float {
    if (!preg_match('/^\s*(?:(\d+):)?(\d{1,2}):(\d{1,2}(?:[.,]\d+)?)\s*$/', $s, $m)) return null;
    return (int)$m[1] * 3600 + (int)$m[2] * 60 + (float)str_replace(',', '.', $m[3]);
}

function ogg_read_chapters(string $path): array {
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    try {
        [$packets, $serial] = ogg_read_header_packets($fh);
        if (count($packets) < 2) return [];
        [$id, $comments] = $packets;

        if (strncmp($id, 'OpusHead', 8) === 0) {
            $rate = 48000;
            $preSkip = strlen($id) >= 12 ? unpack('v', $id, 10)[1] : 0;
            $tagPrefix = 'OpusTags';
        } elseif (strncmp($id, "\x01vorbis", 7) === 0) {
            $rate = strlen($id) >= 16 ? unpack('V', $id, 12)[1] : 0;
            $preSkip = 0;
            $tagPrefix = "\x03vorbis";
        } else {
            return [];
        }
        if (strncmp($comments, $tagPrefix, strlen($tagPrefix)) !== 0) return [];

        // Vorbis comment block: vendor, then "KEY=value" entries (lengths are u32 LE)
        $p = strlen($tagPrefix);
        $len = strlen($comments);
        if ($p + 4 > $len) return [];
        $p += 4 + unpack('V', $comments, $p)[1];
        if ($p + 4 > $len) return [];
        $count = unpack('V', $comments, $p)[1];
        $p += 4;
        $starts = [];
        $names = [];
        for ($i = 0; $i < $count && $p + 4 <= $len; $i++) {
            $l = unpack('V', $comments, $p)[1];
            $p += 4;
            $entry = substr($comments, $p, $l);
            $p += $l;
            if (preg_match('/^CHAPTER(\d+)(NAME)?=(.*)$/is', $entry, $m)) {
                $n = (int)$m[1];
                if ($m[2] !== '') {
                    $names[$n] = $m[3];
                } elseif (($t = ogg_parse_time($m[3])) !== null) {
                    $starts[$n] = $t;
                }
            }
        }
        if (count($starts) < 2) return [];

        $marks = [];
        foreach ($starts as $n => $t) {
            $marks[] = ['title' => $names[$n] ?? '', 'start' => $t];
        }
        return finish_chapters($marks, $rate > 0 ? ogg_duration($fh, $serial, $rate, $preSkip) : 0.0);
    } finally {
        fclose($fh);
    }
}

/** Duration from the granule position of the stream's last page. */
function ogg_duration($fh, int $serial, int $rate, int $preSkip): float {
    $size = fstat($fh)['size'];
    $tail = min($size, 256 * 1024);
    fseek($fh, $size - $tail);
    $buf = read_exact($fh, $tail);
    $p = strlen($buf);
    while (($p = strrpos(substr($buf, 0, $p), 'OggS')) !== false) {
        if ($p + 27 <= strlen($buf) && unpack('V', $buf, $p + 14)[1] === $serial) {
            $granule = unpack('P', $buf, $p + 6)[1];
            if ($granule > 0) return max(0, $granule - $preSkip) / $rate;
        }
        if ($p === 0) break;
    }
    return 0.0;
}
