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
use context_course;

/**
 * MCP tool to detect students at risk of falling behind or dropping out.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class detect_at_risk_students extends base_tool {

    public function get_name(): string {
        return 'detect_at_risk_students';
    }

    public function get_description(): string {
        return 'Compute an early-warning risk index for students in a course based on days inactive, overdue assignments, failing grades, missing activity completions, and Moodle Analytics predictions.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle course ID to analyze.',
                ],
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Optional group ID to filter student evaluation to a specific group.',
                ],
                'inactivity_days_threshold' => [
                    'type' => 'integer',
                    'description' => 'Number of days of inactivity to flag (default: 7).',
                    'default' => 7,
                ],
                'grade_warning_percent' => [
                    'type' => 'number',
                    'description' => 'Grade percentage threshold below which a student is flagged (default: 55.0).',
                    'default' => 55.0,
                ],
                'grade_warning_threshold' => [
                    'type' => 'number',
                    'description' => 'Absolute grade threshold (legacy/fallback if specified).',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Max number of at-risk students to return (default: 25).',
                    'default' => 25,
                ],
            ],
            'required' => ['course_id'],
        ];
    }

    public function execute(array $params) {
        global $DB, $CFG, $USER;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $inactthreshold = (int)($params['inactivity_days_threshold'] ?? 7);
        $gradewarningpercent = isset($params['grade_warning_percent']) ? (float)$params['grade_warning_percent'] : 55.0;
        $maxresults = (int)($params['max_results'] ?? 25);
        $groupid = isset($params['group_id']) ? (int)$params['group_id'] : 0;

        // H7 & M1: Enforce capability via base_tool helper.
        $course = $this->require_course_access($courseid, 'moodle/grade:viewall');
        $context = context_course::instance($course->id);

        // Fetch course grade item and calculate threshold.
        $courseitem = \grade_item::fetch_course_item($course->id);
        if (isset($params['grade_warning_threshold'])) {
            $gradethreshold = (float)$params['grade_warning_threshold'];
        } else if ($courseitem && (float)$courseitem->grademax > 0) {
            $gradethreshold = (float)$courseitem->grademax * ($gradewarningpercent / 100.0);
        } else {
            $gradethreshold = 5.5;
        }

        // M2 & L4: Enforce group isolation for separate groups.
        $targetgroupids = null;
        if ($course->groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $usergroups = groups_get_user_groups($course->id, $USER->id);
            $allowedgroupids = $usergroups[0] ?? [];

            if ($groupid > 0) {
                if (!in_array($groupid, $allowedgroupids)) {
                    throw new moodle_exception('error_group_access_denied', 'tool_teachermcp');
                }
                $targetgroupids = [$groupid];
            } else {
                $targetgroupids = $allowedgroupids;
            }

            if (empty($targetgroupids)) {
                return [
                    'course_id' => $courseid,
                    'total_students_evaluated' => 0,
                    'total_at_risk' => 0,
                    'thresholds' => [
                        'inactivity_days' => $inactthreshold,
                        'grade_warning_percent' => $gradewarningpercent,
                        'effective_grade_warning' => $gradethreshold,
                    ],
                    'at_risk_students' => [],
                ];
            }
        } else if ($groupid > 0) {
            if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $course->id])) {
                throw new moodle_exception('error_group_access_denied', 'tool_teachermcp');
            }
            $targetgroupids = [$groupid];
        }

        $now = time();
        $completion = new \completion_info($course);
        $hascompletion = $completion->is_enabled();

        // L4: Batch modinfo outside of loop.
        $modinfo = get_fast_modinfo($course);

        // Get active learners only, strictly filtered by allowed groups if separate groups or group_id specified.
        $userfields = \core_user\fields::for_name()->including('id', 'email')->get_sql('u', false, '', '', false)->selects;
        if ($targetgroupids !== null) {
            $students = get_enrolled_users($context, '', $targetgroupids, $userfields, '', 0, 0, true);
        } else {
            $students = get_enrolled_users($context, '', 0, $userfields, '', 0, 0, true);
        }

        foreach ($students as $userid => $student) {
            if (has_capability('moodle/grade:viewall', $context, $userid)) {
                unset($students[$userid]);
            }
        }

        if (empty($students)) {
            return [
                'course_id' => $courseid,
                'total_students_evaluated' => 0,
                'total_at_risk' => 0,
                'thresholds' => [
                    'inactivity_days' => $inactthreshold,
                    'grade_warning_percent' => $gradewarningpercent,
                    'effective_grade_warning' => $gradethreshold,
                ],
                'at_risk_students' => [],
            ];
        }

        $studentids = array_keys($students);
        list($stduidsql, $stduidparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);

        // L4: Batch fetch user_lastaccess outside loop.
        $lastaccessparams = array_merge(['cid' => $course->id], $stduidparams);
        $lastaccessrecords = $DB->get_records_select_menu('user_lastaccess', "courseid = :cid AND userid $stduidsql", $lastaccessparams, '', 'userid, timeaccess');

        // L4: Fetch assignments and check overrides/extensions.
        $assigns = $DB->get_records_select('assign', 'course = :cid AND duedate > 0', ['cid' => $course->id], '', 'id, name, duedate, teamsubmission');
        $submissionsmap = [];
        $extensionsmap = [];
        $overridesmap = [];

        if (!empty($assigns)) {
            $assignids = array_keys($assigns);
            list($aidsql, $aidparams) = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED);

            // Submissions.
            $subsql = "SELECT " . $DB->sql_concat('assignment', "'-'", 'userid') . " AS k, id, status
                         FROM {assign_submission}
                        WHERE assignment $aidsql AND status = 'submitted' AND userid $stduidsql";
            $submissionsmap = $DB->get_records_sql_menu($subsql, array_merge($aidparams, $stduidparams));

            // User flags (extensions).
            $extsql = "SELECT " . $DB->sql_concat('assignment', "'-'", 'userid') . " AS k, extensionduedate
                         FROM {assign_user_flags}
                        WHERE assignment $aidsql AND extensionduedate > 0 AND userid $stduidsql";
            $extensionsmap = $DB->get_records_sql_menu($extsql, array_merge($aidparams, $stduidparams));

            // Assign overrides.
            if ($DB->get_manager()->table_exists('assign_overrides')) {
                $ovrsql = "SELECT " . $DB->sql_concat('assignid', "'-'", 'userid') . " AS k, duedate
                             FROM {assign_overrides}
                            WHERE assignid $aidsql AND userid $stduidsql";
                $overridesmap = $DB->get_records_sql_menu($ovrsql, array_merge($aidparams, $stduidparams));
            }
        }

        // Batch grades for students.
        $gradesmap = [];
        if ($courseitem) {
            $gradesql = "SELECT userid, finalgrade
                           FROM {grade_grades}
                          WHERE itemid = :itemid AND userid $stduidsql AND finalgrade IS NOT NULL";
            $gradesmap = $DB->get_records_sql_menu($gradesql, array_merge(['itemid' => $courseitem->id], $stduidparams));
        }

        $hasanalyticstable = $DB->get_manager()->table_exists('analytics_predictions');
        $predictionsmap = [];
        if ($hasanalyticstable) {
            $predsql = "SELECT sampleid, predictionscore
                          FROM {analytics_predictions}
                         WHERE contextid = :contextid AND prediction = 1 AND sampleid $stduidsql";
            $predictionsmap = $DB->get_records_sql_menu($predsql, array_merge(['contextid' => $context->id], $stduidparams));
        }

        $atrisk = [];

        foreach ($students as $student) {

            $riskpoints = 0;
            $flags = [];

            // 1. Inactivity check.
            $lastaccess = $lastaccessrecords[$student->id] ?? 0;
            $daysinactive = $lastaccess ? (int)(($now - $lastaccess) / 86400) : 999;

            if ($daysinactive >= $inactthreshold) {
                $severity = min(40, (int)($daysinactive * 2));
                $riskpoints += $severity;
                $flags[] = [
                    'code' => 'inactive_days',
                    'message' => get_string('flag_inactivity', 'tool_teachermcp', $daysinactive),
                    'days' => $daysinactive,
                ];
            }

            // 2. Overdue assignments without submission.
            $overduecount = 0;
            foreach ($assigns as $as) {
                $userduedate = $overridesmap[$as->id . '-' . $student->id] ?? $as->duedate;
                $extension = $extensionsmap[$as->id . '-' . $student->id] ?? 0;
                $effectiveduedate = max($userduedate, $extension);

                if ($effectiveduedate > 0 && $effectiveduedate < $now) {
                    if (empty($submissionsmap[$as->id . '-' . $student->id])) {
                        $overduecount++;
                    }
                }
            }

            if ($overduecount > 0) {
                $riskpoints += min(30, $overduecount * 15);
                $flags[] = [
                    'code' => 'overdue_assignments',
                    'message' => get_string('flag_overdue_assignments', 'tool_teachermcp', $overduecount),
                    'count' => $overduecount,
                ];
            }

            // 3. Low or failing grades.
            $currentgrade = null;
            if (isset($gradesmap[$student->id])) {
                $currentgrade = round((float)$gradesmap[$student->id], 1);
                if ($currentgrade < $gradethreshold) {
                    $riskpoints += 30;
                    $flags[] = [
                        'code' => 'low_grade',
                        'message' => get_string('flag_low_grade', 'tool_teachermcp', $currentgrade),
                        'grade' => $currentgrade,
                    ];
                }
            }

            // 4. Incomplete activities.
            if ($hascompletion) {
                $incompletecount = 0;
                foreach ($modinfo->cms as $cm) {
                    if ($cm->completion > 0 && $cm->uservisible) {
                        $cdata = $completion->get_data($cm, false, $student->id);
                        if ($cdata->completionstate == COMPLETION_INCOMPLETE) {
                            $incompletecount++;
                        }
                    }
                }
                if ($incompletecount >= 3) {
                    $riskpoints += 15;
                    $flags[] = [
                        'code' => 'incomplete_activities',
                        'message' => get_string('flag_incomplete_activities', 'tool_teachermcp', $incompletecount),
                        'count' => $incompletecount,
                    ];
                }
            }

            // 5. Analytics risk prediction.
            if (isset($predictionsmap[$student->id])) {
                $scorepct = round((float)$predictionsmap[$student->id] * 100);
                $riskpoints += 25;
                $flags[] = [
                    'code' => 'analytics_risk',
                    'message' => get_string('flag_analytics_risk', 'tool_teachermcp', $scorepct),
                    'score_percent' => $scorepct,
                ];
            }

            if ($riskpoints >= 20) {
                $flagmessages = array_map(function($f) {
                    return $f['message'];
                }, $flags);

                $atrisk[] = [
                    'student_id' => (int)$student->id,
                    'fullname' => fullname($student),
                    'email' => $student->email,
                    'risk_score' => min(100, $riskpoints),
                    'risk_level' => ($riskpoints >= 60) ? 'HIGH' : (($riskpoints >= 40) ? 'MEDIUM' : 'LOW'),
                    'days_inactive' => $daysinactive,
                    'current_course_grade' => $currentgrade,
                    'risk_flags' => $flags,
                    'risk_factors' => $flagmessages,
                ];
            }
        }

        // Sort descending by risk score.
        usort($atrisk, function($a, $b) {
            return $b['risk_score'] <=> $a['risk_score'];
        });

        $trimmed = array_slice($atrisk, 0, $maxresults);

        return [
            'course_id' => $courseid,
            'total_students_evaluated' => count($students),
            'total_at_risk' => count($atrisk),
            'thresholds' => [
                'inactivity_days' => $inactthreshold,
                'grade_warning_percent' => $gradewarningpercent,
                'effective_grade_warning' => $gradethreshold,
            ],
            'at_risk_students' => $trimmed,
        ];
    }
}
