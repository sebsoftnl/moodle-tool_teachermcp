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

/**
 * MCP tool to analyze class understanding, misconception patterns, and question difficulty on completed quizzes.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_class_understanding extends base_tool {

    public function get_name(): string {
        return 'analyze_class_understanding';
    }

    public function get_description(): string {
        return 'Analyze aggregate student performance, question difficulty (facility index), common errors, and learning gaps on a completed Quiz.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'The course module ID of the quiz.',
                ],
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Optional group ID to filter quiz attempts by.',
                ],
            ],
            'required' => ['cmid'],
        ];
    }

    public function execute(array $params) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $cmid = (int)($params['cmid'] ?? 0);
        $groupid = isset($params['group_id']) ? (int)$params['group_id'] : 0;
        list($cm, $course, $context) = $this->require_cm_access($cmid, 'mod/quiz:viewreports');

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        // M2 & L4: Enforce group isolation for separate groups.
        $isseparategroups = ($course->groupmode == SEPARATEGROUPS || groups_get_activity_groupmode($cm) == SEPARATEGROUPS);
        $canaccessall = has_capability('moodle/site:accessallgroups', $context);
        $targetgroupids = null;

        if ($isseparategroups && !$canaccessall) {
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
                    'cmid' => $cmid,
                    'quiz_name' => $quiz->name,
                    'total_students_attempted' => 0,
                    'total_finished_attempts' => 0,
                    'total_attempts' => 0,
                    'message' => 'No finished student attempts found for this quiz yet.',
                ];
            }
        } else if ($groupid > 0) {
            if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $course->id])) {
                throw new moodle_exception('error_group_access_denied', 'tool_teachermcp');
            }
            $targetgroupids = [$groupid];
        }

        // L5: Use \mod_quiz\quiz_attempt::FINISHED.
        $finishedstate = class_exists('\mod_quiz\quiz_attempt') ? \mod_quiz\quiz_attempt::FINISHED : \quiz_attempt::FINISHED;

        // Fetch completed attempts (strictly filtered by allowed groups if separate groups or group_id specified).
        if ($targetgroupids !== null) {
            list($ginsql, $gparams) = $DB->get_in_or_equal($targetgroupids, SQL_PARAMS_NAMED);
            $groupmemberids = $DB->get_fieldset_select('groups_members', 'userid', "groupid $ginsql", $gparams);
            $groupmemberids = array_values(array_unique($groupmemberids));
            if (empty($groupmemberids)) {
                return [
                    'cmid' => $cmid,
                    'quiz_name' => $quiz->name,
                    'total_students_attempted' => 0,
                    'total_finished_attempts' => 0,
                    'total_attempts' => 0,
                    'message' => 'No finished student attempts found for this quiz yet.',
                ];
            }
            list($uidsql, $uidparams) = $DB->get_in_or_equal($groupmemberids, SQL_PARAMS_NAMED);
            $attempts = $DB->get_records_select(
                'quiz_attempts',
                "quiz = :qid AND state = :st AND preview = 0 AND userid $uidsql",
                array_merge(['qid' => $quiz->id, 'st' => $finishedstate], $uidparams),
                'id ASC'
            );
        } else {
            $attempts = $DB->get_records_select('quiz_attempts', 'quiz = :qid AND state = :st AND preview = 0', [
                'qid' => $quiz->id,
                'st' => $finishedstate,
            ], 'id ASC');
        }

        if (empty($attempts)) {
            return [
                'cmid' => $cmid,
                'quiz_name' => $quiz->name,
                'total_students_attempted' => 0,
                'total_finished_attempts' => 0,
                'total_attempts' => 0,
                'message' => 'No finished student attempts found for this quiz yet.',
            ];
        }

        $scores = [];
        foreach ($attempts as $att) {
            if ($att->sumgrades !== null) {
                $scores[] = (float)$att->sumgrades;
            }
        }

        $attemptcount = count($scores);
        $avgscore = $attemptcount > 0 ? array_sum($scores) / $attemptcount : 0;
        $maxscore = $attemptcount > 0 ? max($scores) : 0;
        $minscore = $attemptcount > 0 ? min($scores) : 0;

        // L5: Use modern quiz structure to get accurate question versions.
        $quizsettings = \mod_quiz\quiz_settings::create($quiz->id);
        $structure = $quizsettings->get_structure();
        $slots = $structure->get_slots();

        $questionsbreakdown = [];
        $attemptids = array_keys($attempts);
        list($inatt, $inparams) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);

        foreach ($slots as $slot) {
            $q = $structure->get_question_in_slot($slot->slot);
            $qname = $structure->get_question_name_in_slot($slot->slot);
            $qtype = $structure->get_question_type_for_slot($slot->slot);
            $qtext = $q ? shorten_text(strip_tags($q->questiontext), 100) : '';

            $qaquery = "
                SELECT qa.fraction
                  FROM {question_attempts} qa
                  JOIN {quiz_attempts} quiza ON quiza.uniqueid = qa.questionusageid
                 WHERE quiza.id $inatt AND qa.slot = :slot
            ";
            $slotparams = array_merge($inparams, ['slot' => $slot->slot]);

            $fractions = $DB->get_fieldset_sql($qaquery, $slotparams);
            $correctcount = 0;
            $wrongcount = 0;
            $partialcount = 0;
            $needsgradingcount = 0;

            foreach ($fractions as $f) {
                if ($f === null) {
                    $needsgradingcount++;
                    continue;
                }
                $fval = (float)$f;
                if ($fval >= 0.99) {
                    $correctcount++;
                } else if ($fval > 0.0) {
                    $partialcount++;
                } else {
                    $wrongcount++;
                }
            }

            $gradedanswers = $correctcount + $partialcount + $wrongcount;
            $facilitypct = ($gradedanswers > 0) ? round(($correctcount / $gradedanswers) * 100, 1) : 0;

            $questionsbreakdown[] = [
                'slot' => (int)$slot->slot,
                'question_name' => $qname,
                'question_text' => $qtext,
                'question_type' => $qtype,
                'max_mark' => (float)$slot->maxmark,
                'facility_index_percent' => $facilitypct,
                'correct_answers' => $correctcount,
                'partial_answers' => $partialcount,
                'wrong_answers' => $wrongcount,
                'needs_grading' => $needsgradingcount,
                'status' => ($facilitypct < 50) ? 'DIFFICULT_NEEDS_ATTENTION' : (($facilitypct >= 85) ? 'WELL_UNDERSTOOD' : 'MODERATE'),
            ];
        }

        // Sort questions to identify biggest learning gaps first.
        usort($questionsbreakdown, function($a, $b) {
            return $a['facility_index_percent'] <=> $b['facility_index_percent'];
        });

        return [
            'cmid' => $cmid,
            'quiz_name' => $quiz->name,
            'total_students_attempted' => count(array_unique(array_column($attempts, 'userid'))),
            'total_finished_attempts' => $attemptcount,
            'grade_scale_maximum' => (float)$quiz->sumgrades,
            'metrics' => [
                'average_score' => round($avgscore, 2),
                'highest_score' => round($maxscore, 2),
                'lowest_score' => round($minscore, 2),
            ],
            'questions_analysis' => $questionsbreakdown,
        ];
    }
}
