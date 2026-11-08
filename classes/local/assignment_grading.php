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

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Side-effect-free access to native assignment grades.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignment_grading {
    /**
     * Read a grade without letting a latest-attempt lookup create a submission.
     *
     * @param \assign $assign Assignment whose access has already been checked.
     * @param int $userid Student ID.
     * @param int $attemptnumber Requested attempt, or -1 for latest.
     * @return \stdClass|null Existing grade, if any.
     */
    public static function read_grade(\assign $assign, int $userid, int $attemptnumber = -1): ?\stdClass {
        if ($attemptnumber < 0) {
            $submission = $assign->get_instance()->teamsubmission
                ? $assign->get_group_submission($userid, 0, false)
                : $assign->get_user_submission($userid, false);
            $attemptnumber = $submission ? (int)$submission->attemptnumber : 0;
        }
        // Native get_user_grade(..., false, -1) still creates the latest submission.
        return $assign->get_user_grade($userid, false, $attemptnumber) ?: null;
    }

    /**
     * Validate the complete attachment request before any grade or draft writes.
     *
     * @param \assign $assign Authorized assignment.
     * @param mixed $files Untrusted attachment list.
     * @return array Filename to decoded bytes, retaining native replacement semantics.
     */
    public static function validate_feedback_files(\assign $assign, mixed $files): array {
        global $CFG;
        if (!is_array($files) || !array_is_list($files)) {
            throw new \moodle_exception(
                'error_invalid_params',
                'tool_teachermcp',
                '',
                get_string('error_feedback_files_invalid', 'tool_teachermcp')
            );
        }
        if (!$files) {
            return [];
        }
        $plugin = $assign->get_feedback_plugin_by_type('file');
        if (!$plugin || !$plugin->is_enabled() || !$plugin->is_visible()) {
            throw new \moodle_exception('error_feedback_files_disabled', 'tool_teachermcp');
        }
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, $assign->get_course()->maxbytes);
        $decodedfiles = [];
        foreach ($files as $file) {
            if (
                !is_array($file) || !isset($file['filename'], $file['content_base64'])
                    || !is_string($file['filename']) || !is_string($file['content_base64'])
            ) {
                throw new \moodle_exception(
                    'error_invalid_params',
                    'tool_teachermcp',
                    '',
                    get_string('error_feedback_files_invalid', 'tool_teachermcp')
                );
            }
            $filename = clean_param($file['filename'], PARAM_FILE);
            $bytes = base64_decode($file['content_base64'], true);
            if (
                $filename === '' || $filename !== $file['filename'] || in_array($filename, ['.', '..'], true)
                    || array_key_exists($filename, $decodedfiles) || $bytes === false
            ) {
                throw new \moodle_exception(
                    'error_invalid_params',
                    'tool_teachermcp',
                    '',
                    get_string('error_feedback_files_invalid', 'tool_teachermcp')
                );
            }
            if (strlen($bytes) > $maxbytes) {
                throw new \moodle_exception(
                    'error_invalid_params',
                    'tool_teachermcp',
                    '',
                    get_string('error_feedback_file_too_large', 'tool_teachermcp', $maxbytes)
                );
            }
            $decodedfiles[$filename] = $bytes;
        }
        return $decodedfiles;
    }

    /**
     * Read native feedback-file metadata after assignment/student authorization.
     *
     * @param \assign $assign Authorized assignment.
     * @param \stdClass|null $grade Authorized student's existing grade.
     * @return array Authenticated native download links, never file contents or credentials.
     */
    public static function feedback_manifest(\assign $assign, ?\stdClass $grade): array {
        if ($grade === null) {
            return [];
        }
        $files = get_file_storage()->get_area_files(
            $assign->get_context()->id,
            'assignfeedback_file',
            'feedback_files',
            $grade->id,
            'filepath, filename',
            false
        );
        $manifest = [];
        foreach ($files as $file) {
            $manifest[] = [
                'file_id' => (int)$file->get_id(),
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'filesize' => (int)$file->get_filesize(),
                'mimetype' => $file->get_mimetype(),
                'download_url' => \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    'assignfeedback_file',
                    'feedback_files',
                    $grade->id,
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                )->out(false),
            ];
        }
        return $manifest;
    }
}
