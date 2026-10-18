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
use stdClass;
use context_course;

/**
 * MCP tool to inspect course gradebook items and update student grades.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_grades extends base_tool {

    public function get_name(): string {
        return 'manage_grades';
    }

    public function get_description(): string {
        return 'Inspect course gradebook items and update numeric, scale, or letter grades with lock checking and range validation.';
    }

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
                    'description' => 'Action: "list_grade_items", "get_student_grades", or "update_grade".',
                    'enum' => ['list_grade_items', 'get_student_grades', 'update_grade'],
                ],
                'grade_item_id' => [
                    'type' => 'integer',
                    'description' => 'Grade item ID (required for update_grade).',
                ],
                'student_user_id' => [
                    'type' => 'integer',
                    'description' => 'Student user ID.',
                ],
                'final_grade' => [
                    'type' => 'number',
                    'description' => 'Numeric grade value to record.',
                ],
                'grade_letter' => [
                    'type' => 'string',
                    'description' => 'Optional letter grade (e.g. "A", "B+", "C") to convert and save.',
                ],
                'scale_item' => [
                    'type' => 'string',
                    'description' => 'Optional scale text item (e.g. "Competent", "Pass") for scale-based grade items.',
                ],
                'feedback' => [
                    'type' => 'string',
                    'description' => 'Feedback note accompanying the grade.',
                ],
            ],
            'required' => ['course_id', 'action'],
        ];
    }

    public function execute(array $params) {
        global $DB, $CFG, $USER;
        require_once($CFG->libdir . '/gradelib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $action = $params['action'] ?? 'list_grade_items';

        // H7 & M1: Enforce connectagent and course grade viewing rights.
        $course = $this->require_course_access($courseid, 'moodle/grade:viewall');
        $context = context_course::instance($courseid);

        if ($action === 'list_grade_items') {
            $items = \grade_item::fetch_all(['courseid' => $courseid]);
            $list = [];

            if ($items) {
                foreach ($items as $item) {
                    $scaleinfo = null;
                    if ($item->scaleid) {
                        $scale = $DB->get_record('scale', ['id' => $item->scaleid]);
                        if ($scale) {
                            $scaleinfo = [
                                'scale_id' => (int)$scale->id,
                                'scale_name' => $scale->name,
                                'items' => array_map('trim', explode(',', $scale->scale)),
                            ];
                        }
                    }

                    $list[] = [
                        'id' => (int)$item->id,
                        'itemname' => $item->get_name(),
                        'itemtype' => $item->itemtype,
                        'itemmodule' => $item->itemmodule,
                        'gradetype' => (int)$item->gradetype,
                        'grademin' => (float)$item->grademin,
                        'grademax' => (float)$item->grademax,
                        'hidden' => (bool)$item->is_hidden(),
                        'locked' => (bool)$item->is_locked(),
                        'scale' => $scaleinfo,
                    ];
                }
            }

            return [
                'course_id' => $courseid,
                'total_items' => count($list),
                'grade_items' => $list,
            ];
        }

        if ($action === 'get_student_grades') {
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

            $grades = \grade_grade::fetch_all(['userid' => $studentid]);
            $studentgrades = [];

            if ($grades) {
                foreach ($grades as $g) {
                    $item = $g->load_grade_item();
                    if ($item && $item->courseid == $courseid) {
                        $studentgrades[] = [
                            'grade_item_id' => (int)$item->id,
                            'item_name' => $item->get_name(),
                            'finalgrade' => ($g->finalgrade !== null) ? (float)$g->finalgrade : null,
                            'feedback' => $g->feedback,
                            'locked' => (bool)$g->is_locked(),
                            'timemodified' => $g->timemodified ? (int)$g->timemodified : null,
                            'timemodified_iso' => $g->timemodified ? date('c', (int)$g->timemodified) : null,
                        ];
                    }
                }
            }

            return [
                'course_id' => $courseid,
                'student_user_id' => $studentid,
                'grades' => $studentgrades,
            ];
        }

        if ($action === 'update_grade') {
            require_capability('moodle/grade:edit', $context);

            $itemid = (int)($params['grade_item_id'] ?? 0);
            $studentid = (int)($params['student_user_id'] ?? 0);

            if (!$itemid || !$studentid) {
                throw new moodle_exception('missingparam', 'error', '', 'grade_item_id or student_user_id');
            }

            // H4 & M2: Validate student enrollment and group isolation.
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

            $gradeitem = \grade_item::fetch(['id' => $itemid, 'courseid' => $courseid]);
            if (!$gradeitem) {
                throw new moodle_exception('error_item_not_found', 'tool_teachermcp', '', null, 'Grade item not found.');
            }

            // H3 & H4: Lock checking.
            if ($gradeitem->is_locked()) {
                throw new moodle_exception('error_grade_locked', 'tool_teachermcp', '', null,
                    "Grade item '{$gradeitem->get_name()}' is locked and cannot be modified.");
            }

            $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $studentid]);
            if ($gradegrade && $gradegrade->is_locked()) {
                throw new moodle_exception('error_grade_locked', 'tool_teachermcp', '', null,
                    "Grade for this student on '{$gradeitem->get_name()}' is locked.");
            }

            $gradeval = null;

            // Handle scale value if provided (H3).
            if (!empty($params['scale_item']) && $gradeitem->scaleid) {
                $scale = $DB->get_record('scale', ['id' => $gradeitem->scaleid]);
                if ($scale) {
                    $scaleitems = array_map('trim', explode(',', $scale->scale));
                    $targetitem = trim($params['scale_item']);
                    $pos = array_search(strtolower($targetitem), array_map('strtolower', $scaleitems));
                    if ($pos !== false) {
                        // Scales are 1-based integers in Moodle gradelib.
                        $gradeval = (float)($pos + 1);
                    } else {
                        throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                            "Scale item '{$targetitem}' not found in scale '{$scale->name}'. Valid options: " . implode(', ', $scaleitems));
                    }
                }
            } else if (!empty($params['grade_letter'])) {
                // Handle letter grade conversion (H3).
                $letters = grade_get_letters($context);
                $targetletter = strtoupper(trim($params['grade_letter']));
                $boundary = array_search($targetletter, array_map('strtoupper', $letters), true);
                if ($boundary !== false) {
                    $percentage = (float)$boundary / 100.0;
                    $gradeval = (float)($gradeitem->grademin + ($percentage * ($gradeitem->grademax - $gradeitem->grademin)));
                } else {
                    throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                        "Letter grade '{$targetletter}' is not defined in this course gradebook.");
                }
            } else if (isset($params['final_grade'])) {
                $gradeval = (float)$params['final_grade'];
            }

            // H3 & H4: Range validation.
            if ($gradeval !== null) {
                if ($gradeval < $gradeitem->grademin || $gradeval > $gradeitem->grademax) {
                    throw new moodle_exception('error_grade_out_of_range', 'tool_teachermcp', '', null,
                        "Grade {$gradeval} is outside allowed range ({$gradeitem->grademin} to {$gradeitem->grademax}).");
                }
            }

            // H4: Pass false if omitted so existing value is preserved!
            $finalgradeparam = ($gradeval !== null) ? $gradeval : false;
            $feedbackparam = isset($params['feedback']) ? clean_text($params['feedback']) : false;

            $success = $gradeitem->update_final_grade($studentid, $finalgradeparam, 'teachermcp', $feedbackparam, FORMAT_HTML);
            if (!$success) {
                throw new moodle_exception('error_invalid_request', 'tool_teachermcp', '', null, 'Failed to update grade in gradebook.');
            }

            // Fetch live grade record.
            $updatedgrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $studentid]);

            return [
                'success' => true,
                'grade_item_id' => $itemid,
                'item_name' => $gradeitem->get_name(),
                'student_user_id' => $studentid,
                'updated_grade' => ($updatedgrade && $updatedgrade->finalgrade !== null) ? (float)$updatedgrade->finalgrade : null,
                'feedback' => $updatedgrade ? $updatedgrade->feedback : '',
            ];
        }

        throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', null, "Unsupported action: {$action}");
    }
}
