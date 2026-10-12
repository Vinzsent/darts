<?php
/**
 * Self-contained QR Code generator (QR Code Model 2, versions 1–40).
 *
 * Renders a QR code to a PNG image using the bundled GD library — no Composer
 * or external packages required. Supports numeric, alphanumeric and byte
 * (UTF-8) modes; the Purchase Order QR codes use byte mode with a JSON payload.
 *
 * The algorithm is a compact, public-domain implementation of the ISO/IEC 18004
 * specification (Reed–Solomon ECC, module masking, BCH format/version info).
 *
 * Public API:
 *   $qr = new QrCode($text, QrCode::ECC_MEDIUM);
 *   $png = $qr->toPng($scale, $margin);   // raw PNG bytes (string)
 *   $qr->getModule($x, $y);               // bool — for custom rendering/testing
 */
class QrCode
{
    // ---- Error correction levels (format-bit values per the spec) ----
    const ECC_LOW     = 0; // formatBits 1  (~7%)
    const ECC_MEDIUM  = 1; // formatBits 0  (~15%)
    const ECC_QUARTILE= 2; // formatBits 3  (~25%)
    const ECC_HIGH    = 3; // formatBits 2  (~30%)

    private static $MIN_VERSION = 1;
    private static $MAX_VERSION = 40;

    // Encoding modes (mode indicator bits).
    const MODE_NUMERIC = 1;
    const MODE_ALNUM   = 2;
    const MODE_BYTE    = 4;

    // Penalty weights for mask selection (N1..N4).
    private static $PENALTY_N1 = 3;
    private static $PENALTY_N2 = 3;
    private static $PENALTY_N3 = 40;
    private static $PENALTY_N4 = 10;

