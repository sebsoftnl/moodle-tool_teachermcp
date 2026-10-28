<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/teaching_fixtures.php');

use tool_teachermcp\tests\fixtures\teaching_fixtures as fixtures;
use tool_teachermcp\tools\detect_at_risk_students;
use tool_teachermcp\tools\grade_submission;
use tool_teachermcp\tools\manage_grades;
use tool_teachermcp\tools\prepare_grading_session;

/**
 * Regression contracts for truthful grading and native risk ranges.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grading_risk_regressions_test extends \advanced_testcase {
    public function test_listing_submissions_is_read_only_even_without_an_attempt(): void {
        [$course, , $student] = $this->fixture();
        foreach ([0, 1] as $team) {
            $assign = fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS,
                'teamsubmission' => $team]);
            $before = fixtures::snapshot();
            for ($i = 0; $i < 2; $i++) {
                $result = (new grade_submission())->execute(['cmid' => (int)$assign->get_course_module()->id,
                    'action' => 'list_submissions']);
                $row = array_column($result['submissions'], null, 'student_id')[$student->id];
                $this->assertSame('nosubmission', $row['status']);
                $this->assertSame(-1, $row['attemptnumber']);
                $this->assertNull($row['time_submitted']);
                $this->assertEquals($before, fixtures::snapshot(), 'Reading submissions must not create individual or team attempts');
            }
        }
    }

    public function test_rejected_first_grade_does_not_create_submission_or_support_records(): void {
        [$course, , $student] = $this->fixture();
        foreach ([0, 1] as $team) {
            $assign = fixtures::assignment($this->getDataGenerator(), $course,
                ['groupmode' => NOGROUPS, 'grade' => 10, 'teamsubmission' => $team]);
            $before = fixtures::snapshot();
            try {
                (new grade_submission())->execute(['cmid' => (int)$assign->get_course_module()->id,
                    'action' => 'save_grade', 'student_user_id' => (int)$student->id, 'grade' => 11,
                    'applytoall' => false, 'feedback_comment' => 'Do not save',
                    'feedback_files' => [['filename' => 'reject.txt', 'content_base64' => base64_encode('Rejected')]]]);
                $this->fail('Out-of-range first grade must be rejected');
            } catch (\moodle_exception $error) {
                $this->assertSame('error_grade_out_of_range', $error->errorcode);
            }
            $this->assertEquals($before, fixtures::snapshot(), 'Rejected grading must leave attempts, timestamps, grades and files unchanged');
        }
    }

    public function test_preparation_and_explicit_attempt_reads_do_not_create_new_attempts(): void {
        global $DB;
        [$course, , $student] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        fixtures::submit($assign, $student, '<p>Existing submission</p>');
        $before = fixtures::snapshot();
        $result = (new prepare_grading_session())->execute(['cmid' => (int)$assign->get_course_module()->id]);
        $this->assertSame(1, $result['total_in_batch']);
        $this->assertEquals($before, fixtures::snapshot());
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$student->id, 'applytoall' => false];
        (new grade_submission())->execute($args + ['grade' => 65, 'attemptnumber' => 0]);
        $this->assertSame(1, $DB->count_records('assign_submission', ['assignment' => $assign->get_instance()->id]));
    }

    public function test_overridden_numeric_grade_rejects_without_false_success_or_partial_writes(): void {
        [$course, , $student] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$student->id, 'applytoall' => false];
        (new grade_submission())->execute($args + ['grade' => 87, 'feedback_comment' => 'Original']);
        $item = $assign->get_grade_item();
        $item->update_final_grade($student->id, 89, 'test', 'Override', FORMAT_HTML);
        $before = fixtures::snapshot();
        try {
            (new grade_submission())->execute($args + ['grade' => 91, 'feedback_comment' => 'Must not be saved']);
            $this->fail('An overridden assignment grade must not report a successful numeric save');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_grade_overridden', $error->errorcode);
        }
        $this->assertEquals($before, fixtures::snapshot(), 'Override rejection must preserve all grading state');
    }

    public function test_saved_grade_and_feedback_only_response_expose_actual_gradebook_state(): void {
        [$course, , $student] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$student->id, 'applytoall' => false];
        $saved = (new grade_submission())->execute($args + ['grade' => 87, 'feedback_comment' => 'Original']);
        $this->assertSame(87.0, $saved['grade_saved']);
        $this->assertSame(87.0, $saved['gradebook']['finalgrade']);
        $this->assertFalse($saved['gradebook']['overridden']);
        $this->assertFalse($saved['gradebook']['locked']);
        $this->assertSame(0, $saved['attemptnumber']);
        $assign->get_grade_item()->update_final_grade($student->id, 89, 'test', 'Override', FORMAT_HTML);
        $feedback = (new grade_submission())->execute($args + ['feedback_comment' => 'Feedback only']);
        $this->assertSame(87.0, $feedback['grade_saved']);
        $this->assertSame(89.0, $feedback['gradebook']['finalgrade']);
        $this->assertTrue($feedback['gradebook']['overridden']);
        $this->assertNotEmpty($feedback['warning']);
        $read = (new manage_grades())->execute(['course_id' => (int)$course->id,
            'action' => 'get_student_grades', 'student_user_id' => (int)$student->id]);
        $row = array_column($read['grades'], null, 'grade_item_id')[$assign->get_grade_item()->id];
        $this->assertTrue($row['overridden']);
    }

    public function test_team_override_prevents_partial_numeric_grading(): void {
        [$course, , $student] = $this->fixture();
        $generator = $this->getDataGenerator();
        $other = $generator->create_user();
        $generator->enrol_user($other->id, $course->id, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        foreach ([$student, $other] as $member) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        }
        $assign = fixtures::assignment($generator, $course, ['groupmode' => NOGROUPS,
            'teamsubmission' => 1, 'requireallteammemberssubmit' => 0]);
        fixtures::submit($assign, $student, 'Team answer');
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$student->id];
        (new grade_submission())->execute($args + ['grade' => 70]);
        $assign->get_grade_item()->update_final_grade($other->id, 90, 'test');
        $before = fixtures::snapshot();
        try {
            (new grade_submission())->execute($args + ['grade' => 80]);
            $this->fail('A teammate override must prevent partial group grading');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_grade_overridden', $error->errorcode);
        }
        $this->assertEquals($before, fixtures::snapshot());
        $single = (new grade_submission())->execute($args + ['grade' => 80, 'applytoall' => false]);
        $this->assertSame(80.0, $single['grade_saved']);
    }

    public function test_risk_uses_student_range_for_ungraded_hidden_future_work(): void {
        [$course, , $student] = $this->fixture();
        $this->risk_items($course, $student, GRADE_AGGREGATE_SUM, true);
        $result = (new detect_at_risk_students())->execute(['course_id' => (int)$course->id]);
        $row = array_column($result['at_risk_students'], null, 'student_id')[$student->id];
        $this->assertNotContains('low_grade', array_column($row['risk_flags'], 'code'), 'Ungraded work must not turn 80/100 into 80/400');
        $this->assertSame(100.0, $row['grade_assessment']['maximum']);
        $this->assertSame(80.0, $row['grade_assessment']['percentage']);
        $this->assertSame(55.0, $row['grade_assessment']['warning_threshold']);
        $this->assertNull($result['thresholds']['effective_grade_warning'], 'A variable per-student threshold must not be presented as one global value');
    }

    public function test_risk_respects_including_empty_grades_and_explicit_absolute_threshold(): void {
        [$course, , $student] = $this->fixture();
        $this->risk_items($course, $student, GRADE_AGGREGATE_SUM, false);
        $tool = new detect_at_risk_students();
        $result = $tool->execute(['course_id' => (int)$course->id]);
        $row = array_column($result['at_risk_students'], null, 'student_id')[$student->id];
        $this->assertContains('low_grade', array_column($row['risk_flags'], 'code'));
        $this->assertSame(400.0, $row['grade_assessment']['maximum']);
        $this->assertSame(20.0, $row['grade_assessment']['percentage']);
        $absolute = $tool->execute(['course_id' => (int)$course->id, 'grade_warning_threshold' => 70]);
        $row = array_column($absolute['at_risk_students'], null, 'student_id')[$student->id];
        $this->assertNotContains('low_grade', array_column($row['risk_flags'], 'code'));
        $this->assertSame(70.0, $absolute['thresholds']['effective_grade_warning']);
    }

    public function test_weighted_risk_range_matches_native_gradebook(): void {
        [$course, , $student] = $this->fixture();
        $this->risk_items($course, $student, GRADE_AGGREGATE_WEIGHTED_MEAN, true);
        $item = \grade_item::fetch_course_item($course->id);
        $native = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $result = (new detect_at_risk_students())->execute(['course_id' => (int)$course->id]);
        $row = array_column($result['at_risk_students'], null, 'student_id')[$student->id];
        $this->assertSame((float)$native->get_grade_min(), $row['grade_assessment']['minimum']);
        $this->assertSame((float)$native->get_grade_max(), $row['grade_assessment']['maximum']);
        $this->assertSame(80.0, $row['grade_assessment']['percentage']);
        $this->assertNotContains('low_grade', array_column($row['risk_flags'], 'code'));
    }

    public function test_zero_width_and_missing_course_grades_do_not_invent_percentages(): void {
        global $DB;
        [$course, , $student] = $this->fixture();
        $tool = new detect_at_risk_students();
        $empty = $tool->execute(['course_id' => (int)$course->id]);
        $row = array_column($empty['at_risk_students'], null, 'student_id')[$student->id];
        $this->assertNull($row['grade_assessment']);
        $this->risk_items($course, $student, GRADE_AGGREGATE_SUM, true);
        $item = \grade_item::fetch_course_item($course->id);
        $DB->set_field('grade_grades', 'rawgrademax', 0, ['itemid' => $item->id, 'userid' => $student->id]);
        $DB->set_field('grade_grades', 'finalgrade', 0, ['itemid' => $item->id, 'userid' => $student->id]);
        $result = $tool->execute(['course_id' => (int)$course->id]);
        $row = array_column($result['at_risk_students'], null, 'student_id')[$student->id];
        $this->assertNull($row['grade_assessment']['percentage']);
        $this->assertNotContains('low_grade', array_column($row['risk_flags'], 'code'));
    }

    private function fixture(): array {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 0]);
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('user_lastaccess', ['userid' => $student->id, 'courseid' => $course->id,
            'timeaccess' => time() - 10 * DAYSECS]);
        $this->setUser($teacher);
        return [$course, $teacher, $student];
    }

    private function risk_items(\stdClass $course, \stdClass $student, int $aggregation, bool $onlygraded): void {
        $category = \grade_category::fetch_course_category($course->id);
        $category->aggregation = $aggregation;
        $category->aggregateonlygraded = $onlygraded ? 1 : 0;
        $category->update();
        $graded = new \grade_item((object)['courseid' => $course->id, 'itemtype' => 'manual',
            'itemname' => 'Assessed work', 'gradetype' => GRADE_TYPE_VALUE, 'grademin' => 0, 'grademax' => 100,
            'aggregationcoef' => $aggregation === GRADE_AGGREGATE_WEIGHTED_MEAN ? 1 : 0], false);
        $graded->insert();
        $ungraded = new \grade_item((object)['courseid' => $course->id, 'itemtype' => 'manual',
            'itemname' => 'Hidden future work', 'gradetype' => GRADE_TYPE_VALUE, 'grademin' => 0, 'grademax' => 300,
            'hidden' => 1, 'aggregationcoef' => $aggregation === GRADE_AGGREGATE_WEIGHTED_MEAN ? 3 : 0], false);
        $ungraded->insert();
        $graded->update_final_grade($student->id, 80, 'test');
        grade_regrade_final_grades($course->id);
    }
}
