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

/**
 * MCP tool to create and scaffold Moodle Assignment (mod_assign) activities.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_assignment extends base_tool {

    public function get_name(): string {
        return 'create_assignment';
    }

    public function get_description(): string {
        return 'Create a Moodle Assignment (mod_assign) in a course section with due dates, submission settings, and optional grading rubric.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Target Moodle course ID.',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Target course section number (defaults to 0).',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Assignment title/name.',
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'Assignment prompt, instructions, and requirements (HTML or plain text).',
                ],
                'due_date' => [
                    'type' => 'integer',
                    'description' => 'Unix timestamp for submission due date (optional).',
                ],
                'cutoff_date' => [
                    'type' => 'integer',
                    'description' => 'Unix timestamp after which submissions are no longer accepted (optional).',
                ],
                'allow_online_text' => [
                    'type' => 'boolean',
                    'description' => 'Allow student online text entry (default: true).',
                ],
                'allow_file_submissions' => [
                    'type' => 'boolean',
                    'description' => 'Allow student file uploads (default: true).',
                ],
                'max_files' => [
                    'type' => 'integer',
                    'description' => 'Maximum files allowed per submission (default: 2).',
                ],
                'max_grade' => [
                    'type' => 'number',
                    'description' => 'Maximum grade score (default: 100).',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Visibility status for students (default: false / hidden for teacher review).',
                ],
                'rubric_criteria' => [
                    'type' => 'array',
                    'description' => 'Optional rubric evaluation criteria descriptors to document in the assignment prompt.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'weight_percentage' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'required' => ['course_id', 'title'],
        ];
    }

    public function execute(array $params) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $course = $this->require_course_access($courseid, 'mod/assign:addinstance');

        $title = isset($params['title']) ? clean_param($params['title'], PARAM_TEXT) : '';
        if ($title === '') {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
        }
        $intro = isset($params['intro']) ? $this->clean_agent_html($params['intro']) : '';
        $sectionnum = (int)($params['section_number'] ?? 0);

        // Verify section exists.
        $modinfo = get_fast_modinfo($course);
        $sectioninfos = $modinfo->get_section_info_all();
        if (!isset($sectioninfos[$sectionnum])) {
            throw new moodle_exception('sectionnotexist', 'error');
        }

        $visible = !empty($params['visible']) ? 1 : 0;
        if ($visible === 1) {
            $context = \context_course::instance($course->id);
            require_capability('moodle/course:activityvisibility', $context);
        }

        // If rubric criteria are specified, append a clean formatted rubric section to the intro.
        $rubric = $params['rubric_criteria'] ?? null;
        if ($rubric !== null && !is_array($rubric)) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
        }
        if (is_array($rubric) && !empty($rubric)) {
            if (count($rubric) > 20) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
            }
            $totalweight = 0.0;
            $intro .= '<div class="alert alert-secondary mt-3"><h5>' . get_string('rubric', 'gradingform_rubric') . '</h5><ul class="mb-0">';
            foreach ($rubric as $criterion) {
                if (!is_array($criterion)) {
                    throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
                }
                $desc = clean_param($criterion['description'] ?? '', PARAM_TEXT);
                if ($desc === '') {
                    throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
                }
                $rawweight = $criterion['weight_percentage'] ?? null;
                if (!is_numeric($rawweight)) {
                    throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
                }
                $weightvalue = (float)$rawweight;
                if (!is_finite($weightvalue) || $weightvalue < 0 || $weightvalue > 100) {
                    throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
                }
                $totalweight += $weightvalue;
                $intro .= '<li><strong>' . $desc . '</strong> (' . $weightvalue . '%)</li>';
            }
            if ($totalweight <= 0.0 || $totalweight > 100.0) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
            }
            $intro .= '</ul></div>';
        }

        $duedate = !empty($params['due_date']) ? (int)$params['due_date'] : 0;
        $cutoffdate = !empty($params['cutoff_date']) ? (int)$params['cutoff_date'] : 0;
        $allowonlinetext = $params['allow_online_text'] ?? true;
        $allowfile = $params['allow_file_submissions'] ?? true;
        if (!$allowonlinetext && !$allowfile) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
        }
        $maxfiles = isset($params['max_files']) ? max(1, min(20, (int)$params['max_files'])) : 2;
        $rawmaxgrade = $params['max_grade'] ?? 100;
        if (!is_numeric($rawmaxgrade)) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
        }
        $maxgrade = (float)$rawmaxgrade;
        if (!is_finite($maxgrade) || $maxgrade < 1 || $maxgrade > 100000) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
        }
        if ($duedate > 0 && $cutoffdate > 0 && $cutoffdate < $duedate) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp');
        }

        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'assign';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->name = $title;
        $moduleinfo->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->alwaysshowdescription = 1;
        $moduleinfo->submissiondrafts = 0;
        $moduleinfo->requiresubmissionstatement = 0;
        $moduleinfo->sendnotifications = 0;
        $moduleinfo->sendlatenotifications = 0;
        $moduleinfo->duedate = $duedate;
        $moduleinfo->cutoffdate = $cutoffdate;
        $moduleinfo->gradingduedate = $duedate ? ($duedate + (7 * 86400)) : 0;
        $moduleinfo->allowsubmissionsfromdate = time();
        $moduleinfo->grade = $maxgrade;
        $moduleinfo->assignsubmission_onlinetext_enabled = $allowonlinetext ? 1 : 0;
        $moduleinfo->assignsubmission_file_enabled = $allowfile ? 1 : 0;
        $moduleinfo->assignsubmission_file_maxfiles = $maxfiles;
        $moduleinfo->assignsubmission_file_maxsizebytes = 0;
        $moduleinfo->teamsubmission = 0;
        $moduleinfo->requireallteammemberssubmit = 0;
        $moduleinfo->teamsubmissiongroupingid = 0;
        $moduleinfo->preventsubmissionnotingroup = 0;
        $moduleinfo->blindmarking = 0;
        $moduleinfo->markingworkflow = 0;
        $moduleinfo->markingallocation = 0;
        $moduleinfo->visible = $visible;
        $moduleinfo->visibleold = $visible;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;

        $created = create_module($moduleinfo);
        $cmid = (int)$created->coursemodule;
        $assignid = (int)$created->instance;

        return [
            'status' => 'success',
            'activity_type' => 'assign',
            'cmid' => $cmid,
            'assignment_id' => $assignid,
            'title' => $title,
            'course_id' => (int)$course->id,
            'section_number' => $sectionnum,
            'due_date' => $duedate ? userdate($duedate) : null,
            'view_url' => (new moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false),
            'message' => "Assignment '{$title}' created successfully.",
        ];
    }
}
