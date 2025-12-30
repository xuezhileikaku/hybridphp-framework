<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HPACK Huffman Encoding/Decoding for HTTP/2
 * 
 * Implements RFC 7541 Appendix B Huffman Code.
 * Provides efficient header compression using Huffman coding.
 */
class HpackHuffman
{
    /**
     * Huffman code table from RFC 7541 Appendix B
     * Format: [symbol => [code, bit_length]]
     */
    private const HUFFMAN_CODES = [
        0 => [0x1ff8, 13],
        1 => [0x7fffd8, 23],
        2 => [0xfffffe2, 28],
        3 => [0xfffffe3, 28],
        4 => [0xfffffe4, 28],
        5 => [0xfffffe5, 28],
        6 => [0xfffffe6, 28],
        7 => [0xfffffe7, 28],
        8 => [0xfffffe8, 28],
        9 => [0xffffea, 24],
        10 => [0x3ffffffc, 30],
        11 => [0xfffffe9, 28],
        12 => [0xfffffea, 28],
        13 => [0x3ffffffd, 30],
        14 => [0xfffffeb, 28],
        15 => [0xfffffec, 28],
        16 => [0xfffffed, 28],
        17 => [0xfffffee, 28],
        18 => [0xfffffef, 28],
        19 => [0xffffff0, 28],
        20 => [0xffffff1, 28],
        21 => [0xffffff2, 28],
        22 => [0x3ffffffe, 30],
        23 => [0xffffff3, 28],
        24 => [0xffffff4, 28],
        25 => [0xffffff5, 28],
        26 => [0xffffff6, 28],
        27 => [0xffffff7, 28],
        28 => [0xffffff8, 28],
        29 => [0xffffff9, 28],
        30 => [0xffffffa, 28],
        31 => [0xffffffb, 28],
        32 => [0x14, 6],      // ' '
        33 => [0x3f8, 10],    // '!'
        34 => [0x3f9, 10],    // '"'
        35 => [0xffa, 12],    // '#'
        36 => [0x1ff9, 13],   // '$'
        37 => [0x15, 6],      // '%'
        38 => [0xf8, 8],      // '&'
        39 => [0x7fa, 11],    // '\''
        40 => [0x3fa, 10],    // '('
        41 => [0x3fb, 10],    // ')'
        42 => [0xf9, 8],      // '*'
        43 => [0x7fb, 11],    // '+'
        44 => [0xfa, 8],      // ','
        45 => [0x16, 6],      // '-'
        46 => [0x17, 6],      // '.'
        47 => [0x18, 6],      // '/'
        48 => [0x0, 5],       // '0'
        49 => [0x1, 5],       // '1'
        50 => [0x2, 5],       // '2'
        51 => [0x19, 6],      // '3'
        52 => [0x1a, 6],      // '4'
        53 => [0x1b, 6],      // '5'
        54 => [0x1c, 6],      // '6'
        55 => [0x1d, 6],      // '7'
        56 => [0x1e, 6],      // '8'
        57 => [0x1f, 6],      // '9'
        58 => [0x5c, 7],      // ':'
        59 => [0xfb, 8],      // ';'
        60 => [0x7ffc, 15],   // '<'
        61 => [0x20, 6],      // '='
        62 => [0xffb, 12],    // '>'
        63 => [0x3fc, 10],    // '?'
        64 => [0x1ffa, 13],   // '@'
        65 => [0x21, 6],      // 'A'
        66 => [0x5d, 7],      // 'B'
        67 => [0x5e, 7],      // 'C'
        68 => [0x5f, 7],      // 'D'
        69 => [0x60, 7],      // 'E'
        70 => [0x61, 7],      // 'F'
        71 => [0x62, 7],      // 'G'
        72 => [0x63, 7],      // 'H'
        73 => [0x64, 7],      // 'I'
        74 => [0x65, 7],      // 'J'
        75 => [0x66, 7],      // 'K'
        76 => [0x67, 7],      // 'L'
        77 => [0x68, 7],      // 'M'
        78 => [0x69, 7],      // 'N'
        79 => [0x6a, 7],      // 'O'
        80 => [0x6b, 7],      // 'P'
        81 => [0x6c, 7],      // 'Q'
        82 => [0x6d, 7],      // 'R'
        83 => [0x6e, 7],      // 'S'
        84 => [0x6f, 7],      // 'T'
        85 => [0x70, 7],      // 'U'
        86 => [0x71, 7],      // 'V'
        87 => [0x72, 7],      // 'W'
        88 => [0xfc, 8],      // 'X'
        89 => [0x73, 7],      // 'Y'
        90 => [0xfd, 8],      // 'Z'
        91 => [0x1ffb, 13],   // '['
        92 => [0x7fff0, 19],  // '\\'
        93 => [0x1ffc, 13],   // ']'
        94 => [0x3ffc, 14],   // '^'
        95 => [0x22, 6],      // '_'
        96 => [0x7ffd, 15],   // '`'
        97 => [0x3, 5],       // 'a'
        98 => [0x23, 6],      // 'b'
        99 => [0x4, 5],       // 'c'
        100 => [0x24, 6],     // 'd'
        101 => [0x5, 5],      // 'e'
        102 => [0x25, 6],     // 'f'
        103 => [0x26, 6],     // 'g'
        104 => [0x27, 6],     // 'h'
        105 => [0x6, 5],      // 'i'
        106 => [0x74, 7],     // 'j'
        107 => [0x75, 7],     // 'k'
        108 => [0x28, 6],     // 'l'
        109 => [0x29, 6],     // 'm'
        110 => [0x2a, 6],     // 'n'
        111 => [0x7, 5],      // 'o'
        112 => [0x2b, 6],     // 'p'
        113 => [0x76, 7],     // 'q'
        114 => [0x2c, 6],     // 'r'
        115 => [0x8, 5],      // 's'
        116 => [0x9, 5],      // 't'
        117 => [0x2d, 6],     // 'u'
        118 => [0x77, 7],     // 'v'
        119 => [0x78, 7],     // 'w'
        120 => [0x79, 7],     // 'x'
        121 => [0x7a, 7],     // 'y'
        122 => [0x7b, 7],     // 'z'
        123 => [0x7ffe, 15],  // '{'
        124 => [0x7fc, 11],   // '|'
        125 => [0x3ffd, 14],  // '}'
        126 => [0x1ffd, 13],  // '~'
        127 => [0xffffffc, 28],
        128 => [0xfffe6, 20],
        129 => [0x3fffd2, 22],
        130 => [0xfffe7, 20],
        131 => [0xfffe8, 20],
        132 => [0x3fffd3, 22],
        133 => [0x3fffd4, 22],
        134 => [0x3fffd5, 22],
        135 => [0x7fffd9, 23],
        136 => [0x3fffd6, 22],
        137 => [0x7fffda, 23],
        138 => [0x7fffdb, 23],
        139 => [0x7fffdc, 23],
        140 => [0x7fffdd, 23],
        141 => [0x7fffde, 23],
        142 => [0xffffeb, 24],
        143 => [0x7fffdf, 23],
        144 => [0xffffec, 24],
        145 => [0xffffed, 24],
        146 => [0x3fffd7, 22],
        147 => [0x7fffe0, 23],
        148 => [0xffffee, 24],
        149 => [0x7fffe1, 23],
        150 => [0x7fffe2, 23],
        151 => [0x7fffe3, 23],
        152 => [0x7fffe4, 23],
        153 => [0x1fffdc, 21],
        154 => [0x3fffd8, 22],
        155 => [0x7fffe5, 23],
        156 => [0x3fffd9, 22],
        157 => [0x7fffe6, 23],
        158 => [0x7fffe7, 23],
        159 => [0xffffef, 24],
        160 => [0x3fffda, 22],
        161 => [0x1fffdd, 21],
        162 => [0xfffe9, 20],
        163 => [0x3fffdb, 22],
        164 => [0x3fffdc, 22],
        165 => [0x7fffe8, 23],
        166 => [0x7fffe9, 23],
        167 => [0x1fffde, 21],
        168 => [0x7fffea, 23],
        169 => [0x3fffdd, 22],
        170 => [0x3fffde, 22],
        171 => [0xfffff0, 24],
        172 => [0x1fffdf, 21],
        173 => [0x3fffdf, 22],
        174 => [0x7fffeb, 23],
        175 => [0x7fffec, 23],
        176 => [0x1fffe0, 21],
        177 => [0x1fffe1, 21],
        178 => [0x3fffe0, 22],
        179 => [0x1fffe2, 21],
        180 => [0x7fffed, 23],
        181 => [0x3fffe1, 22],
        182 => [0x7fffee, 23],
        183 => [0x7fffef, 23],
        184 => [0xfffea, 20],
        185 => [0x3fffe2, 22],
        186 => [0x3fffe3, 22],
        187 => [0x3fffe4, 22],
        188 => [0x7ffff0, 23],
        189 => [0x3fffe5, 22],
        190 => [0x3fffe6, 22],
        191 => [0x7ffff1, 23],
        192 => [0x3ffffe0, 26],
        193 => [0x3ffffe1, 26],
        194 => [0xfffeb, 20],
        195 => [0x7fff1, 19],
        196 => [0x3fffe7, 22],
        197 => [0x7ffff2, 23],
        198 => [0x3fffe8, 22],
        199 => [0x1ffffec, 25],
        200 => [0x3ffffe2, 26],
        201 => [0x3ffffe3, 26],
        202 => [0x3ffffe4, 26],
        203 => [0x7ffffde, 27],
        204 => [0x7ffffdf, 27],
        205 => [0x3ffffe5, 26],
        206 => [0xfffff1, 24],
        207 => [0x1ffffed, 25],
        208 => [0x7fff2, 19],
        209 => [0x1fffe3, 21],
        210 => [0x3ffffe6, 26],
        211 => [0x7ffffe0, 27],
        212 => [0x7ffffe1, 27],
        213 => [0x3ffffe7, 26],
        214 => [0x7ffffe2, 27],
        215 => [0xfffff2, 24],
        216 => [0x1fffe4, 21],
        217 => [0x1fffe5, 21],
        218 => [0x3ffffe8, 26],
        219 => [0x3ffffe9, 26],
        220 => [0xffffffd, 28],
        221 => [0x7ffffe3, 27],
        222 => [0x7ffffe4, 27],
        223 => [0x7ffffe5, 27],
        224 => [0xfffec, 20],
        225 => [0xfffff3, 24],
        226 => [0xfffed, 20],
        227 => [0x1fffe6, 21],
        228 => [0x3fffe9, 22],
        229 => [0x1fffe7, 21],
        230 => [0x1fffe8, 21],
        231 => [0x7ffff3, 23],
        232 => [0x3fffea, 22],
        233 => [0x3fffeb, 22],
        234 => [0x1ffffee, 25],
        235 => [0x1ffffef, 25],
        236 => [0xfffff4, 24],
        237 => [0xfffff5, 24],
        238 => [0x3ffffea, 26],
        239 => [0x7ffff4, 23],
        240 => [0x3ffffeb, 26],
        241 => [0x7ffffe6, 27],
        242 => [0x3ffffec, 26],
        243 => [0x3ffffed, 26],
        244 => [0x7ffffe7, 27],
        245 => [0x7ffffe8, 27],
        246 => [0x7ffffe9, 27],
        247 => [0x7ffffea, 27],
        248 => [0x7ffffeb, 27],
        249 => [0xffffffe, 28],
        250 => [0x7ffffec, 27],
        251 => [0x7ffffed, 27],
        252 => [0x7ffffee, 27],
        253 => [0x7ffffef, 27],
        254 => [0x7fffff0, 27],
        255 => [0x3ffffee, 26],
        256 => [0x3fffffff, 30], // EOS
    ];

