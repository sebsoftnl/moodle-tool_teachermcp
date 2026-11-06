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

/**
 * MCP tool to inspect course completion criteria and student completion progress.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_course_completion extends base_tool {
    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public function get_name(): string {
        return 'manage_course_completion';
    }

    /**
     * Return the MCP tool description.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        return 'Inspect course completion criteria, rules, and student completion progress status.';
    }

    /**
     * Return the JSON input schema for this tool.
     *
     * @return array Result of the operation.
     */
    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle course ID.',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Action: "get_criteria" or "get_student_progress".',
                    'enum' => ['get_criteria', 'get_student_progress'],
                ],
                'student_user_id' => [
                    'type' => 'integer',
                    'description' => 'Target student user ID (for get_student_progress).',
                ],
            ],
            'required' => ['course_id', 'action'],
        ];
    }

    /**
     * Execute the tool using the validated arguments.
     *
     * @param array $params params value.
     */
    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/completionlib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $action = $params['action'] ?? 'get_criteria';

        $course = $this->require_course_access($courseid, 'report/completion:view');
        $context = \context_course::instance($course->id);
        $completion = new \completion_info($course);

        if (!$completion->is_enabled()) {
            return [
                'course_id' => $courseid,
                'completion_enabled' => false,
                'message' => 'Completion tracking is not enabled for this course.',
            ];
        }

        if ($action === 'get_criteria') {
            $criteria = $completion->get_criteria();
            $criterialist = [];

            foreach ($criteria as $crit) {
                $criterialist[] = [
                    'id' => (int)$crit->id,
                    'criteriatype' => $crit->criteriatype,
                    'title' => $crit->get_title(),
                    'details' => $crit->get_details(),
                ];
            }

            return [
                'course_id' => $courseid,
                'completion_enabled' => true,
                'aggregation_method' => $completion->get_aggregation_method(),
                'total_criteria' => count($criterialist),
                'criteria' => $criterialist,
            ];
        }

        if ($action === 'get_student_progress') {
            $studentid = (int)($params['student_user_id'] ?? 0);
            if (!$studentid) {
                throw new moodle_exception('missingparam', 'error', '', 'student_user_id');
            }

            // M3 & M2: Enforce active enrollment and group isolation.
            if (!is_enrolled($context, $studentid, '', true)) {
                throw new moodle_exception('error_not_enrolled', 'tool_teachermcp');
            }

            $groupmode = groups_get_course_groupmode($course);
            $canaccessallgroups = has_capability('moodle/site:accessallgroups', $context);
            if ($groupmode == SEPARATEGROUPS && !$canaccessallgroups) {
                $teachergroups = groups_get_user_groups($course->id, $USER->id)[0] ?? [];
                $studentgroups = groups_get_user_groups($course->id, $studentid)[0] ?? [];
                if (empty(array_intersect($teachergroups, $studentgroups))) {
                    throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                }
            }

            $modinfo = get_fast_modinfo($course);
            $activitiesprogress = [];

            foreach ($modinfo->cms as $cm) {
                if ($cm->completion == COMPLETION_TRACKING_NONE || !$cm->uservisible) {
                    continue;
                }

                $data = $completion->get_data($cm, false, $studentid);

                $statuslabel = 'incomplete';
                if ($data->completionstate == COMPLETION_COMPLETE) {
                    $statuslabel = 'complete';
                } else if ($data->completionstate == COMPLETION_COMPLETE_PASS) {
                    $statuslabel = 'complete_pass';
                } else if ($data->completionstate == COMPLETION_COMPLETE_FAIL) {
                    $statuslabel = 'complete_fail';
                }

                $timecompleted = $data->completionstate == COMPLETION_INCOMPLETE ? null : (int)$data->timemodified;
                $activitiesprogress[] = [
                    'cmid' => (int)$cm->id,
                    'modname' => $cm->modname,
                    'name' => $cm->name,
                    'state' => $statuslabel,
                    'timecompleted' => $timecompleted,
                    'timecompleted_iso' => $timecompleted !== null ? date('c', $timecompleted) : null,
                ];
            }

            $coursecomplete = $completion->is_course_complete($studentid);

            return [
                'course_id' => $courseid,
                'student_user_id' => $studentid,
                'is_course_complete' => (bool)$coursecomplete,
                'activities_progress' => $activitiesprogress,
            ];
        }

        throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', null, "Unsupported action: {$action}");
    }
}
