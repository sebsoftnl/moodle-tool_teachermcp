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
use context_user;
use tool_teachermcp\local\assignment_grading;

/**
 * MCP tool to view student submissions and submit grades, feedback, and files.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_submission extends base_tool {

    public function get_name(): string {
        return 'grade_submission';
    }

    public function get_description(): string {
        return 'View student assignment submissions and submit numeric/scale grades with feedback comments, marking workflow state, allocation, and optional feedback files.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'The course module ID of the assignment.',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: "list_submissions" or "save_grade".',
                    'enum' => ['list_submissions', 'save_grade'],
                ],
                'student_user_id' => [
                    'type' => 'integer',
                    'description' => 'Target student user ID (required for save_grade).',
                ],
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Optional group ID to filter or validate submissions.',
                ],
                'grade' => [
                    'type' => 'number',
                    'description' => 'Grade value to assign (e.g. 8.5 or 85). If omitted, existing grade is preserved.',
                ],
                'feedback_comment' => [
                    'type' => 'string',
                    'description' => 'Teacher feedback comment for the student.',
                ],
                'attemptnumber' => [
                    'type' => 'integer',
                    'description' => 'Submission attempt number (-1 for latest attempt, default: -1).',
                    'default' => -1,
                ],
                'applytoall' => [
                    'type' => 'boolean',
                    'description' => 'Whether to apply grade and feedback to all group members (for team submissions, default: true).',
                    'default' => true,
                ],
                'workflowstate' => [
                    'type' => 'string',
                    'description' => 'Marking workflow state (e.g. "notmarked", "inmarking", "readyforreview", "inreview", "readyforrelease", "released").',
                    'enum' => ['notmarked', 'inmarking', 'readyforreview', 'inreview', 'readyforrelease', 'released'],
                ],
                'allocatedmarker' => [
                    'type' => 'integer',
                    'description' => 'User ID of the allocated grader/marker (if marking allocation is enabled).',
                ],
                'feedback_files' => [
                    'type' => 'array',
                    'description' => 'List of feedback files to attach to this grade submission. Each item: {filename: string, content_base64: string}.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string', 'description' => 'Filename (e.g. annotated_essay.pdf)'],
                            'content_base64' => ['type' => 'string', 'description' => 'Base64-encoded file content.'],
                        ],
                        'required' => ['filename', 'content_base64'],
                    ],
                ],
            ],
            'required' => ['cmid', 'action'],
        ];
    }

    public function execute(array $params) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $cmid = (int)($params['cmid'] ?? 0);
        $action = $params['action'] ?? 'list_submissions';
        $targetgroupid = !empty($params['group_id']) ? (int)$params['group_id'] : 0;

        // H7 & M1: Enforce capability via base_tool helper.
        list($cm, $course, $context) = $this->require_cm_access($cmid, 'mod/assign:grade');

        $assign = new \assign($context, $cm, $course);
        $instance = $assign->get_instance();

        // M2: Group isolation.
        $groupmode = groups_get_activity_groupmode($cm);
        $canaccessallgroups = has_capability('moodle/site:accessallgroups', $context);
        $allowedgroups = groups_get_activity_allowed_groups($cm);
        $teachergroups = array_keys($allowedgroups);

        if ($groupmode == SEPARATEGROUPS && !$canaccessallgroups) {
            if ($targetgroupid > 0) {
                if (!in_array($targetgroupid, $teachergroups)) {
                    throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                }
            } else if (empty($teachergroups)) {
                throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
            }
        }

        if ($action === 'list_submissions') {
            $querygroup = $targetgroupid;
            if (!$querygroup && $groupmode == SEPARATEGROUPS && !$canaccessallgroups) {
                $querygroup = reset($teachergroups) ?: 0;
            }

            // Get enrolled students in group.
            $userfields = \core_user\fields::for_name()->including('id', 'email')->get_sql('u', false, '', '', false)->selects;
            $users = get_enrolled_users($context, 'mod/assign:submit', $querygroup, $userfields);

            $list = [];
            foreach ($users as $student) {
                $submission = $assign->get_user_submission($student->id, false);
                $grade = assignment_grading::read_grade($assign, (int)$student->id);
                $flags = $assign->get_user_flags($student->id, false);

                $status = $submission ? $submission->status : 'nosubmission';

                $list[] = [
                    'student_id' => (int)$student->id,
                    'fullname' => fullname($student),
                    'email' => $student->email,
                    'status' => $status,
                    'attemptnumber' => $submission ? (int)$submission->attemptnumber : -1,
                    'time_submitted' => ($submission && $submission->timemodified) ? (int)$submission->timemodified : null,
                    'time_submitted_iso' => ($submission && $submission->timemodified) ? date('c', $submission->timemodified) : null,
                    'current_grade' => ($grade && $grade->grade !== null && (float)$grade->grade >= 0) ? (float)$grade->grade : null,
                    'workflowstate' => $flags ? ($flags->workflowstate ?? null) : null,
                    'allocatedmarker' => $flags ? (int)($flags->allocatedmarker ?? 0) : 0,
                ];
            }

            return [
                'cmid' => $cmid,
                'assignment_name' => $instance->name,
                'total_students' => count($list),
                'submissions' => $list,
            ];
        }

        if ($action === 'save_grade') {
            $studentid = (int)($params['student_user_id'] ?? 0);
            if (!$studentid) {
                throw new moodle_exception('missingparam', 'error', '', 'student_user_id');
            }

            // H5 & M3: Check student enrolled and active.
            if (!is_enrolled($context, $studentid, '', true)) {
                throw new moodle_exception('error_not_enrolled', 'tool_teachermcp');
            }

            // M2: Group isolation for target student.
            if ($groupmode == SEPARATEGROUPS && !$canaccessallgroups) {
                $studentgroups = groups_get_user_groups($course->id, $studentid)[0] ?? [];
                if ($targetgroupid > 0) {
                    if (!in_array($targetgroupid, $studentgroups) || !in_array($targetgroupid, $teachergroups)) {
                        throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                    }
                } else {
                    if (empty(array_intersect($teachergroups, $studentgroups))) {
                        throw new moodle_exception('error_no_group_access', 'tool_teachermcp');
                    }
                }
            }

            $applytoall = !isset($params['applytoall']) || !empty($params['applytoall']);
            $gradeitem = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
                'iteminstance' => $instance->id, 'itemnumber' => 0]);
            $gradeuserids = [$studentid];
            if ($instance->teamsubmission && $applytoall) {
                $group = $assign->get_submission_group($studentid);
                $members = $assign->get_submission_group_members($group ? $group->id : 0, true, $assign->show_only_active_users());
                $gradeuserids = array_map(static fn($member) => (int)$member->id, $members);
            }
            foreach ($gradeuserids as $gradeuserid) {
                if ($gradeitem && $gradeitem->is_locked($gradeuserid)) {
                    throw new moodle_exception('error_grade_locked', 'tool_teachermcp');
                }
                $bookgrade = $gradeitem ? \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $gradeuserid]) : false;
                if (isset($params['grade']) && $bookgrade && $bookgrade->is_overridden()) {
                    throw new moodle_exception('error_grade_overridden', 'tool_teachermcp');
                }
            }
            $attemptnumber = isset($params['attemptnumber']) ? (int)$params['attemptnumber'] : -1;
            $currentgrade = assignment_grading::read_grade($assign, $studentid, $attemptnumber);

            // H5: Missing grade means preserve existing grade, not -1.
            if (isset($params['grade'])) {
                $gradeval = (float)$params['grade'];
                if ($instance->grade > 0) {
                    if ($gradeval < 0 || $gradeval > (float)$instance->grade) {
                        throw new moodle_exception('error_grade_out_of_range', 'tool_teachermcp', '', null,
                            "Grade {$gradeval} is outside allowed range (0 to {$instance->grade}).");
                    }
                } else if ($instance->grade < 0) {
                    $scaleid = -$instance->grade;
                    $scale = $DB->get_record('scale', ['id' => $scaleid]);
                    if ($scale) {
                        $scaleitems = array_map('trim', explode(',', $scale->scale));
                        $scaleint = (int)$gradeval;
                        if ($scaleint < 1 || $scaleint > count($scaleitems)) {
                            throw new moodle_exception('error_grade_out_of_range', 'tool_teachermcp', '', null,
                                "Scale grade {$gradeval} is outside allowed items (1 to " . count($scaleitems) . ").");
                        }
                    }
                }
            } else {
                $gradeval = ($currentgrade && $currentgrade->grade !== null) ? (float)$currentgrade->grade : -1.0;
            }

            $feedback = isset($params['feedback_comment']) ? clean_text($params['feedback_comment']) : '';
            $feedbackformat = FORMAT_HTML;
            if (!isset($params['feedback_comment']) && $currentgrade) {
                $commentsplugin = $assign->get_feedback_plugin_by_type('comments');
                if ($commentsplugin && $commentsplugin->is_enabled()) {
                    $previouscomments = $commentsplugin->get_feedback_comments($currentgrade->id);
                    if ($previouscomments) {
                        $feedback = $previouscomments->commenttext;
                        $feedbackformat = (int)$previouscomments->commentformat;
                    }
                }
            }
            $gradedata = new stdClass();
            $gradedata->grade = $gradeval;
            $gradedata->attemptnumber = $attemptnumber;
            $gradedata->addattempt = 0;
            $gradedata->applytoall = $applytoall ? 1 : 0;
            $gradedata->assignfeedbackcomments_editor = [
                'text' => $feedback,
                'format' => $feedbackformat,
            ];

            // Marking workflow and allocation.
            if (!empty($instance->markingworkflow) && !empty($params['workflowstate'])) {
                $workflowstate = clean_param($params['workflowstate'], PARAM_ALPHA);
                $gradedata->workflowstate = $workflowstate;
            }

            if (!empty($instance->markingallocation) && isset($params['allocatedmarker'])) {
                $allocatedmarker = (int)$params['allocatedmarker'];
                $gradedata->allocatedmarker = $allocatedmarker;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                if (!empty($params['feedback_files']) && is_array($params['feedback_files'])) {
                    $fileplugin = $assign->get_feedback_plugin_by_type('file');
                    if ($fileplugin && $fileplugin->is_enabled() && $fileplugin->is_visible()) {
                        $draftitemid = file_get_unused_draft_itemid();
                        $fs = get_file_storage();
                        $usercontext = context_user::instance($USER->id);

                        foreach ($params['feedback_files'] as $fbfile) {
                            $filename = clean_param($fbfile['filename'] ?? '', PARAM_FILE);
                            $contentbase64 = $fbfile['content_base64'] ?? '';
                            if (!empty($filename) && !empty($contentbase64)) {
                                $decoded = base64_decode($contentbase64, true);
                                if ($decoded !== false) {
                                    $filerecord = [
                                        'contextid' => $usercontext->id,
                                        'component' => 'user',
                                        'filearea' => 'draft',
                                        'itemid' => $draftitemid,
                                        'filepath' => '/',
                                        'filename' => $filename,
                                    ];
                                    $fs->create_file_from_string($filerecord, $decoded);
                                }
                            }
                        }
                        $gradedata->files_filemanager = $draftitemid;
                    }
                }

                if (!$assign->save_grade($studentid, $gradedata)) {
                    throw new moodle_exception('error_grade_not_saved', 'tool_teachermcp');
                }
                $outcomes = [];
                foreach ($gradeuserids as $gradeuserid) {
                    $saved = assignment_grading::read_grade($assign, $gradeuserid, $attemptnumber);
                    if (!$saved || (isset($params['grade']) && !grade_floats_equal($saved->grade, $gradeval))) {
                        throw new moodle_exception('error_grade_not_saved', 'tool_teachermcp');
                    }
                    $bookgrade = $gradeitem ? \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $gradeuserid]) : false;
                    $outcomes[$gradeuserid] = [
                        'student_user_id' => $gradeuserid,
                        'attemptnumber' => (int)$saved->attemptnumber,
                        'grade_saved' => $saved->grade !== null && (float)$saved->grade >= 0 ? (float)$saved->grade : null,
                        'gradebook' => [
                            'grade_item_id' => $gradeitem ? (int)$gradeitem->id : null,
                            'finalgrade' => $bookgrade && $bookgrade->finalgrade !== null ? (float)$bookgrade->finalgrade : null,
                            'overridden' => $bookgrade ? $bookgrade->is_overridden() : false,
                            'locked' => $gradeitem ? $gradeitem->is_locked($gradeuserid) : false,
                        ],
                    ];
                }
                $target = $outcomes[$studentid];
                $saved = assignment_grading::read_grade($assign, $studentid, $attemptnumber);
                $commentsplugin = $assign->get_feedback_plugin_by_type('comments');
                $comments = $commentsplugin && $commentsplugin->is_enabled()
                    ? $commentsplugin->get_feedback_comments($saved->id) : false;
                $flags = $assign->get_user_flags($studentid, false);
                $result = [
                    'success' => true,
                    'cmid' => $cmid,
                    'student_user_id' => $studentid,
                    'attemptnumber' => $target['attemptnumber'],
                    'grade_saved' => $target['grade_saved'],
                    'feedback' => $comments ? $comments->commenttext : '',
                    'workflowstate' => $flags->workflowstate ?? null,
                    'gradebook' => $target['gradebook'],
                    'affected_students' => array_values($outcomes),
                ];
                foreach ($outcomes as $outcome) {
                    if ($outcome['gradebook']['overridden'] ||
                            !grade_floats_equal($outcome['grade_saved'], $outcome['gradebook']['finalgrade'])) {
                        $result['warning'] = get_string('warning_gradebook_differs', 'tool_teachermcp');
                        break;
                    }
                }
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }

        }

        throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', null, "Unsupported action: {$action}");
    }
}