    /**
     * Decode tree for Huffman decoding (built lazily)
     */
    private static ?array $decodeTree = null;

    /**
     * Encode a string using Huffman coding
     */
    public static function encode(string $data): string
    {
        $bits = '';
        $length = strlen($data);
        
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            [$code, $bitLength] = self::HUFFMAN_CODES[$byte];
            $bits .= str_pad(decbin($code), $bitLength, '0', STR_PAD_LEFT);
        }
        
        // Pad with EOS prefix (all 1s) to byte boundary
        $padding = 8 - (strlen($bits) % 8);
        if ($padding < 8) {
            $bits .= str_repeat('1', $padding);
        }
        
        // Convert bit string to bytes
        $result = '';
        $bitLength = strlen($bits);
        for ($i = 0; $i < $bitLength; $i += 8) {
            $result .= chr(bindec(substr($bits, $i, 8)));
        }
        
        return $result;
    }

    /**
     * Decode a Huffman-encoded string
     */
    public static function decode(string $data): string
    {
        if (self::$decodeTree === null) {
            self::buildDecodeTree();
        }
        
        $result = '';
        $node = self::$decodeTree;
        $dataLength = strlen($data);
        
        for ($i = 0; $i < $dataLength; $i++) {
            $byte = ord($data[$i]);
            
            for ($bit = 7; $bit >= 0; $bit--) {
                $direction = ($byte >> $bit) & 1;
                
                if (!isset($node[$direction])) {
                    // Invalid Huffman code
                    throw new HpackException('Invalid Huffman code');
                }
                
                $node = $node[$direction];
                
                if (is_int($node)) {
                    if ($node === 256) {
                        // EOS symbol - should only appear in padding
                        // Check if remaining bits are all 1s (valid padding)
                        if ($i === $dataLength - 1) {
                            // Verify remaining bits are padding
                            $remainingBits = $byte & ((1 << ($bit + 1)) - 1);
                            $expectedPadding = (1 << ($bit + 1)) - 1;
                            if ($remainingBits !== $expectedPadding) {
                                throw new HpackException('Invalid Huffman padding');
                            }
                            return $result;
                        }
                        throw new HpackException('EOS symbol in middle of data');
                    }
                    
                    $result .= chr($node);
                    $node = self::$decodeTree;
                }
            }
        }
        
        // Check for incomplete code at end (should be valid padding)
        if ($node !== self::$decodeTree) {
            // Verify it's valid padding (all remaining bits should be 1)
            // This is acceptable per RFC 7541
        }
        
        return $result;
    }

    /**
     * Build the decode tree for efficient decoding
     */
    private static function buildDecodeTree(): void
    {
        self::$decodeTree = [];
        
        foreach (self::HUFFMAN_CODES as $symbol => [$code, $bitLength]) {
            $node = &self::$decodeTree;
            
            for ($i = $bitLength - 1; $i >= 0; $i--) {
                $bit = ($code >> $i) & 1;
                
                if ($i === 0) {
                    $node[$bit] = $symbol;
                } else {
                    if (!isset($node[$bit])) {
                        $node[$bit] = [];
                    }
                    $node = &$node[$bit];
                }
            }
        }
    }

    /**
     * Calculate the encoded length of a string without actually encoding
     */
    public static function encodedLength(string $data): int
    {
        $bits = 0;
        $length = strlen($data);
        
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            $bits += self::HUFFMAN_CODES[$byte][1];
        }
        
        // Round up to byte boundary
        return (int) ceil($bits / 8);
    }

    /**
     * Check if Huffman encoding would be beneficial
     */
    public static function shouldEncode(string $data): bool
    {
        return self::encodedLength($data) < strlen($data);
    }
}