    // Number of error-correction codewords per block, indexed [ecl][version].
    private static $ECC_CODEWORDS_PER_BLOCK = [
        [-1,  7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    // Number of error-correction blocks, indexed [ecl][version].
    private static $NUM_ECC_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4,  4,  4,  4,  4,  6,  6,  6,  6,  7,  8,  8,  9,  9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5,  5,  8,  9,  9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8,  8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private $version;
    private $size;          // modules per side
    private $errorCorrectionLevel;
    private $mask;          // chosen mask pattern 0..7

    /** modules[y][x] = true(dark)/false(light); isFunction[y][x] reserved flag. */
    private $modules = [];
    private $isFunction = [];

    public function __construct($text, $ecl = self::ECC_MEDIUM)
    {
        $this->errorCorrectionLevel = $ecl;
        $bytes = self::toUtf8($text);

        // Pick the smallest byte-mode segment that fits.
        $mode = self::MODE_BYTE;
        $charCount = strlen($bytes);

        // Determine the minimal version whose data capacity holds the segment.
        $dataUsedBits = 0;
        for ($ver = self::$MIN_VERSION; ; $ver++) {
            $capacityBits = self::numDataCodewords($ver, $ecl) * 8;
            $headerBits = 4 + self::charCountBits($mode, $ver);
            if ($headerBits + $charCount * 8 <= $capacityBits) {
                $this->version = $ver;
                $dataUsedBits = $headerBits + $charCount * 8;
                break;
            }
            if ($ver >= self::$MAX_VERSION) {
                throw new InvalidArgumentException('Data too long for a QR code (max version ' . self::$MAX_VERSION . ').');
            }
        }

        $this->size = $this->version * 4 + 17;
        // Add ECC, interleave, draw everything.
        $bitBuffer = [];
        self::appendBits($bitBuffer, $mode, 4);
        self::appendBits($bitBuffer, $charCount, self::charCountBits($mode, $this->version));
        for ($i = 0; $i < $charCount; $i++) {
            self::appendBits($bitBuffer, ord($bytes[$i]), 8);
        }

        $this->drawFunctionPatterns();
        $allCodewords = $this->addEccAndInterleave($bitBuffer);
        $this->drawCodewords($allCodewords);

        // Choose the best mask.
        $minPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $this->applyMask($m);
            $this->drawFormatBits($m);
            $penalty = $this->getPenaltyScore();
            if ($penalty < $minPenalty) {
                $this->mask = $m;
                $minPenalty = $penalty;
            }
            $this->applyMask($m); // undo (XOR is its own inverse)
        }
        $this->applyMask($this->mask);
        $this->drawFormatBits($this->mask);
    }

    /* ===================== Public rendering ===================== */

    /** Returns true if the module at (x, y) is dark. */
    public function getModule($x, $y)
    {
        return isset($this->modules[$y][$x]) ? (bool)$this->modules[$y][$x] : false;
    }

    public function getSize()
    {
        return $this->size;
    }

    /**
     * Render to a PNG byte string using GD. $scale = pixels per module,
     * $margin = quiet-zone modules. Returns the raw PNG data.
     */
    public function toPng($scale = 8, $margin = 4)
    {
        $size = $this->size;
        $imgSize = ($size + $margin * 2) * $scale;

        $im = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, $imgSize - 1, $imgSize - 1, $white);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($this->getModule($x, $y)) {
                    imagefilledrectangle(
                        $im,
                        ($x + $margin) * $scale,
                        ($y + $margin) * $scale,
                        ($x + $margin + 1) * $scale - 1,
                        ($y + $margin + 1) * $scale - 1,
                        $black
                    );
                }
            }
        }

        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);
        return $data;
    }

    /* ===================== Static helpers ===================== */

    private static function toUtf8($text)
    {
        // Normalise to raw UTF-8 bytes for byte mode.
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding((string)$text, 'UTF-8', 'UTF-8');
        }
        return (string)$text;
    }

    /** Character-count indicator bit width for a given mode + version. */
    private static function charCountBits($mode, $ver)
    {
        if ($mode === self::MODE_NUMERIC) {
            return $ver <= 9 ? 10 : ($ver <= 26 ? 12 : 14);
        } elseif ($mode === self::MODE_ALNUM) {
            return $ver <= 9 ? 9 : ($ver <= 26 ? 11 : 13);
        } else { // byte
            return $ver <= 9 ? 8 : 16;
        }
    }

    /** Total number of raw data modules available (before ECC subtraction). */
    private static function numRawDataModules($ver)
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = (int)floor($ver / 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36; // two version-information blocks
            }
        }
        return $result;
    }

    /** Number of data codewords (payload, excluding ECC) for a version/level. */
    private static function numDataCodewords($ver, $ecl)
    {
        return (int)floor(self::numRawDataModules($ver) / 8)
            - self::$ECC_CODEWORDS_PER_BLOCK[$ecl][$ver] * self::$NUM_ECC_BLOCKS[$ecl][$ver];
    }

    /** Append $val (of $len bits) to the bit buffer array. */
    private static function appendBits(&$buffer, $val, $len)
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $buffer[] = ($val >> $i) & 1;
        }
    }

    /* ===================== Function patterns ===================== */

    /** Draw finder patterns, separators, timing, alignment, reserves format/version, dark module. */
    private function drawFunctionPatterns()
    {
        $size = $this->size;
        // Initialise matrices to light.
        for ($y = 0; $y < $size; $y++) {
            $this->modules[$y] = array_fill(0, $size, false);
            $this->isFunction[$y] = array_fill(0, $size, false);
        }

        // Timing patterns (row/column 6).
        for ($i = 0; $i < $size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }

        // Finder patterns (3 corners) with their separators.
        // Each finder is centred at (3,3), (size-4,3) and (3,size-4) so its
        // 7x7 box occupies the corner; the -4..+4 loop also paints separators.
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($size - 4, 3);
        $this->drawFinderPattern(3, $size - 4);

        // Alignment patterns.
        $alignPos = $this->getAlignmentPatternPositions();
        $n = count($alignPos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                // Skip the three finder corners.
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                $this->drawAlignmentPattern($alignPos[$i], $alignPos[$j]);
            }
        }

        // Reserve format information areas (drawn later with actual mask).
        $this->drawFormatBits(0); // placeholder; overwritten after mask selection
        $this->drawVersion();
    }

    private function drawFinderPattern($x, $y)
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max([abs($dx), abs($dy)]); // Chebyshev distance
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunctionModule($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignmentPattern($x, $y)
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max([abs($dx), abs($dy)]) !== 1);
            }
        }
    }

    /** Returns the 6 alignment-pattern center coordinates for this version. */
    private function getAlignmentPatternPositions()
    {
        $ver = $this->version;
        if ($ver === 1) {
            return [];
        }
        $numAlign = (int)floor($ver / 7) + 2;
        $step = ($ver === 32) ? 26 : (int)ceil(($ver * 4 + 4) / ($numAlign * 2 - 2)) * 2;
        $result = [6];
        for ($pos = $ver * 4 + 10; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function setFunctionModule($x, $y, $isDark)
    {
        $this->modules[$y][$x] = $isDark;
        $this->isFunction[$y][$x] = true;
    }

    /* ===================== ECC + interleave ===================== */

    /** Pad the data bits, split into blocks, add Reed–Solomon ECC, interleave. Returns codewords (ints). */
    private function addEccAndInterleave($data)
    {
        $ver = $this->version;
        $ecl = $this->errorCorrectionLevel;
        $numBlocks = self::$NUM_ECC_BLOCKS[$ecl][$ver];
        $blockEccLen = self::$ECC_CODEWORDS_PER_BLOCK[$ecl][$ver];
        $rawCodewords = (int)floor(self::numRawDataModules($ver) / 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = (int)floor($rawCodewords / $numBlocks);

        // Pack bits into bytes (MSB first).
        $dat = [];
        $count = count($data);
        for ($i = 0; $i < $count; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | (isset($data[$i + $j]) ? $data[$i + $j] : 0);
            }
            $dat[] = $byte & 0xFF;
        }

        // Build each block (short data blocks get a pad to align).
        $blocks = [];
        $rsDiv = self::reedSolomonComputeDivisor($blockEccLen);
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $datLen = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $datSlice = array_slice($dat, $k, $datLen);
            $k += $datLen;
            $ecc = self::reedSolomonComputeRemainder($datSlice, $rsDiv);
            if ($i < $numShortBlocks) {
                $datSlice[] = 0; // short data block
            }
            $blocks[] = array_merge($datSlice, $ecc);
        }

        // Interleave (skip the extra pad codeword in short blocks).
        $result = [];
        $maxLen = 0;
        foreach ($blocks as $b) { $maxLen = max($maxLen, count($b)); }
        for ($i = 0; $i < $maxLen; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if ($i !== $shortBlockLen - $blockEccLen || $b >= $numShortBlocks) {
                    if (isset($blocks[$b][$i])) {
                        $result[] = $blocks[$b][$i];
                    }
                }
            }
        }
        return $result;
    }

    /** Reed–Solomon divisor polynomial for the given degree. */
    private static function reedSolomonComputeDivisor($degree)
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::reedSolomonMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::reedSolomonMultiply($root, 0x02);
        }
        return $result;
    }

    /** Reed–Solomon remainder (ECC codewords) for a data block. */
    private static function reedSolomonComputeRemainder($data, $divisor)
    {
        $degree = count($divisor);
        $result = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ $result[0];
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::reedSolomonMultiply($divisor[$i], $factor);
            }
        }
        return $result;
    }

    /** GF(2^8) multiply with the QR primitive polynomial 0x11D. */
    private static function reedSolomonMultiply($x, $y)
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ ((($z >> 7) & 1) * 0x11D)) & 0xFF;
            $z ^= ((($y >> $i) & 1) * $x);
        }
        return $z & 0xFF;
    }

    /* ===================== Codeword placement ===================== */

    /** Draw the interleaved codewords into the data region (zig-zag). */
    private function drawCodewords($data)
    {
        $size = $this->size;
        $i = 0; // bit index
        $totalBits = count($data) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = ((($right + 1) & 2) === 0);
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $totalBits) {
                        $byte = $data[$i >> 3];
                        $this->modules[$y][$x] = (bool)((($byte >> (7 - ($i & 7))) & 1));
                        $i++;
                    }
                }
            }
        }
    }

    /* ===================== Masking ===================== */

    /** XOR the data modules with the given mask pattern (invertible). */
    private function applyMask($mask)
    {
        $size = $this->size;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                switch ($mask) {
                    case 0: $invert = (($x + $y) % 2 === 0); break;
                    case 1: $invert = ($y % 2 === 0); break;
                    case 2: $invert = ($x % 3 === 0); break;
                    case 3: $invert = ((($x + $y) % 3) === 0); break;
                    case 4: $invert = ((((int)floor($x / 3) + (int)floor($y / 2)) % 2) === 0); break;
                    case 5: $invert = ((($x * $y) % 2 + ($x * $y) % 3) === 0); break;
                    case 6: $invert = (((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0); break;
                    case 7: $invert = ((((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0); break;
                    default: $invert = false;
                }
                if (!$this->isFunction[$y][$x] && $invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /* ===================== Format + version bits ===================== */

    private function drawFormatBits($mask)
    {
        $size = $this->size;
        // EC level indicator bits: L=1, M=0, Q=3, H=2.
        $ecFormatBits = [1, 0, 3, 2];
        $data = ($ecFormatBits[$this->errorCorrectionLevel] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412; // 15 bits

        // First copy (around top-left finder).
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, 7, self::getBit($bits, 6));
        $this->setFunctionModule(8, 8, self::getBit($bits, 7));
        $this->setFunctionModule(7, 8, self::getBit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::getBit($bits, $i));
        }

        // Second copy (split bottom-left + top-right).
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($size - 1 - $i, 8, self::getBit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $size - 15 + $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, $size - 8, true); // always dark
    }

    private function drawVersion()
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem; // 18 bits

        for ($i = 0; $i < 18; $i++) {
            $bit = self::getBit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = (int)floor($i / 3);
            $this->setFunctionModule($a, $b, $bit);
            $this->setFunctionModule($b, $a, $bit);
        }
    }

    private static function getBit($x, $i)
    {
        return ((($x >> $i) & 1) !== 0);
    }

    /* ===================== Penalty scoring ===================== */

    private function getPenaltyScore()
    {
        $result = 0;
        $size = $this->size;

        // Rows: adjacent runs + finder-like patterns.
        for ($y = 0; $y < $size; $y++) {
            $runColor = false;
            $runX = 0;
            $runHistory = [0, 0, 0, 0, 0, 0, 0];
            for ($x = 0; $x < $size; $x++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runX++;
                    if ($runX === 5) {
                        $result += self::$PENALTY_N1;
                    } elseif ($runX > 5) {
                        $result++;
                    }
                } else {
                    $this->finderPenaltyAddHistory($runX, $runHistory);
                    if (!$runColor) {
                        $result += $this->finderPenaltyCountPatterns($runHistory) * self::$PENALTY_N3;
                    }
                    $runColor = $this->modules[$y][$x];
                    $runX = 1;
                }
            }
            $result += $this->finderPenaltyTerminateAndCount($runColor, $runX, $runHistory) * self::$PENALTY_N3;
        }
        // Columns.
        for ($x = 0; $x < $size; $x++) {
            $runColor = false;
            $runY = 0;
            $runHistory = [0, 0, 0, 0, 0, 0, 0];
            for ($y = 0; $y < $size; $y++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runY++;
                    if ($runY === 5) {
                        $result += self::$PENALTY_N1;
                    } elseif ($runY > 5) {
                        $result++;
                    }
                } else {
                    $this->finderPenaltyAddHistory($runY, $runHistory);
                    if (!$runColor) {
                        $result += $this->finderPenaltyCountPatterns($runHistory) * self::$PENALTY_N3;
                    }
                    $runColor = $this->modules[$y][$x];
                    $runY = 1;
                }
            }
            $result += $this->finderPenaltyTerminateAndCount($runColor, $runY, $runHistory) * self::$PENALTY_N3;
        }

        // 2x2 blocks of the same colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += self::$PENALTY_N2;
                }
            }
        }

        // Balance of dark/light modules.
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($this->modules[$y][$x]) {
                    $dark++;
                }
            }
        }
        $total = $size * $size;
        $k = (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        $result += max(0, $k) * self::$PENALTY_N4;

        return $result;
    }

    private function finderPenaltyAddHistory($runLength, &$history)
    {
        if ($history[0] === 0) {
            $runLength += $this->size; // add light border to initial run
        }
        array_pop($history);
        array_unshift($history, $runLength);
    }

    private function finderPenaltyCountPatterns($runHistory)
    {
        $n = $runHistory[1];
        $core = $n > 0 && $runHistory[2] === $n && $runHistory[3] === $n * 3 && $runHistory[4] === $n && $runHistory[5] === $n;
        return ($core && $runHistory[0] >= $n * 4 && $runHistory[6] >= $n ? 1 : 0)
             + ($core && $runHistory[6] >= $n * 4 && $runHistory[0] >= $n ? 1 : 0);
    }

    private function finderPenaltyTerminateAndCount($currentRunColor, $runLength, &$runHistory)
    {
        if ($currentRunColor) { // terminate dark run
            $this->finderPenaltyAddHistory($runLength, $runHistory);
            $runLength = 0;
        }
        $runLength += $this->size; // add light border to final run
        $this->finderPenaltyAddHistory($runLength, $runHistory);
        return $this->finderPenaltyCountPatterns($runHistory);
    }
}





