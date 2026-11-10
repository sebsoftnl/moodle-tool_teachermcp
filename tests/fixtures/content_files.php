<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Local binary fixtures shared by PHPUnit and the owned CLI harness.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_files {
    /** Encoded one-pixel WebP image fixture. */
    private const WEBP = 'UklGRjAAAABXRUJQVlA4TCMAAAAvH8ADAA9wDatnPCsEEADxd2hU5z+ig0Ag2V86gAUi+h/FCgA=';

    /**
     * Generate a small valid fixture image.
     *
     * @param string $extension Image extension.
     * @return string Encoded image bytes.
     */
    public static function image(string $extension = 'png'): string {
        if ($extension === 'webp') {
            return base64_decode(self::WEBP, true);
        }
        $encoder = match ($extension) {
            'png' => 'imagepng',
            'jpg', 'jpeg' => 'imagejpeg',
            'gif' => 'imagegif',
            default => throw new \InvalidArgumentException('Unknown fixture image format'),
        };
        $image = imagecreatetruecolor(32, 16);
        imagefilledrectangle($image, 0, 0, 31, 15, imagecolorallocate($image, 25, 86, 156));
        imagefilledrectangle($image, 16, 0, 31, 15, imagecolorallocate($image, 232, 112, 59));
        ob_start();
        try {
            if (!$encoder($image)) {
                throw new \RuntimeException('Could not encode fixture image');
            }
            return ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    /**
     * Pad a generated PNG to the requested byte length.
     *
     * @param int $bytes Target byte count.
     * @return string Encoded PNG bytes.
     */
    public static function png_at_size(int $bytes): string {
        $png = self::image();
        $length = $bytes - strlen($png) - 12;
        if ($length < 5) {
            throw new \InvalidArgumentException('PNG fixture size leaves no room for a text chunk');
        }
        $data = "note\0" . str_repeat('x', $length - 5);
        $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        return substr($png, 0, -12) . $chunk . substr($png, -12);
    }

    /**
     * Build or inspect a fixture ZIP package.
     *
     * @param array $entries ZIP entry names mapped to their contents.
     * @return string ZIP package bytes.
     */
    public static function package(array $entries): string {
        $path = tempnam(make_request_directory(), 'tmcp-content-');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create fixture package');
            }
            try {
                foreach ($entries as $name => $content) {
                    if (!$zip->addFromString($name, $content)) {
                        throw new \RuntimeException('Could not add fixture package entry');
                    }
                    if ($name === 'mimetype') {
                        $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                    }
                }
            } finally {
                $zip->close();
            }
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Load or construct a valid office document fixture.
     *
     * @param string $extension Office document extension.
     * @return string Encoded document bytes.
     */
    public static function office(string $extension): string {
        global $CFG;
        return match ($extension) {
            'docx' => file_get_contents($CFG->dirroot . '/files/converter/googledrive/tests/fixtures/source.docx'),
            'pptx' => file_get_contents($CFG->dirroot . '/mod/bigbluebuttonbn/tests/fixtures/bbpresentation.pptx'),
            'odt' => self::package([
                'mimetype' => 'application/vnd.oasis.opendocument.text',
                'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"'
                    . ' manifest:version="1.2"><manifest:file-entry manifest:full-path="/"'
                    . ' manifest:media-type="application/vnd.oasis.opendocument.text"/>'
                    . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
                    . '</manifest:manifest>',
                'content.xml' => self::office_xml('odt', "Local ODT caf\u{00e9} \u{03a9}"),
            ]),
            default => throw new \InvalidArgumentException('Unknown fixture document format'),
        };
    }

    /**
     * Build office XML with optional exact byte length.
     *
     * @param string $extension Office document extension.
     * @param string $text Text to include.
     * @param int|null $bytes Optional XML length.
     * @return string Encoded XML.
     */
    public static function office_xml(string $extension, string $text, ?int $bytes = null): string {
        $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        [$before, $after] = match ($extension) {
            'docx' => ['<?xml version="1.0" encoding="UTF-8"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>',
                '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:body></w:document>'],
            'odt' => ['<?xml version="1.0" encoding="UTF-8"?>'
                . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
                . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2">'
                . '<office:body><office:text>',
                '<text:p>' . $text . '</text:p></office:text></office:body></office:document-content>'],
            'pptx' => ['<?xml version="1.0" encoding="UTF-8"?>'
                . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
                . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree>',
                '<p:sp><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>' . $text
                . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>'],
            default => throw new \InvalidArgumentException('Unknown fixture XML format'),
        };
        if ($bytes === null) {
            return $before . $after;
        }
        $padding = $bytes - strlen($before . $after) - 7;
        if ($padding < 0) {
            throw new \InvalidArgumentException('XML fixture size is too small');
        }
        return $before . '<!--' . str_repeat('x', $padding) . '-->' . $after;
    }

    /**
     * Encode a PDF text drawing command.
     *
     * @param string $text PDF text content.
     * @return string PDF text stream.
     */
    public static function pdf_text(string $text): string {
        return 'BT /F1 12 Tf 40 100 Td (' . strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') Tj ET';
    }

    /**
     * Build a minimal PDF with the supplied content streams.
     *
     * @param array $streams PDF content streams.
     * @param bool $compressed Whether to compress streams.
     * @param bool $image Whether to include an image.
     * @return string Encoded PDF.
     */
    public static function pdf(array $streams, bool $compressed = false, bool $image = false): string {
        $contents = implode(' ', array_map(static fn($id) => $id . ' 0 R', range(5, 4 + count($streams))));
        $imageid = 5 + count($streams);
        $resources = '/Font << /F1 4 0 R >>' . ($image ? ' /XObject << /Im0 ' . $imageid . ' 0 R >>' : '');
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << ' . $resources
                . ' >> /Contents [' . $contents . '] >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        foreach ($streams as $stream) {
            $data = $compressed ? gzcompress($stream) : $stream;
            $objects[] = '<< /Length ' . strlen($data) . ($compressed ? ' /Filter /FlateDecode' : '')
                . ">>\nstream\n" . $data . "\nendstream";
        }
        if ($image) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB'
                . " /BitsPerComponent 8 /Length 3 >>\nstream\n\x19\x56\x9c\nendstream";
        }
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $startxref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$startxref}\n%%EOF\n";
    }

    /**
     * Store fixture bytes in the Moodle file pool.
     *
     * @param \context $context File context.
     * @param string $filename Stored filename.
     * @param string $content File bytes.
     * @param string $component File component.
     * @param string $filearea File area.
     * @param int $itemid File item ID.
     * @return \stored_file Stored fixture file.
     */
    public static function store(
        \context $context,
        string $filename,
        string $content,
        string $component = 'mod_resource',
        string $filearea = 'content',
        int $itemid = 0
    ): \stored_file {
        global $USER;
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => $component, 'filearea' => $filearea,
            'itemid' => $itemid, 'filepath' => '/', 'filename' => $filename, 'userid' => $USER->id,
        ], $content);
    }
}
