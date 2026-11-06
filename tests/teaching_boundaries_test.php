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

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/teaching_fixtures.php');

use tool_teachermcp\tests\fixtures\teaching_fixtures as fixtures;
use tool_teachermcp\tools\analyze_class_understanding;
use tool_teachermcp\tools\detect_at_risk_students;
use tool_teachermcp\tools\get_group_students;
use tool_teachermcp\tools\grade_submission;
use tool_teachermcp\tools\manage_course_calendar;
use tool_teachermcp\tools\manage_course_completion;
use tool_teachermcp\tools\manage_grades;
use tool_teachermcp\tools\prepare_grading_session;
use tool_teachermcp\tools\send_student_message;

/**
 * Native teaching permission, grading and populated-analytics boundaries.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teaching_boundaries_test extends \advanced_testcase {
    public function test_letter_grades_use_native_percentage_boundaries_including_zero(): void {
        global $DB;
        [$course, , $students] = $this->fixture();
        $context = \context_course::instance($course->id);
        foreach ([75 => 'B', 0 => 'F'] as $boundary => $letter) {
            $DB->insert_record('grade_letters', ['contextid' => $context->id, 'lowerboundary' => $boundary, 'letter' => $letter]);
        }
        \cache::make('core', 'grade_letters')->delete($context->id);
        $this->assertSame(['B', 'F'], array_values(grade_get_letters($context)));
        $item = $this->manual_item($course);
        $args = ['course_id' => (int)$course->id, 'action' => 'update_grade', 'grade_item_id' => (int)$item->id,
            'student_user_id' => (int)$students['alpha']->id];
        $tool = new manage_grades();
        $outcomes = [];
        foreach ([' b ', 'F'] as $letter) {
            try {
                $outcomes[] = $tool->execute($args + ['grade_letter' => $letter])['updated_grade'];
            } catch (\moodle_exception $error) {
                $outcomes[] = $error->errorcode;
            }
        }
        $this->assert_unchanged(fn() => $tool->execute($args + ['grade_letter' => 'ZZ']), 'error_invalid_params');
        $this->assertSame([65.0, 20.0], $outcomes, 'Native letters map percentage boundaries to names, including boundary zero.');
    }

    public function test_assignment_item_lock_rejects_before_feedback_and_draft_writes(): void {
        $this->assert_assignment_lock('item');
    }

    public function test_assignment_student_lock_rejects_before_feedback_and_draft_writes(): void {
        $this->assert_assignment_lock('student');
    }

    public function test_team_locked_member_prevents_partial_applytoall_writes(): void {
        $this->assert_assignment_lock('team');
    }

    public function test_gradebook_item_lock_preserves_grade_and_feedback(): void {
        $this->assert_gradebook_lock(true);
    }

    public function test_gradebook_student_lock_preserves_grade_and_feedback(): void {
        $this->assert_gradebook_lock(false);
    }

    public function test_numeric_grade_boundaries_reject_without_writes(): void {
        [$course, , $students] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course);
        $tool = new grade_submission();
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$students['alpha']->id, 'applytoall' => false];
        foreach ([0, 100] as $grade) {
            $saved = $tool->execute($args + ['grade' => $grade]);
            $this->assertSame((float)$grade, $saved['grade_saved']);
        }
        foreach ([-1, 101] as $grade) {
            $this->assert_unchanged(
                fn() => $tool->execute($args + ['grade' => $grade, 'feedback_comment' => 'Denied']),
                'error_grade_out_of_range'
            );
        }
        $item = $this->manual_item($course);
        $bookargs = ['course_id' => (int)$course->id, 'action' => 'update_grade', 'grade_item_id' => (int)$item->id,
            'student_user_id' => (int)$students['alpha']->id];
        foreach ([19, 81] as $grade) {
            $this->assert_unchanged(
                fn() => (new manage_grades())->execute($bookargs + ['final_grade' => $grade]),
                'error_grade_out_of_range'
            );
        }
    }

    public function test_scale_grade_boundaries_and_unknown_name_reject_without_writes(): void {
        [$course, , $students] = $this->fixture();
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Missing,Developing,Complete']);
        $assign = fixtures::assignment($this->getDataGenerator(), $course, ['grade' => -$scale->id]);
        $tool = new grade_submission();
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$students['alpha']->id, 'applytoall' => false];
        foreach ([1, 3] as $grade) {
            $this->assertSame((float)$grade, $tool->execute($args + ['grade' => $grade])['grade_saved']);
        }
        foreach ([0, 4] as $grade) {
            $this->assert_unchanged(fn() => $tool->execute($args + ['grade' => $grade]), 'error_grade_out_of_range');
        }
        $item = $this->assignment_item($assign);
        $this->assert_unchanged(fn() => (new manage_grades())->execute(['course_id' => (int)$course->id,
            'action' => 'update_grade', 'grade_item_id' => (int)$item->id, 'student_user_id' => (int)$students['alpha']->id,
            'scale_item' => 'Not a scale item']), 'error_invalid_params');
    }

    public function test_populated_quiz_metrics_use_latest_native_fractions_and_scoped_finished_attempts(): void {
        [$course, $teacher, $students] = $this->fixture();
        $generator = $this->getDataGenerator();
        $quiz = fixtures::quiz($generator, $course, $teacher, ['shortanswer', 'shortanswer', 'shortanswer']);
        $answers = ['alpha' => ['full', 'full', 'full'], 'beta' => ['unmatched', 'full', 'full'],
            'gamma' => ['half', 'unmatched', 'full']];
        $totals = [];
        foreach ($answers as $name => $values) {
            $attempt = fixtures::attempt(
                $generator,
                $quiz,
                $students[$name],
                $this->responses($values),
                true,
                $name === 'alpha' ? [1 => ['answer' => 'unmatched']] : []
            );
            $this->assertSame(0, (int)$attempt->get_attempt()->preview);
            $totals[] = (float)$attempt->get_attempt()->sumgrades;
        }
        $this->assertSame([3.0, 2.0, 1.5], $totals);
        fixtures::attempt($generator, $quiz, $students['outside'], $this->responses(['full', 'full', 'full']));
        $unfinished = fixtures::attempt($generator, $quiz, $students['alpha'], $this->responses(['full', 'full', 'full']), false);
        $this->assertSame(\mod_quiz\quiz_attempt::IN_PROGRESS, $unfinished->get_state());
        $preview = fixtures::attempt($generator, $quiz, $teacher, $this->responses(['full', 'full', 'full']));
        $this->assertSame(1, (int)$preview->get_attempt()->preview);
        $result = (new analyze_class_understanding())->execute(['cmid' => (int)$quiz->cmid]);
        $this->assertSame(3, $result['total_students_attempted']);
        $this->assertSame(3, $result['total_finished_attempts']);
        $this->assertSame(3.0, $result['grade_scale_maximum']);
        $this->assertSame(['average_score' => 2.17, 'highest_score' => 3.0, 'lowest_score' => 1.5], $result['metrics']);
        $rows = array_column($result['questions_analysis'], null, 'slot');
        foreach (
            [1 => [1, 1, 1, 33.3, 'DIFFICULT_NEEDS_ATTENTION'],
                2 => [2, 0, 1, 66.7, 'MODERATE'], 3 => [3, 0, 0, 100.0, 'WELL_UNDERSTOOD']] as $slot => $expected
        ) {
            $actual = $rows[$slot];
            $this->assertSame($expected, [$actual['correct_answers'], $actual['partial_answers'],
                $actual['wrong_answers'], $actual['facility_index_percent'], $actual['status']]);
            $this->assertSame(0, $actual['needs_grading']);
            $this->assertSame('Native slot ' . $slot, $actual['question_name']);
            $this->assertSame('shortanswer', $actual['question_type']);
        }
        $this->assertSame([1, 2, 3], array_column($result['questions_analysis'], 'slot'));
    }

    public function test_populated_essay_metrics_keep_ungraded_answers_distinct(): void {
        [$course, $teacher, $students] = $this->fixture();
        $generator = $this->getDataGenerator();
        $quiz = fixtures::quiz($generator, $course, $teacher, ['essay']);
        $attempt = fixtures::attempt($generator, $quiz, $students['alpha'], [1 => ['answer' => 'Owned essay', 'answerformat' => FORMAT_PLAIN]]);
        $this->assertNull($attempt->get_question_attempt(1)->get_fraction());
        $result = (new analyze_class_understanding())->execute(['cmid' => (int)$quiz->cmid]);
        $this->assertSame(1, $result['total_students_attempted']);
        $this->assertSame(0, $result['total_finished_attempts']);
        $row = $result['questions_analysis'][0];
        $this->assertSame('Native slot 1', $row['question_name']);
        $this->assertSame('essay', $row['question_type']);
        $this->assertSame([0, 0, 0, 1, 0], [$row['correct_answers'], $row['partial_answers'], $row['wrong_answers'],
            $row['needs_grading'], $row['facility_index_percent']]);
    }

    public function test_forbidden_group_targets_reject_without_domain_writes(): void {
        [$course, $teacher, $students, $groups] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $item = $this->assignment_item($assign);
        $courseargs = ['course_id' => (int)$course->id];
        $cmargs = ['cmid' => (int)$assign->get_course_module()->id];
        $outside = ['student_user_id' => (int)$students['outside']->id];
        $group = ['group_id' => (int)$groups['denied']->id];
        $calls = [
            [new get_group_students(), $courseargs + $group, 'error_no_group_access'],
            [new grade_submission(), $cmargs + ['action' => 'list_submissions'] + $group, 'error_no_group_access'],
            [new grade_submission(), $cmargs + ['action' => 'save_grade', 'grade' => 73] + $outside, 'error_no_group_access'],
            [new manage_grades(), $courseargs + ['action' => 'get_student_grades'] + $outside, 'error_no_group_access'],
            [new manage_grades(), $courseargs + ['action' => 'update_grade', 'grade_item_id' => (int)$item->id,
                'final_grade' => 73] + $outside, 'error_no_group_access'],
            [new manage_course_completion(), $courseargs + ['action' => 'get_student_progress'] + $outside, 'error_no_group_access'],
            [new detect_at_risk_students(), $courseargs + $group, 'error_group_access_denied'],
            [new analyze_class_understanding(), ['cmid' => (int)$quiz->cmid] + $group, 'error_group_access_denied'],
            [new send_student_message(), $courseargs + $outside + ['message_text' => 'Denied'], 'error_no_group_access'],
        ];
        foreach ($calls as [$tool, $params, $code]) {
            $this->assert_unchanged(fn() => $tool->execute($params), $code);
        }
        $nonmember = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonmember->id, $course->id, 'editingteacher');
        $this->setUser($nonmember);
        $this->assert_unchanged(fn() => (new prepare_grading_session())->execute($cmargs), 'error_no_group_access');
        $empty = (new detect_at_risk_students())->execute($courseargs);
        $this->assertSame(0, $empty['total_students_evaluated']);
        $this->assertSame([], $empty['at_risk_students']);
    }

    public function test_native_capability_denials_preserve_domain_state(): void {
        global $DB;
        [$course, $teacher, $students] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $item = $this->assignment_item($assign);
        $context = \context_course::instance($course->id);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $courseargs = ['course_id' => (int)$course->id];
        $calls = [
            ['mod/assign:grade', $assign->get_context(), new prepare_grading_session(), ['cmid' => (int)$assign->get_course_module()->id]],
            ['moodle/grade:edit', $context, new manage_grades(), $courseargs + ['action' => 'update_grade',
                'grade_item_id' => (int)$item->id, 'student_user_id' => (int)$students['alpha']->id, 'final_grade' => 73]],
            ['moodle/course:viewparticipants', $context, new get_group_students(), $courseargs],
            ['report/completion:view', $context, new manage_course_completion(), $courseargs + ['action' => 'get_criteria']],
            ['moodle/site:sendmessage', $context, new send_student_message(), $courseargs + [
                'student_user_id' => (int)$students['alpha']->id, 'message_text' => 'Denied']],
            ['moodle/calendar:manageentries', $context, new manage_course_calendar(), $courseargs + ['action' => 'create_event']],
            ['mod/quiz:viewreports', \context_module::instance($quiz->cmid), new analyze_class_understanding(), ['cmid' => (int)$quiz->cmid]],
            ['moodle/grade:viewall', $context, new detect_at_risk_students(), $courseargs],
        ];
        foreach ($calls as [$capability, $capcontext, $tool, $params]) {
            assign_capability($capability, CAP_PROHIBIT, $role, $capcontext->id, true);
            accesslib_clear_all_caches(true);
            $this->setUser($teacher);
            $this->assertFalse(has_capability($capability, $capcontext));
            try {
                $this->assert_unchanged(fn() => $tool->execute($params), 'nopermissions', get_capability_string($capability));
            } finally {
                unassign_capability($capability, $role, $capcontext->id);
                accesslib_clear_all_caches(true);
                $this->setUser($teacher);
            }
        }
    }

    public function test_nonmember_and_suspended_targets_are_not_teaching_write_targets(): void {
        global $DB;
        [$course, , $students] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course);
        $args = ['cmid' => (int)$assign->get_course_module()->id];
        $outsider = $this->getDataGenerator()->create_user();
        $this->assert_unchanged(fn() => (new grade_submission())->execute($args + ['action' => 'save_grade',
            'student_user_id' => (int)$outsider->id, 'grade' => 73]), 'error_not_enrolled');
        $enrolid = $DB->get_field('enrol', 'id', ['courseid' => $course->id, 'enrol' => 'manual'], MUST_EXIST);
        $DB->set_field(
            'user_enrolments',
            'status',
            ENROL_USER_SUSPENDED,
            ['enrolid' => $enrolid, 'userid' => $students['alpha']->id]
        );
        $this->assert_unchanged(fn() => (new grade_submission())->execute($args + ['action' => 'save_grade',
            'student_user_id' => (int)$students['alpha']->id, 'grade' => 73]), 'error_not_enrolled');
        $this->setUser($outsider);
        $this->assert_unchanged(fn() => (new prepare_grading_session())->execute($args), 'error_not_enrolled');
        $this->assert_unchanged(fn() => (new manage_course_calendar())->execute(['course_id' => (int)$course->id,
            'action' => 'create_event']), 'nopermissions');
    }

    public function test_read_grade_and_message_presets_keep_their_existing_boundaries(): void {
        [$course] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course);
        $server = new server();
        foreach ([['read'], ['read', 'grade'], ['read', 'message']] as $scopes) {
            $token = (object)['permissions' => json_encode(['scopes' => $scopes])];
            $roster = $server->execute_tool('get_group_students', ['course_id' => (int)$course->id], $token);
            $this->assertSame(1, $roster['total_groups']);
            foreach (
                ['prepare_grading_session' => 'grade', 'grade_submission' => 'grade', 'manage_grades' => 'grade',
                    'send_student_message' => 'message', 'manage_course_calendar' => 'write'] as $tool => $required
            ) {
                if (!in_array($required, $scopes, true)) {
                    $this->assert_unchanged(fn() => $server->execute_tool($tool, [], $token), 'error_tool_not_permitted');
                }
            }
            if (in_array('grade', $scopes, true)) {
                $prepared = $server->execute_tool('prepare_grading_session', ['cmid' => (int)$assign->get_course_module()->id], $token);
                $this->assertSame(0, $prepared['total_in_batch']);
            }
        }
    }

    public function test_preparation_filters_submitted_ungraded_work_and_clamps_small_limit(): void {
        [$course, , $students] = $this->fixture();
        $assign = fixtures::assignment($this->getDataGenerator(), $course);
        foreach (['alpha', 'beta', 'outside'] as $key) {
            fixtures::submit($assign, $students[$key], '<p>Owned ' . $key . ' work</p>');
        }
        $args = ['cmid' => (int)$assign->get_course_module()->id];
        (new grade_submission())->execute($args + ['action' => 'save_grade', 'student_user_id' => (int)$students['alpha']->id,
            'grade' => 73, 'applytoall' => false]);
        $tool = new prepare_grading_session();
        $ungraded = $tool->execute($args);
        $this->assertSame([(int)$students['beta']->id], array_column($ungraded['submissions'], 'student_id'));
        $all = $tool->execute($args + ['only_ungraded' => false]);
        $this->assertSame(2, $all['total_in_batch']);
        $this->assertNotContains((int)$students['outside']->id, array_column($all['submissions'], 'student_id'));
        $this->assertSame(1, $tool->execute($args + ['only_ungraded' => false, 'limit' => 0])['total_in_batch']);
    }

    public function test_message_privacy_recipient_and_rate_denials_do_not_deliver(): void {
        global $DB;
        [$course, $teacher, $students] = $this->fixture();
        fixtures::local_messages([$students['alpha']]);
        $tool = new send_student_message();
        $args = ['course_id' => (int)$course->id, 'student_user_id' => (int)$students['alpha']->id, 'message_text' => 'Owned limit message'];
        set_user_preference('message_blocknoncontacts', \core_message\api::MESSAGE_PRIVACY_ONLYCONTACTS, $students['alpha']);
        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('moodle/site:messageanyuser', $context));
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/site:messageanyuser', CAP_PROHIBIT, $role, $context->id, true);
        accesslib_clear_all_caches(true);
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/site:messageanyuser', $context));
        $this->assertFalse(\core_message\api::can_send_message($students['alpha']->id, $teacher->id));
        $this->assert_unchanged(fn() => $tool->execute($args), 'error_cannot_message_user');
        $this->assert_unchanged(fn() => $tool->execute(array_replace($args, ['student_user_id' => (int)$teacher->id])), 'error_invalid_user');
        set_user_preference('message_blocknoncontacts', \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER, $students['alpha']);
        set_config('message_ratelimit_per_hour', 1, 'tool_teachermcp');
        $sink = $this->redirectMessages();
        try {
            $this->assertGreaterThan(0, $tool->execute($args)['message_id']);
            $this->assert_unchanged(fn() => $tool->execute($args), 'error_rate_limited');
            $this->assertCount(1, $sink->get_messages());
        } finally {
            $sink->close();
        }
    }

    private function fixture(): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1, 'enablecompletion' => 1]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $students = [];
        foreach (['alpha', 'beta', 'gamma', 'outside'] as $key) {
            $students[$key] = $generator->create_user();
            $generator->enrol_user($students[$key]->id, $course->id, 'student');
        }
        $groups = fixtures::groups($generator, $course, $teacher, $students);
        $this->setUser($teacher);
        return [$course, $teacher, $students, $groups];
    }

    private function manual_item(\stdClass $course): \grade_item {
        $item = new \grade_item((object)['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'Owned boundary item',
            'gradetype' => GRADE_TYPE_VALUE, 'grademin' => 20, 'grademax' => 80], false);
        $this->assertNotFalse($item->insert());
        return $item;
    }

    private function assignment_item(\assign $assign): \grade_item {
        return \grade_item::fetch(['courseid' => $assign->get_course()->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->get_instance()->id, 'itemnumber' => 0]);
    }

    private function assert_unchanged(callable $operation, string $code, ?string $message = null): void {
        $before = fixtures::snapshot();
        $caught = null;
        try {
            $operation();
        } catch (\moodle_exception $error) {
            $caught = $error;
        }
        $this->assertEquals($before, fixtures::snapshot(), 'Denied teaching operations must not write grades, feedback, files or support state.');
        $this->assertNotNull($caught, 'The denied operation must raise an explicit error.');
        $this->assertSame($code, $caught->errorcode);
        if ($message !== null) {
            $this->assertStringContainsString($message, $caught->getMessage());
        }
    }

    private function assert_assignment_lock(string $kind): void {
        global $DB;
        [$course, , $students] = $this->fixture();
        $generator = $this->getDataGenerator();
        $options = [];
        if ($kind === 'team') {
            $team = $generator->create_group(['courseid' => $course->id]);
            foreach (['alpha', 'beta'] as $key) {
                $generator->create_group_member(['groupid' => $team->id, 'userid' => $students[$key]->id]);
            }
            $grouping = $generator->create_grouping(['courseid' => $course->id]);
            $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $team->id]);
            $options = ['teamsubmission' => 1, 'requireallteammemberssubmit' => 0, 'teamsubmissiongroupingid' => $grouping->id];
        }
        $assign = fixtures::assignment($generator, $course, $options);
        fixtures::submit($assign, $students['alpha'], '<p>Owned lock submission</p>');
        $tool = new grade_submission();
        $args = ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$students['alpha']->id];
        $tool->execute($args + ['grade' => 55, 'feedback_comment' => 'Original feedback']);
        grade_regrade_final_grades($course->id);
        $item = $this->assignment_item($assign);
        $lockeduser = $kind === 'team' ? $students['beta']->id : $students['alpha']->id;
        if ($kind === 'item') {
            $item->set_locked(1);
        } else {
            \grade_grade::fetch(['itemid' => $item->id, 'userid' => $lockeduser])->set_locked(1);
        }
        $item = $this->assignment_item($assign);
        $this->assertTrue($item->is_locked($lockeduser), 'The native fixture lock must be effective before the tool call.');
        $this->assert_unchanged(
            fn() => $tool->execute($args + ['grade' => 73, 'feedback_comment' => 'Denied feedback',
            'feedback_files' => [['filename' => 'denied.txt', 'content_base64' => base64_encode('Denied feedback bytes')]]]),
            'error_grade_locked'
        );
        if ($kind === 'team') {
            $before = $DB->get_records('assign_grades', ['assignment' => $assign->get_instance()->id, 'userid' => $lockeduser]);
            $tool->execute($args + ['grade' => 73, 'applytoall' => false]);
            $this->assertSame(73.0, (float)$assign->get_user_grade($students['alpha']->id, false)->grade);
            $this->assertEquals($before, $DB->get_records('assign_grades', ['assignment' => $assign->get_instance()->id, 'userid' => $lockeduser]));
        }
    }

    private function assert_gradebook_lock(bool $wholeitem): void {
        [$course, , $students] = $this->fixture();
        $item = $this->manual_item($course);
        $item->update_final_grade($students['alpha']->id, 55, 'fixture', 'Original feedback', FORMAT_HTML);
        grade_regrade_final_grades($course->id);
        $item = \grade_item::fetch(['id' => $item->id]);
        if ($wholeitem) {
            $item->set_locked(1);
        } else {
            \grade_grade::fetch(['itemid' => $item->id, 'userid' => $students['alpha']->id])->set_locked(1);
        }
        $item = \grade_item::fetch(['id' => $item->id]);
        $this->assertTrue($item->is_locked($students['alpha']->id));
        $this->assert_unchanged(fn() => (new manage_grades())->execute(['course_id' => (int)$course->id, 'action' => 'update_grade',
            'grade_item_id' => (int)$item->id, 'student_user_id' => (int)$students['alpha']->id,
            'final_grade' => 73, 'feedback' => 'Denied feedback']), 'error_grade_locked');
    }

    private function responses(array $answers): array {
        return array_combine(range(1, count($answers)), array_map(static fn($answer) => ['answer' => $answer], $answers));
    }
}
