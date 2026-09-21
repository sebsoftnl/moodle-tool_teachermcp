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

namespace tool_teachermcp\tools;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use moodle_url;

/**
 * MCP tool to upload image or document assets into a course for embedding in pages, quizzes, or books.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_course_asset extends base_tool {

    public function get_name(): string {
        return 'upload_course_asset';
    }

    public function get_description(): string {
        return 'Uploads an image asset (PNG, JPEG, GIF, WEBP) into a course or target activity (Page or Book) for embedding. Stores the file in Moodle file storage and returns ready-to-use HTML <img> tags, Markdown tags, and native pluginfile URLs.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Target course ID where the asset should be available.',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Filename with extension (PNG, JPG/JPEG, GIF, or WEBP, e.g. "diagram.png", "chart.jpg").',
                ],
                'file_content_base64' => [
                    'type' => 'string',
                    'description' => 'Base64-encoded binary content of the image.',
                ],
                'image_url' => [
                    'type' => 'string',
                    'description' => 'Deprecated and disabled. Send file_content_base64 instead.',
                ],
                'alt_text' => [
                    'type' => 'string',
                    'description' => 'Accessible description / alt text for the image.',
                ],
                'target_cmid' => [
                    'type' => 'integer',
                    'description' => 'Optional course module ID (mod_page or mod_book) to store the asset directly in the activity file area.',
                ],
                'chapter_id' => [
                    'type' => 'integer',
                    'description' => 'Optional chapter ID if target_cmid is a Book activity. If omitted, the first chapter of the book is used.',
                ],
            ],
            'required' => ['course_id', 'filename'],
        ];
    }

    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/filelib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $rawfilename = trim($params['filename'] ?? '');
        $base64 = trim($params['file_content_base64'] ?? '');
        $imageurl = trim($params['image_url'] ?? '');
        $alttext = trim($params['alt_text'] ?? '');
        $targetcmid = (int)($params['target_cmid'] ?? 0);
        $targetchapterid = (int)($params['chapter_id'] ?? 0);

        if ($courseid <= 0) {
            throw new moodle_exception('invalidcourseid', 'error');
        }

        // Require both course capabilities.
        $course = $this->require_course_access($courseid, ['moodle/course:manageactivities', 'moodle/course:managefiles']);

        $filename = clean_param($rawfilename, PARAM_FILE);
        if (empty($filename)) {
            throw new moodle_exception('missingparam', 'error', '', 'filename');
        }

        // Extension check: only PNG, JPEG, GIF, and WEBP.
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedextensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        if (!in_array($extension, $allowedextensions, true)) {
            throw new moodle_exception('error_invalid_image_format', 'tool_teachermcp');
        }

        if (empty($alttext)) {
            $alttext = pathinfo($filename, PATHINFO_FILENAME);
        }

        // Enforce maximum upload size: course/site limit with 10 MB absolute hard cap.
        $hardlimit = 10 * 1024 * 1024; // 10 MB.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, $course->maxbytes);
        if ($maxbytes <= 0 || $maxbytes > $hardlimit) {
            $maxbytes = $hardlimit;
        }

        // Determine target filearea and context.
        $component = 'tool_teachermcp';
        $filearea = 'course_assets';
        $itemid = 0;
        $targetcontext = \context_course::instance($course->id);

        if ($targetcmid > 0) {
            list($cm, $cmcourse, $modcontext) = $this->require_cm_access($targetcmid);
            if ((int)$cmcourse->id !== (int)$course->id) {
                throw new moodle_exception('invalidcoursemoduleid', 'error', '', $targetcmid);
            }
            if ($cm->modname === 'page') {
                $component = 'mod_page';
                $filearea = 'content';
                $itemid = 0;
                $targetcontext = $modcontext;
            } else if ($cm->modname === 'book') {
                require_capability('mod/book:edit', $modcontext);
                $component = 'mod_book';
                $filearea = 'chapter';
                if ($targetchapterid > 0) {
                    $chapter = $DB->get_record('book_chapters', ['id' => $targetchapterid, 'bookid' => $cm->instance], '*', MUST_EXIST);
                    $itemid = (int)$chapter->id;
                } else {
                    $firstchapter = $DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum ASC', '*', 0, 1);
                    if (!empty($firstchapter)) {
                        $chapter = reset($firstchapter);
                        $itemid = (int)$chapter->id;
                    } else {
                        $itemid = 0;
                    }
                }
                $targetcontext = $modcontext;
            } else {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null, 'target_cmid must belong to a Page or Book activity.');
            }
        }

        $binarycontent = null;

        if (!empty($base64)) {
            // Strip data URI scheme prefix if present (e.g. data:image/png;base64,...).
            if (preg_match('/^data:[^;]+;base64,(.*)$/s', $base64, $m)) {
                $base64 = $m[1];
            }
            // Size check prior to decoding.
            if (strlen($base64) * 0.75 > $maxbytes) {
                throw new moodle_exception('error_file_too_large', 'tool_teachermcp');
            }
            $binarycontent = base64_decode($base64, true);
            if ($binarycontent === false) {
                throw new moodle_exception('error_invalid_base64', 'tool_teachermcp');
            }
        } else if (!empty($imageurl)) {
            throw new moodle_exception('error_remote_image_fetch_disabled', 'tool_teachermcp');
        } else {
            throw new moodle_exception('missingparam', 'error', '', 'file_content_base64');
        }

        // Validate decoded binary size.
        if (strlen($binarycontent) > $maxbytes) {
            throw new moodle_exception('error_file_too_large', 'tool_teachermcp');
        }

        // Verify content using getimagesizefromstring: reject SVG, HTML, JS and any non-image content.
        $imageinfo = @getimagesizefromstring($binarycontent);
        if ($imageinfo === false) {
            throw new moodle_exception('error_invalid_image_format', 'tool_teachermcp');
        }
        $allowedtypes = [
            IMAGETYPE_PNG,
            IMAGETYPE_JPEG,
            IMAGETYPE_GIF,
            IMAGETYPE_WEBP,
        ];
        if (!in_array($imageinfo[2], $allowedtypes, true)) {
            throw new moodle_exception('error_invalid_image_format', 'tool_teachermcp');
        }
        $detectedmime = strtolower($imageinfo['mime'] ?? '');
        if (!in_array($detectedmime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            throw new moodle_exception('error_invalid_image_format', 'tool_teachermcp');
        }

        $fs = get_file_storage();

        // Resolve filename collisions: generate unique suffix (_1, _2) without blind overwriting.
        $namepart = pathinfo($filename, PATHINFO_FILENAME);
        $extpart = pathinfo($filename, PATHINFO_EXTENSION);
        $counter = 1;
        while ($fs->file_exists($targetcontext->id, $component, $filearea, $itemid, '/', $filename)) {
            $filename = $namepart . '_' . $counter . ($extpart !== '' ? '.' . $extpart : '');
            $counter++;
        }

        $filerecord = [
            'contextid' => $targetcontext->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $storedfile = $fs->create_file_from_string($filerecord, $binarycontent);

        $assetrecord = (object)[
            'userid' => $USER->id,
            'contextid' => $targetcontext->id,
            'fileid' => $storedfile->get_id(),
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_teachermcp_assets', $assetrecord);

        // Build native Moodle pluginfile URL.
        $pluginfileurl = moodle_url::make_pluginfile_url(
            $targetcontext->id,
            $component,
            $filearea,
            $itemid,
            '/',
            $filename
        )->out(false);

        $htmltag = sprintf(
            '<img src="%s" alt="%s" class="img-fluid rounded border my-2" />',
            s($pluginfileurl),
            s($alttext)
        );

        $markdowntag = sprintf('![%s](%s)', $alttext, $pluginfileurl);

        return [
            'status' => 'success',
            'file_id' => $storedfile->get_id(),
            'filename' => $filename,
            'course_id' => $course->id,
            'target_cmid' => $targetcmid ?: null,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filesize' => $storedfile->get_filesize(),
            'mimetype' => $storedfile->get_mimetype(),
            'url' => $pluginfileurl,
            'html_tag' => $htmltag,
            'markdown_tag' => $markdowntag,
            'usage_tip' => 'Embed this asset directly into any page, book chapter, or quiz question using the provided html_tag or url.',
        ];
    }
}
