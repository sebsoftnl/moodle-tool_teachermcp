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

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for course, module, and separate-group access controls.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\tools\base_tool
 */
final class access_test extends \advanced_testcase {
    public function test_b1_require_course_access_multiple_capabilities(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        // Test with teacher who has both capabilities.
        $this->setUser($teacher);
        $tool = new \tool_teachermcp\tools\send_student_message();
        $ref = new \ReflectionMethod($tool, 'require_course_access');
        $ref->setAccessible(true);

        $res = $ref->invoke($tool, (int)$course->id, ['moodle/course:manageactivities', 'mod/quiz:addinstance']);
        $this->assertEquals($course->id, $res->id);

        // Test with student who lacks capabilities - must throw required_capability_exception.
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $ref->invoke($tool, (int)$course->id, ['moodle/course:manageactivities', 'mod/quiz:addinstance']);
    }

    public function test_h2_teacher_course_access_and_suspended_enrolment(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $suspendedteacher = $generator->create_user();
        $student = $generator->create_user();

        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($suspendedteacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        // Suspend the second teacher's enrolment.
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $suspendedteacher->id]);

        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Demo Page', 'content' => 'Hello']);

        // 1. Editing teacher succeeds across all 5 tools.
        $this->setUser($teacher);

        $texport = new \tool_teachermcp\tools\export_course_learning_material();
        $resexport = $texport->execute(['course_id' => (int)$course->id]);
        $this->assertEquals($course->id, $resexport['course_id']);

        $tcontents = new \tool_teachermcp\tools\get_course_contents();
        $rescontents = $tcontents->execute(['course_id' => (int)$course->id]);
        $this->assertEquals($course->id, $rescontents['course_id']);

        $tstudents = new \tool_teachermcp\tools\get_group_students();
        $resstudents = $tstudents->execute(['course_id' => (int)$course->id]);
        $this->assertEquals($course->id, $resstudents['course_id']);

        $treadfile = new \tool_teachermcp\tools\read_activity_file();
        $resreadfile = $treadfile->execute(['cmid' => (int)$page->cmid]);
        $this->assertSame('empty', $resreadfile['status']);
        $this->assertSame([], $resreadfile['files']);

        $tcontentact = new \tool_teachermcp\tools\manage_content_activity();
        $rescontentact = $tcontentact->execute(['action' => 'get_content', 'cmid' => (int)$page->cmid]);
        $this->assertEquals('page', $rescontentact['type']);

        // 2. Suspended teacher must fail with error_not_enrolled on require_course_access.
        $this->setUser($suspendedteacher);
        try {
            $tcontents->execute(['course_id' => (int)$course->id]);
            $this->fail('Expected exception for suspended teacher');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_not_enrolled', $e->errorcode);
        }

        // Module-scoped tools must reject the same suspended enrolment.
        try {
            $tcontentact->execute(['action' => 'get_content', 'cmid' => (int)$page->cmid]);
            $this->fail('Expected exception for suspended teacher on module tool');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_not_enrolled', $e->errorcode);
        }

        // 3. Student has enrollment but lacks capabilities.
        $this->setUser($student);
        try {
            $texport->execute(['course_id' => (int)$course->id]);
            $this->fail('Expected required_capability_exception for student calling export_course_learning_material');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        try {
            $tstudents->execute(['course_id' => (int)$course->id]);
            $this->fail('Expected required_capability_exception for student calling get_group_students');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_blok6_group_isolation_separate_groups(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        // Create course with SEPARATEGROUPS.
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);

        $teacher = $generator->create_user();
        $student1 = $generator->create_user();
        $student2 = $generator->create_user();

        $generator->enrol_user($teacher->id, $course->id, 'teacher'); // Non-editing teacher without accessallgroups.
        $generator->enrol_user($student1->id, $course->id, 'student');
        $generator->enrol_user($student2->id, $course->id, 'student');

        // Create Group A and Group B.
        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);

        // Put teacher and student1 in Group A.
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $student1->id]);

        // Put student2 in Group B.
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student2->id]);

        // Create quiz in course.
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Group Quiz']);

        // Simulate attempts for both students.
        $finishedstate = class_exists('\mod_quiz\quiz_attempt') ? \mod_quiz\quiz_attempt::FINISHED : \quiz_attempt::FINISHED;
        $DB->insert_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            'attempt' => 1,
            'uniqueid' => 101,
            'layout' => '1,0',
            'state' => $finishedstate,
            'timestart' => time() - 3600,
            'timefinish' => time() - 1800,
            'timemodified' => time() - 1800,
            'preview' => 0,
            'sumgrades' => 8.0,
        ]);
        $DB->insert_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $student2->id,
            'attempt' => 1,
            'uniqueid' => 102,
            'layout' => '1,0',
            'state' => $finishedstate,
            'timestart' => time() - 3600,
            'timefinish' => time() - 1800,
            'timemodified' => time() - 1800,
            'preview' => 0,
            'sumgrades' => 4.0,
        ]);

        $this->setUser($teacher);

        // 1. analyze_class_understanding must only see attempts from Group A (1 attempt, not 2).
        $toolanalyze = new \tool_teachermcp\tools\analyze_class_understanding();
        $resanalyze = $toolanalyze->execute(['cmid' => (int)$quiz->cmid]);
        $this->assertEquals(1, $resanalyze['total_finished_attempts']);

        // Teacher attempting to pass group_id of Group B (not member) must be denied.
        try {
            $toolanalyze->execute(['cmid' => (int)$quiz->cmid, 'group_id' => (int)$groupb->id]);
            $this->fail('Expected error_group_access_denied for unauthorized group');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_group_access_denied', $e->errorcode);
        }

        // 2. detect_at_risk_students must only evaluate student1 from Group A.
        $toolrisk = new \tool_teachermcp\tools\detect_at_risk_students();
        $resrisk = $toolrisk->execute(['course_id' => (int)$course->id]);
        $this->assertEquals(1, $resrisk['total_students_evaluated']);

        // Explicitly passing Group A (which teacher belongs to) succeeds.
        $resriskgroupa = $toolrisk->execute(['course_id' => (int)$course->id, 'group_id' => (int)$groupa->id]);
        $this->assertEquals(1, $resriskgroupa['total_students_evaluated']);

        // Passing Group B must fail with error_group_access_denied.
        try {
            $toolrisk->execute(['course_id' => (int)$course->id, 'group_id' => (int)$groupb->id]);
            $this->fail('Expected error_group_access_denied when passing unauthorized group');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_group_access_denied', $e->errorcode);
        }
    }
}
