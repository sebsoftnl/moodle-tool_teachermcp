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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

/**
 * Robust file text extraction and inspection helper for course learning materials.
 * Supports PDF, DOCX, PPTX, ODT, CSV, JSON, XML, HTML, and plain text.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_extractor {
    /** Maximum file size in bytes to attempt textual extraction (15 MB). */
    const MAX_EXTRACT_SIZE = 15728640;

    /** Maximum uncompressed size for zip entries (20 MB). */
    const MAX_ZIP_ENTRY_SIZE = 20971520;

    /** Maximum decompressed stream size in bytes (50 MB). */
    const MAX_DECOMPRESS_SIZE = 52428800;

    /** Maximum number of PDF streams to process. */
    const MAX_PDF_STREAMS = 500;

    /**
     * Check if a component and filearea pair is whitelisted for learning material extraction (M1 & K1).
     * Strictly excludes student submissions (assignsubmission_*), student feedback, student forum attachments, etc.
     *
     * @param string $component
     * @param string $filearea
     * @return bool
     */
    public static function is_allowed_filearea(string $component, string $filearea): bool {
        if ($filearea === 'intro') {
            return true;
        }

        $allowed = [
            'mod_resource' => ['content'],
            'mod_folder' => ['content'],
            'mod_page' => ['content'],
            'mod_book' => ['chapter'],
            'mod_assign' => ['introattachment'],
            'mod_h5pactivity' => ['package'],
            'course' => ['summary', 'overviewfiles'],
        ];

        if (isset($allowed[$component]) && in_array($filearea, $allowed[$component], true)) {
            return true;
        }

        if ($component === 'question') {
            return !str_starts_with($filearea, 'response_');
        }

        return false;
    }

    /**
     * Retrieve all non-directory files attached to a course module that match the safe learning material whitelist.
     *
     * @param int $cmid Course module ID.
     * @return \stored_file[]
     */
    public static function get_module_files(int $cmid): array {
        global $DB;
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }

        $fs = get_file_storage();
        $areas = $DB->get_records_sql(
            'SELECT MIN(id) AS id, component, filearea
                FROM {files} WHERE contextid = :contextid AND filename <> :directory
            GROUP BY component, filearea ORDER BY component, filearea',
            ['contextid' => $context->id, 'directory' => '.']
        );

        $validfiles = [];
        foreach ($areas as $area) {
            // M1: Enforce whitelist so student submissions or grading feedback files are never leaked.
            if (!self::is_allowed_filearea($area->component, $area->filearea)) {
                continue;
            }
            foreach (
                $fs->get_area_files(
                    $context->id,
                    $area->component,
                    $area->filearea,
                    false,
                    'itemid, filepath, filename',
                    false
                ) as $file
            ) {
                $validfiles[] = $file;
            }
        }

        return $validfiles;
    }

    /**
     * Check whether a stored file has a supported textual extraction format.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function is_extractable(\stored_file $file): bool {
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $supported = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm', 'docx', 'pptx', 'odt', 'pdf'];
        return in_array($ext, $supported, true);
    }

    /**
     * Generate an authenticated core pluginfile URL for a stored file.
     * Core pluginfile.php and *_pluginfile() callbacks enforce strict access control.
     *
     * @param \stored_file $file
     * @return string Core pluginfile URL
     */
    public static function get_download_url(\stored_file $file): string {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        )->out(false);
    }

    /**
     * Extract clean human-readable text from a stored file.
     *
     * @param \stored_file $file
     * @param int $maxchars Maximum characters to return.
     * @return array ['success' => bool, 'content' => string, 'notice' => string|null]
     */
    public static function extract_text(\stored_file $file, int $maxchars = 50000): array {
        if ($file->get_filesize() > self::MAX_EXTRACT_SIZE) {
            return [
                'success' => false,
                'content' => '',
                'notice' => "File exceeds maximum size for inline extraction (" . display_size($file->get_filesize()) . ").",
            ];
        }

        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

        switch ($ext) {
            case 'txt':
            case 'md':
            case 'markdown':
            case 'csv':
            case 'tsv':
            case 'json':
            case 'xml':
                $raw = $file->get_content();
                $clean = self::clean_plain_text($raw, $maxchars);
                return ['success' => true, 'content' => $clean, 'notice' => null];

            case 'html':
            case 'htm':
                $raw = $file->get_content();
                $clean = self::clean_plain_text(strip_tags($raw), $maxchars);
                return ['success' => true, 'content' => $clean, 'notice' => null];

            case 'docx':
                return self::extract_docx($file, $maxchars);

            case 'pptx':
                return self::extract_pptx($file, $maxchars);

            case 'odt':
                return self::extract_odt($file, $maxchars);

            case 'pdf':
                return self::extract_pdf($file, $maxchars);

            default:
                return [
                    'success' => false,
                    'content' => '',
                    'notice' => "Binary file type ({$ext}) cannot be parsed into plain text. Use the download link to view.",
                ];
        }
    }

    /**
     * Extract text from Microsoft Word (.docx) package.
     */
    protected static function extract_docx(\stored_file $file, int $maxchars): array {
        if (!class_exists('\ZipArchive')) {
            return ['success' => false, 'content' => '', 'notice' => 'PHP ZipArchive extension required.'];
        }

        $tempfile = $file->copy_content_to_temp('teachermcp', 'docx_');
        $zip = new \ZipArchive();
        if ($zip->open($tempfile) !== true) {
            @unlink($tempfile);
            return ['success' => false, 'content' => '', 'notice' => 'Could not open Word DOCX archive.'];
        }

        // L3: Check entry size before reading into memory.
        $stat = $zip->statName('word/document.xml');
        if (!$stat || $stat['size'] > self::MAX_ZIP_ENTRY_SIZE) {
            $zip->close();
            @unlink($tempfile);
            return ['success' => false, 'content' => '', 'notice' => 'Document XML exceeds maximum allowable size or is missing.'];
        }

        $xmlcontent = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($tempfile);

        if (empty($xmlcontent)) {
            return ['success' => false, 'content' => '', 'notice' => 'Empty Word document or missing word/document.xml.'];
        }

        $xmlcontent = preg_replace('/<\/w:p>/i', "\n\n", $xmlcontent);
        $xmlcontent = preg_replace('/<w:br[^>]*\/>/i', "\n", $xmlcontent);
        $xmlcontent = preg_replace('/<w:tab[^>]*\/>/i', "\t", $xmlcontent);

        $text = strip_tags($xmlcontent);
        $clean = self::clean_plain_text($text, $maxchars);
        if ($clean === '') {
            return ['success' => false, 'content' => '', 'notice' => 'No readable text found in Word document.'];
        }

        return ['success' => true, 'content' => $clean, 'notice' => null];
    }

    /**
     * Extract text from Microsoft PowerPoint (.pptx) presentation.
     */
    protected static function extract_pptx(\stored_file $file, int $maxchars): array {
        if (!class_exists('\ZipArchive')) {
            return ['success' => false, 'content' => '', 'notice' => 'PHP ZipArchive extension required.'];
        }

        $tempfile = $file->copy_content_to_temp('teachermcp', 'pptx_');
        $zip = new \ZipArchive();
        if ($zip->open($tempfile) !== true) {
            @unlink($tempfile);
            return ['success' => false, 'content' => '', 'notice' => 'Could not open PowerPoint PPTX archive.'];
        }

        $slidetexts = [];
        $limited = false;
        for ($i = 1; $i <= $zip->numFiles; $i++) {
            $slidename = "ppt/slides/slide{$i}.xml";
            $stat = $zip->statName($slidename);
            if ($stat && $stat['size'] <= self::MAX_ZIP_ENTRY_SIZE) {
                $content = $zip->getFromName($slidename);
                if ($content !== false) {
                    $content = preg_replace('/<\/a:p>/i', "\n", $content);
                    $clean = trim(strip_tags($content));
                    if (!empty($clean)) {
                        $slidetexts[] = "--- Slide {$i} ---\n" . $clean;
                    }
                } else {
                    $limited = true;
                }
            } else if ($stat) {
                $limited = true;
            }
        }
        $zip->close();
        @unlink($tempfile);

        if (empty($slidetexts)) {
            return ['success' => false, 'content' => '', 'notice' => 'No readable text found in PowerPoint slides.'];
        }

        $alltext = implode("\n\n", $slidetexts);
        $clean = self::clean_plain_text($alltext, $maxchars);

        return ['success' => true, 'content' => $clean,
            'notice' => $limited ? 'Some PowerPoint slides exceeded extraction limits or could not be read; the text is partial.' : null];
    }

    /**
     * Extract text from OpenDocument Text (.odt) package.
     */
    protected static function extract_odt(\stored_file $file, int $maxchars): array {
        if (!class_exists('\ZipArchive')) {
            return ['success' => false, 'content' => '', 'notice' => 'PHP ZipArchive extension required.'];
        }

        $tempfile = $file->copy_content_to_temp('teachermcp', 'odt_');
        $zip = new \ZipArchive();
        if ($zip->open($tempfile) !== true) {
            @unlink($tempfile);
            return ['success' => false, 'content' => '', 'notice' => 'Could not open OpenDocument ODT archive.'];
        }

        // L3: Check entry size before reading into memory.
        $stat = $zip->statName('content.xml');
        if (!$stat || $stat['size'] > self::MAX_ZIP_ENTRY_SIZE) {
            $zip->close();
            @unlink($tempfile);
            return ['success' => false, 'content' => '', 'notice' => 'ODT content.xml exceeds maximum allowable size.'];
        }

        $xmlcontent = $zip->getFromName('content.xml');
        $zip->close();
        @unlink($tempfile);

        if (empty($xmlcontent)) {
            return ['success' => false, 'content' => '', 'notice' => 'Empty ODT document.'];
        }

        $xmlcontent = preg_replace('/<\/text:p>/i', "\n\n", $xmlcontent);
        $xmlcontent = preg_replace('/<\/text:h>/i', "\n\n", $xmlcontent);

        $text = strip_tags($xmlcontent);
        $clean = self::clean_plain_text($text, $maxchars);
        if ($clean === '') {
            return ['success' => false, 'content' => '', 'notice' => 'No readable text found in ODT document.'];
        }

        return ['success' => true, 'content' => $clean, 'notice' => null];
    }

    /**
     * Extract text from text-based PDF documents via pure-PHP stream decoding.
     */
    protected static function extract_pdf(\stored_file $file, int $maxchars): array {
        $raw = $file->get_content();
        if (empty($raw)) {
            return ['success' => false, 'content' => '', 'notice' => 'Empty PDF file.'];
        }

        // L3: Split streams with bounded count.
        $extracted = [];
        // A length-only dictionary identifies the supported unfiltered stream form.
        preg_match_all(
            '/(<<\s*\/Length\s+\d+(?:\s+\d+\s+R)?\s*>>\s*)?stream\r?\n(.*?)[\r\n]+endstream/s',
            $raw,
            $matches,
            PREG_SET_ORDER
        );
        if (preg_last_error() !== PREG_NO_ERROR) {
            return ['success' => false, 'content' => '', 'notice' => 'Failed to parse PDF stream syntax.'];
        }

        $limited = count($matches) > self::MAX_PDF_STREAMS;
        $streams = array_slice($matches, 0, self::MAX_PDF_STREAMS);

        foreach ($streams as $match) {
            $stream = $match[2];
            // L3: Bounded decompression (max 50 MB per stream).
            $decompressed = @gzuncompress($stream, self::MAX_DECOMPRESS_SIZE);
            if ($decompressed === false) {
                $decompressed = @gzinflate($stream, self::MAX_DECOMPRESS_SIZE);
            }
            if ($decompressed === false) {
                $limited = $limited || $match[1] === '';
                $decompressed = $stream; // Might be uncompressed.
            }
            // Native zlib's requested maximum is not an exact output-size guarantee.
            if (strlen($decompressed) > self::MAX_DECOMPRESS_SIZE) {
                $limited = true;
                continue;
            }

            // Look for BT (Begin Text) ... ET (End Text) blocks.
            if (preg_match_all('/BT[\s\S]*?ET/m', $decompressed, $textblocks)) {
                foreach ($textblocks[0] as $tb) {
                    // Match (Text) Tj operators.
                    if (preg_match_all('/\((.*?)\)\s*Tj/s', $tb, $tjmatches)) {
                        foreach ($tjmatches[1] as $str) {
                            $extracted[] = self::decode_pdf_string($str);
                        }
                    }
                    // Match [(Text) 120 (More)] TJ operators.
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $tb, $tjarraymatches)) {
                        foreach ($tjarraymatches[1] as $arr) {
                            if (preg_match_all('/\((.*?)\)/s', $arr, $itemmatches)) {
                                $extracted[] = implode('', array_map([self::class, 'decode_pdf_string'], $itemmatches[1]));
                            }
                        }
                    }
                }
            }
        }

        $alltext = implode(' ', $extracted);
        $clean = self::clean_plain_text($alltext, $maxchars);
        if ($clean === '') {
            return [
                'success' => false,
                'content' => '',
                'notice' => 'PDF does not contain readable plain text streams (it may be a scanned image or encrypted).',
            ];
        }

        return ['success' => true, 'content' => $clean,
            'notice' => $limited ? 'Some PDF streams were omitted or could not be confirmed as decoded text; the extraction may be incomplete.' : null];
    }

    /**
     * Decode escaped characters in PDF string objects.
     */
    protected static function decode_pdf_string(string $str): string {
        $str = str_replace(['\\\\', '\(', '\)'], ['\\', '(', ')'], $str);
        // Replace octal escapes like \040 with corresponding characters.
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str);
        return $str;
    }

    /**
     * Clean, normalize, and truncate plain text strings.
     */
    protected static function clean_plain_text(string $text, int $maxchars): string {
        // Normalize newlines and whitespace.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        if (preg_last_error() !== PREG_NO_ERROR) {
            // Fallback if preg regex encounters PCRE backtracking limits.
            $text = trim($text);
        } else {
            $text = trim($text);
        }

        if (mb_strlen($text) > $maxchars) {
            $text = mb_substr($text, 0, $maxchars) . "\n\n... [Content truncated at {$maxchars} characters]";
        }

        return $text;
    }
}
