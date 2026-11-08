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

use tool_teachermcp\tests\fixtures\teaching_fixtures;
use tool_teachermcp\tools\grade_submission;

/**
 * Assignment feedback-file validation, storage and manifest contracts.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class feedback_files_test extends \advanced_testcase {
    public function test_disabled_feedback_files_reject_before_grade_or_comment_changes(): void {
        [$course, , $student] = $this->fixture();
        $assign = teaching_fixtures::assignment(
            $this->getDataGenerator(),
            $course,
            ['groupmode' => NOGROUPS, 'assignfeedback_file_enabled' => 0]
        );
        $before = teaching_fixtures::snapshot();
        try {
            (new grade_submission())->execute($this->grade_args($assign, $student) + [
                'grade' => 8, 'feedback_comment' => 'Must not save',
                'feedback_files' => [['filename' => 'feedback.txt', 'content_base64' => base64_encode('Feedback')]],
            ]);
            $this->fail('Disabled feedback-file support must not silently ignore attachments');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_feedback_files_disabled', $error->errorcode);
        }
        $this->assertEquals($before, teaching_fixtures::snapshot());
    }

    public function test_invalid_feedback_files_reject_the_entire_request_before_writes(): void {
        [$course, , $student] = $this->fixture();
        $assign = teaching_fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        $valid = ['filename' => 'feedback.txt', 'content_base64' => base64_encode('Feedback')];
        foreach (
            [[$valid, ['filename' => 'bad.txt', 'content_base64' => '%%%']],
                [['filename' => '../bad.txt', 'content_base64' => 'eA==']], [$valid, $valid],
                [['filename' => '', 'content_base64' => 'eA==']], [['filename' => 'bad.txt']], 'not-a-list'] as $files
        ) {
            $before = teaching_fixtures::snapshot();
            try {
                (new grade_submission())->execute($this->grade_args($assign, $student) + [
                    'grade' => 8, 'feedback_comment' => 'Must not save', 'feedback_files' => $files,
                ]);
                $this->fail('Invalid feedback files must reject the whole grading request');
            } catch (\moodle_exception $error) {
                $this->assertSame('error_invalid_params', $error->errorcode);
                $this->assertStringNotContainsString('{$a}', $error->getMessage());
            }
            $this->assertEquals($before, teaching_fixtures::snapshot());
        }
    }

    public function test_feedback_manifests_match_native_storage_and_omit_preserves_files(): void {
        [$course, , $student] = $this->fixture();
        $assign = teaching_fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        $args = $this->grade_args($assign, $student);
        $tool = new grade_submission();
        $saved = $tool->execute($args + ['grade' => 8, 'feedback_files' => [
            ['filename' => 'feedback.txt', 'content_base64' => base64_encode('Native feedback bytes')],
            ['filename' => 'empty.txt', 'content_base64' => ''],
        ]]);
        $this->assertCount(2, $saved['feedback_files']);
        $grade = \tool_teachermcp\local\assignment_grading::read_grade($assign, (int)$student->id);
        foreach ($saved['feedback_files'] as $file) {
            $stored = get_file_storage()->get_file_by_id($file['file_id']);
            $this->assertSame('assignfeedback_file', $stored->get_component());
            $this->assertSame('feedback_files', $stored->get_filearea());
            $this->assertEquals($grade->id, $stored->get_itemid());
            $this->assertStringContainsString('/assignfeedback_file/feedback_files/' . $grade->id . '/', $file['download_url']);
            $this->assertSame((int)$stored->get_filesize(), $file['filesize']);
        }
        $again = $tool->execute($args + ['feedback_comment' => 'Comment only']);
        $this->assertSame($saved['feedback_files'], $again['feedback_files']);
        $listed = $tool->execute(['cmid' => (int)$assign->get_course_module()->id, 'action' => 'list_submissions']);
        $this->assertSame($saved['feedback_files'], $listed['submissions'][0]['feedback_files']);
    }

    public function test_feedback_replacement_and_empty_list_preservation_are_explicit(): void {
        [$course, , $student] = $this->fixture();
        $assign = teaching_fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        $tool = new grade_submission();
        $args = $this->grade_args($assign, $student);
        $first = $tool->execute($args + ['feedback_files' => [
            ['filename' => 'original.txt', 'content_base64' => base64_encode('Original')],
        ]]);
        $empty = $tool->execute($args + ['feedback_files' => []]);
        $this->assertSame($first['feedback_files'], $empty['feedback_files']);
        $replacement = $tool->execute($args + ['feedback_files' => [
            ['filename' => 'replacement.txt', 'content_base64' => base64_encode('Replacement')],
        ]]);
        $this->assertSame(['replacement.txt'], array_column($replacement['feedback_files'], 'filename'));
        $this->assertFalse(get_file_storage()->get_file_by_id($first['feedback_files'][0]['file_id']));
    }

    public function test_team_feedback_files_are_verified_for_each_affected_student(): void {
        [$course, , $student] = $this->fixture();
        $generator = $this->getDataGenerator();
        $other = $generator->create_user();
        $generator->enrol_user($other->id, $course->id, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        foreach ([$student, $other] as $member) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        }
        $assign = teaching_fixtures::assignment(
            $generator,
            $course,
            ['groupmode' => NOGROUPS, 'teamsubmission' => 1, 'requireallteammemberssubmit' => 0]
        );
        teaching_fixtures::submit($assign, $student, 'Team response');
        $result = (new grade_submission())->execute(['cmid' => (int)$assign->get_course_module()->id,
            'action' => 'save_grade', 'student_user_id' => (int)$student->id, 'grade' => 8,
            'feedback_files' => [['filename' => 'team.txt', 'content_base64' => base64_encode('Team feedback')]]]);
        $this->assertCount(2, $result['affected_students']);
        $fileids = [];
        foreach ($result['affected_students'] as $outcome) {
            $this->assertCount(1, $outcome['feedback_files']);
            $fileid = $outcome['feedback_files'][0]['file_id'];
            $fileids[] = $fileid;
            $this->assertSame('Team feedback', get_file_storage()->get_file_by_id($fileid)->get_content());
        }
        $this->assertCount(2, array_unique($fileids));
    }

    public function test_feedback_upload_limit_rejects_before_grade_writes(): void {
        global $DB;
        [$course, , $student] = $this->fixture();
        $course->maxbytes = 4;
        $DB->update_record('course', $course);
        $assign = teaching_fixtures::assignment($this->getDataGenerator(), $course, ['groupmode' => NOGROUPS]);
        $before = teaching_fixtures::snapshot();
        try {
            (new grade_submission())->execute($this->grade_args($assign, $student) + ['grade' => 8,
                'feedback_files' => [['filename' => 'large.txt', 'content_base64' => base64_encode('12345')]]]);
            $this->fail('Course upload limits must be enforced before grading');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_invalid_params', $error->errorcode);
            $this->assertStringContainsString('4 bytes', $error->getMessage());
        }
        $this->assertEquals($before, teaching_fixtures::snapshot());
    }

    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/course/lib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        return [$course, $teacher, $student];
    }

    private function grade_args(\assign $assign, \stdClass $student): array {
        return ['cmid' => (int)$assign->get_course_module()->id, 'action' => 'save_grade',
            'student_user_id' => (int)$student->id, 'applytoall' => false];
    }
}
