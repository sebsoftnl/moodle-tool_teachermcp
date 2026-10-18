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
use stdClass;
use ZipArchive;

/**
 * MCP tool to build, package, and deploy interactive H5P activities and Content Bank materials.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_h5p_activity extends base_tool {

    public function get_name(): string {
        return 'create_h5p_activity';
    }

    public function get_description(): string {
        return 'Builds, packages, and deploys fully functioning H5P interactive learning content (e.g. Multiple Choice, Flashcards, Accordion, True/False, Interactive Questionnaires) into Moodle courses as mod_h5pactivity and stores it in the Moodle Content Bank. Generates compliant h5p.json and content.json automatically based on installed site libraries.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Target course ID where the H5P activity should be added.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Title of the interactive H5P activity.',
                ],
                'library_name' => [
                    'type' => 'string',
                    'description' => 'The machine name of the H5P library (e.g. "H5P.MultiChoice", "H5P.Flashcards", "H5P.TrueFalse", "H5P.Accordion"). Check get_h5p_libraries for available types.',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'The JSON parameters object defining the interactive content (questions, answers, cards, feedback, behaviour).',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Course section index where activity should appear (default: 0).',
                    'default' => 0,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'Optional introductory description shown to students.',
                    'default' => '',
                ],
                'deploy_as_activity' => [
                    'type' => 'boolean',
                    'description' => 'Whether to add this H5P directly to the course as an interactive graded activity (default: true).',
                    'default' => true,
                ],
                'add_to_content_bank' => [
                    'type' => 'boolean',
                    'description' => 'Whether to also store this H5P in the course Content Bank for future teacher re-use and editing (default: true).',
                    'default' => true,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Whether the activity should be immediately visible to students (default: false).',
                    'default' => false,
                ],
                'max_grade' => [
                    'type' => 'number',
                    'description' => 'Maximum grade points for this activity (default: 100).',
                    'default' => 100,
                ],
            ],
            'required' => ['course_id', 'title', 'library_name', 'params'],
        ];
    }

    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/h5pactivity/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $title = clean_param($params['title'] ?? '', PARAM_TEXT);
        $libraryname = trim($params['library_name'] ?? '');
        $contentparams = $params['params'] ?? null;
        $sectionnum = (int)($params['section_number'] ?? 0);
        $intro = isset($params['intro']) ? $this->clean_agent_html($params['intro']) : '';
        $deployasactivity = !isset($params['deploy_as_activity']) || !empty($params['deploy_as_activity']);
        $addtocontentbank = !isset($params['add_to_content_bank']) || !empty($params['add_to_content_bank']);
        $maxgrade = isset($params['max_grade']) ? (float)$params['max_grade'] : 100.0;

        if ($courseid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'course_id');
        }
        if (empty($title)) {
            throw new moodle_exception('missingparam', 'error', '', 'title');
        }
        if (empty($libraryname)) {
            throw new moodle_exception('missingparam', 'error', '', 'library_name');
        }
        if (empty($contentparams) || (!is_array($contentparams) && !is_object($contentparams) && !is_string($contentparams))) {
            throw new moodle_exception('missingparam', 'error', '', 'params');
        }

        // N4: Validate JSON structure if passed as string and enforce max 2.5 MB limit.
        if (is_string($contentparams)) {
            $decoded = json_decode($contentparams);
            if (!$decoded instanceof stdClass) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null, 'Invalid JSON structure in params.');
            }
            $contentparams = $decoded;
        }

        // Recursive depth check: max 20 nesting levels allowed.
        $this->check_params_depth($contentparams);

        $encodedparams = json_encode($contentparams);
        if ($encodedparams === false || $encodedparams === '{}' || !str_starts_with($encodedparams, '{')) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                'H5P content parameters must encode a nonempty JSON object.');
        }
        if (strlen($encodedparams) > 2621440) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null, 'H5P content parameters exceed maximum allowed size of 2.5 MB.');
        }

        $course = $this->require_course_access($courseid, 'moodle/course:manageactivities');
        $coursecontext = \context_course::instance($course->id);
        require_capability('moodle/h5p:deploy', $coursecontext);

        // N5: Verify section exists in course.
        $modinfo = get_fast_modinfo($course);
        $sectioninfos = $modinfo->get_section_info_all();
        if (!isset($sectioninfos[$sectionnum])) {
            throw new moodle_exception('sectionnotexist', 'error');
        }

        // N5: Activities are hidden by default (visible = 0).
        $visible = !empty($params['visible']) ? 1 : 0;
        if ($deployasactivity) {
            require_capability('mod/h5pactivity:addinstance', $coursecontext);
            if ($visible === 1) {
                require_capability('moodle/course:activityvisibility', $coursecontext);
            }
        } else if ($addtocontentbank) {
            require_capability('moodle/contentbank:upload', $coursecontext);
        }

        // 1. Locate latest runnable library matching the requested exact name.
        $lib = $DB->get_record_sql(
            "SELECT * FROM {h5p_libraries}
              WHERE machinename = :machinename
                AND runnable = 1 AND enabled = 1
           ORDER BY majorversion DESC, minorversion DESC",
            ['machinename' => $libraryname],
            IGNORE_MULTIPLE
        );

        if (!$lib) {
            throw new moodle_exception(
                'error_library_not_found',
                'tool_teachermcp',
                '',
                null,
                "H5P library '{$libraryname}' was not found or is disabled on this Moodle site. Call get_h5p_libraries to check available types."
            );
        }

        if (!$deployasactivity && !$addtocontentbank) {
            return [
                'status' => 'success',
                'title' => $title,
                'library' => "{$lib->machinename} v{$lib->majorversion}.{$lib->minorversion}",
                'cmid' => null,
                'view_url' => null,
                'content_bank_id' => null,
                'course_id' => $course->id,
                'section_number' => $sectionnum,
                'message' => 'No destination was requested; nothing was stored or deployed.',
            ];
        }

        // 2. Fetch preloaded dependencies.
        $deps = $DB->get_records_sql(
            "SELECT hl.machinename, hl.majorversion, hl.minorversion
               FROM {h5p_library_dependencies} hld
               JOIN {h5p_libraries} hl ON hl.id = hld.requiredlibraryid
              WHERE hld.libraryid = :libid AND hld.dependencytype = 'preloaded'
           ORDER BY hl.machinename ASC",
            ['libid' => $lib->id]
        );

        $preloaded = [];
        foreach ($deps as $d) {
            $preloaded[] = [
                'machineName' => $d->machinename,
                'majorVersion' => (int)$d->majorversion,
                'minorVersion' => (int)$d->minorversion,
            ];
        }
        $preloaded[] = [
            'machineName' => $lib->machinename,
            'majorVersion' => (int)$lib->majorversion,
            'minorVersion' => (int)$lib->minorversion,
        ];

        // 3. Prepare JSON manifests.
        $h5pmanifest = [
            'title' => $title,
            'language' => current_language(),
            'mainLibrary' => $lib->machinename,
            'embedTypes' => ['div'],
            'preloadedDependencies' => $preloaded,
        ];

        $h5pjson = json_encode($h5pmanifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $contentjson = json_encode($contentparams, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 4. Create .h5p ZIP archive.
        $tempdir = make_request_directory();
        $safefilename = clean_param($title, PARAM_FILE);
        if (empty($safefilename)) {
            $safefilename = 'activity_' . time();
        }
        $zippath = $tempdir . '/' . $safefilename . '.h5p';

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new moodle_exception('error_create_zip', 'tool_teachermcp', '', null, 'Failed to create temporary H5P zip archive.');
            }
            $zip->addFromString('h5p.json', $h5pjson);
            $zip->addFromString('content/content.json', $contentjson);
            $zip->close();
        } else {
            $fp = get_file_packer('application/zip');
            $files = [
                'h5p.json' => $h5pjson,
                'content/content.json' => $contentjson,
            ];
            $fp->archive_to_pathname($files, $zippath);
        }

        $fs = get_file_storage();
        $contentbankid = null;
        $cmid = null;
        $viewurl = null;

        $transaction = $DB->start_delegated_transaction();
        try {
            // 5. Add to Content Bank if requested (N5).
            $contentbankwarning = null;
            if ($addtocontentbank) {
                $cbfile = null;
                try {
                    if (!has_capability('moodle/contentbank:upload', $coursecontext)) {
                        $contentbankwarning = 'Could not save to Content Bank: missing moodle/contentbank:upload capability.';
                    } else {
                        $cb = new \core_contentbank\contentbank();
                        if ($cb->get_extension_supporter('.h5p', $coursecontext) === null) {
                            $contentbankwarning = 'Could not save to Content Bank: content type cannot be uploaded.';
                        } else {
                            $cbrecord = [
                                'contextid' => $coursecontext->id,
                                'component' => 'contentbank',
                                'filearea' => 'public',
                                'itemid' => 0,
                                'filepath' => '/',
                                'filename' => $safefilename . '_' . time() . '.h5p',
                                'userid' => $USER->id,
                            ];
                            $cbfile = $fs->create_file_from_pathname($cbrecord, $zippath);
                            $cbcontent = $cb->create_content_from_file($coursecontext, $USER->id, $cbfile);
                            if ($cbcontent) {
                                $contentbankid = (int)$cbcontent->get_id();
                            } else {
                                $contentbankwarning = 'Could not save to Content Bank: creation returned empty content.';
                            }
                        }
                    }
                } catch (\Throwable $cbe) {
                    $contentbankwarning = 'Could not save to Content Bank: ' . $cbe->getMessage();
                } finally {
                    if ($cbfile !== null) {
                        $cbfile->delete();
                    }
                }
            }
            if (!$deployasactivity && $contentbankid === null) {
                throw new moodle_exception('error_h5p_save_failed', 'tool_teachermcp');
            }

            // 6. Deploy as mod_h5pactivity in course if requested (N5).
            if ($deployasactivity) {
                $modulerec = $DB->get_record('modules', ['name' => 'h5pactivity'], '*', MUST_EXIST);

                $cm = new stdClass();
                $cm->course = $course->id;
                $cm->module = $modulerec->id;
                $cm->instance = 0;
                $cm->section = $sectionnum;
                $cm->idnumber = '';
                $cm->added = time();
                $cm->score = 0;
                $cm->indent = 0;
                $cm->visible = $visible;
                $cm->visibleold = $visible;
                $cm->groupmode = 0;
                $cm->groupingid = 0;
                $cm->completion = 0;

                $cmid = add_course_module($cm);
                course_add_cm_to_section($course, $cmid, $sectionnum);

                $modcontext = \context_module::instance($cmid);
                $modfilerecord = [
                    'contextid' => $modcontext->id,
                    'component' => 'mod_h5pactivity',
                    'filearea' => 'package',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $safefilename . '.h5p',
                    'userid' => $USER->id,
                ];
                $packagefile = $fs->create_file_from_pathname($modfilerecord, $zippath);

                $h5pactivity = new stdClass();
                $h5pactivity->course = $course->id;
                $h5pactivity->name = $title;
                $h5pactivity->intro = $intro;
                $h5pactivity->introformat = FORMAT_HTML;
                $h5pactivity->grade = $maxgrade;
                $h5pactivity->grademethod = 1; // Highest attempt.
                $h5pactivity->enabletracking = 1;
                $h5pactivity->coursemodule = $cmid;
                $h5pactivity->cmidnumber = '';
                $h5pactivity->reference = $packagefile->get_filename();

                $factory = new \core_h5p\factory();
                $core = $factory->get_core();
                $config = \core_h5p\helper::decode_display_options($core);
                $h5pactivity->displayoptions = \core_h5p\helper::get_display_options($core, $config);

                h5pactivity_add_instance($h5pactivity, null);

                // Deploy and register the H5P content into core {h5p} tables.
                if (!$this->save_activity_content($factory, $packagefile)) {
                    throw new moodle_exception('error_h5p_save_failed', 'tool_teachermcp');
                }

                \course_modinfo::purge_course_module_cache($course->id, $cmid);
                rebuild_course_cache($course->id, true);

                $viewurl = (new moodle_url('/mod/h5pactivity/view.php', ['id' => $cmid]))->out(false);
            }

            $result = [
                'status' => 'success',
                'title' => $title,
                'library' => "{$lib->machinename} v{$lib->majorversion}.{$lib->minorversion}",
                'cmid' => $cmid,
                'view_url' => $viewurl,
                'content_bank_id' => $contentbankid,
                'course_id' => $course->id,
                'section_number' => $sectionnum,
                'message' => $cmid !== null ? "H5P activity '{$title}' successfully built, validated, and deployed."
                    : "H5P content '{$title}' successfully saved to the Content Bank.",
            ];

            if ($cmid !== null) {
                $result['visible'] = (bool)$DB->get_field('course_modules', 'visible', ['id' => $cmid], MUST_EXIST);
            }

            if ($contentbankwarning !== null) {
                $result['warning'] = $contentbankwarning;
            }

            $transaction->allow_commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                $transaction->rollback($error);
            } finally {
                // Native module caches are outside the database transaction.
                $cache = \cache::make('core', 'coursemodinfo');
                $cache->acquire_lock($course->id);
                try {
                    $cache->delete($course->id);
                } finally {
                    $cache->release_lock($course->id);
                }
                \course_modinfo::clear_instance_cache();
            }
        } finally {
            if (file_exists($zippath)) {
                unlink($zippath);
            }
        }
    }

    protected function save_activity_content(\core_h5p\factory $factory, \stored_file $file): int|false|null {
        return \core_h5p\helper::save_h5p($factory, $file,
            (object)['frame' => 1, 'export' => 1, 'embed' => 1, 'copyright' => 1]);
    }

    /**
     * Recursively check array nesting depth does not exceed maximum levels.
     *
     * @param mixed $data Array or object to inspect.
     * @param int $currentdepth Current recursion depth.
     * @param int $maxdepth Maximum allowed depth.
     * @throws moodle_exception If depth exceeds limit.
     */
    protected function check_params_depth($data, int $currentdepth = 1, int $maxdepth = 20): void {
        if ($currentdepth > $maxdepth) {
            throw new moodle_exception('error_params_too_deep', 'tool_teachermcp');
        }
        if (is_array($data) || is_object($data)) {
            foreach ($data as $value) {
                if (is_array($value) || is_object($value)) {
                    $this->check_params_depth($value, $currentdepth + 1, $maxdepth);
                }
            }
        }
    }
}
