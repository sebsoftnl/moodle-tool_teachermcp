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

use tool_teachermcp\file_extractor;

defined('MOODLE_INTERNAL') || die();

/**
 * Tool to inspect, extract text, and download source files attached to course activities.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class read_activity_file extends base_tool {

    public function get_name(): string {
        return 'read_activity_file';
    }

    public function get_description(): string {
        return get_string('tool_read_activity_file_desc', 'tool_teachermcp');
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module ID containing the attached file(s) (e.g. resource, assign, folder).',
                ],
                'fileid' => [
                    'type' => 'integer',
                    'description' => 'Optional specific file ID to read/extract. If omitted and multiple files exist, a list of files with download links will be returned.',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Optional filename to filter by (e.g. "syllabus.pdf", "casestudy.docx").',
                ],
                'max_length' => [
                    'type' => 'integer',
                    'description' => 'Maximum characters of textual content to extract (default: 40000).',
                ],
            ],
            'required' => ['cmid'],
        ];
    }

    public function execute(array $params) {
        global $DB;

        $cmid = (int)($params['cmid'] ?? 0);
        $hasfileid = array_key_exists('fileid', $params);
        $hasfilename = array_key_exists('filename', $params);
        $fileid = $hasfileid ? (int)$params['fileid'] : 0;
        $filenamereq = $hasfilename ? trim($params['filename']) : '';
        $maxchars = !empty($params['max_length']) ? max(1000, min(150000, (int)$params['max_length'])) : 40000;

        if (!$cmid) {
            throw new \invalid_parameter_exception('cmid is required.');
        }

        // H2: Teachers have course access via enrollment; require manageactivities instead of course:view.
        list($cm, $course, $context) = $this->require_cm_access($cmid, 'moodle/course:manageactivities');

        $allfiles = file_extractor::get_module_files($cm->id);

        if (empty($allfiles) && !$hasfileid && !$hasfilename) {
            return [
                'status' => 'empty',
                'cmid' => $cm->id,
                'activity_name' => $cm->name,
                'message' => 'No files are attached to this course activity.',
                'files' => [],
            ];
        }

        $filelist = [];
        $targetfile = null;

        foreach ($allfiles as $f) {
            $downloadurl = file_extractor::get_download_url($f);
            $isextractable = file_extractor::is_extractable($f);

            $fileinfo = [
                'fileid' => (int)$f->get_id(),
                'filename' => $f->get_filename(),
                'filesize' => (int)$f->get_filesize(),
                'filesize_human' => display_size($f->get_filesize()),
                'mimetype' => $f->get_mimetype(),
                'timemodified' => (int)$f->get_timemodified(),
                'is_text_extractable' => $isextractable,
                'download_url' => $downloadurl,
            ];

            $filelist[] = $fileinfo;

            if ($hasfileid && $f->get_id() == $fileid) {
                $targetfile = $f;
            } else if (!$hasfileid && $hasfilename && strcasecmp($f->get_filename(), $filenamereq) === 0) {
                $targetfile = $f;
            }
        }

        if (($hasfileid || $hasfilename) && !$targetfile) {
            throw new \moodle_exception('filenotfound', 'error');
        }

        // If no specific file was requested but exactly one file exists, select it automatically.
        if (!$hasfileid && !$hasfilename && count($allfiles) === 1) {
            $targetfile = reset($allfiles);
        }

        $response = [
            'cmid' => $cm->id,
            'activity_name' => $cm->name,
            'module_type' => $cm->modname,
            'total_files' => count($filelist),
            'files' => $filelist,
        ];

        if ($targetfile) {
            $extraction = file_extractor::extract_text($targetfile, $maxchars);
            $response['selected_file'] = [
                'fileid' => (int)$targetfile->get_id(),
                'filename' => $targetfile->get_filename(),
                'filesize_human' => display_size($targetfile->get_filesize()),
                'mimetype' => $targetfile->get_mimetype(),
                'download_url' => file_extractor::get_download_url($targetfile),
                'extracted_success' => $extraction['success'],
                'extracted_notice' => $extraction['notice'],
                'content_text' => $extraction['content'],
            ];
        } else {
            $response['notice'] = 'Multiple files found. Specify fileid or filename to extract content of a specific file.';
        }

        return $response;
    }
}
