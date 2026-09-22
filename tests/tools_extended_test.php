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
 * Extended test suite covering remaining built-in tools, dynamic MCP resources, and events.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\tools
 */
final class tools_extended_test extends \advanced_testcase {

    public function test_get_my_profile_tool(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $user = $generator->create_user([
            'firstname' => 'Marie',
            'lastname' => 'Curie',
            'email' => 'marie@example.com',
            'city' => 'Paris',
            'country' => 'FR',
        ]);
        $this->setUser($user);

        $tool = new \tool_teachermcp\tools\get_my_profile();
        $result = $tool->execute([]);

        $this->assertSame((int)$user->id, $result['userid']);
        $this->assertSame($user->username, $result['username']);
        $this->assertSame('Marie Curie', $result['fullname']);
        $this->assertSame('marie@example.com', $result['email']);
        $this->assertSame('Paris', $result['city']);
        $this->assertSame('FR', $result['country']);
        $this->assertArrayHasKey('site_roles', $result);
        $this->assertArrayHasKey('moodle_release', $result);
        $this->assertArrayHasKey('wwwroot', $result);
    }

    public function test_get_teacher_courses_tool(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $this->setUser($teacher);

        $now = time();
        $course1 = $generator->create_course([
            'fullname' => 'Active Physics',
            'shortname' => 'PHYS101',
            'startdate' => $now - 3600,
            'enddate' => $now + 86400,
        ]);
        $course2 = $generator->create_course([
            'fullname' => 'Past Chemistry',
            'shortname' => 'CHEM101',
            'startdate' => $now - 7200,
            'enddate' => $now - 3600,
        ]);

        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $generator->enrol_user($teacher->id, $course2->id, 'editingteacher');

        $tool = new \tool_teachermcp\tools\get_teacher_courses();

        // 1. All courses
        $all = $tool->execute(['only_active' => false]);
        $this->assertSame(2, $all['total_courses']);
        $ids = array_column($all['courses'], 'id');
        $this->assertContains((int)$course1->id, $ids);
        $this->assertContains((int)$course2->id, $ids);

        // 2. Only active courses
        $active = $tool->execute(['only_active' => true]);
        $this->assertSame(1, $active['total_courses']);
        $this->assertSame((int)$course1->id, $active['courses'][0]['id']);
        $this->assertTrue($active['courses'][0]['can_edit']);
        $this->assertTrue($active['courses'][0]['can_grade']);
    }

    public function test_manage_activity_tool(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $generator->create_module('page', [
            'course' => $course->id,
            'name' => 'Original Page Name',
            'intro' => '<p>Original description</p>',
        ]);

        $tool = new \tool_teachermcp\tools\manage_activity();

        // 1. Get settings
        $settings = $tool->execute([
            'cmid' => (int)$page->cmid,
            'action' => 'get_settings',
        ]);
        $this->assertSame((int)$page->cmid, $settings['cmid']);
        $this->assertSame('Original Page Name', $settings['name']);
        $this->assertSame('page', $settings['modname']);
        $this->assertTrue($settings['visible']);

        // 2. Update settings
        $updated = $tool->execute([
            'cmid' => (int)$page->cmid,
            'action' => 'update_settings',
            'name' => 'Updated Page Name',
            'intro' => '<p>Updated description</p>',
            'visible' => false,
        ]);
        $this->assertTrue($updated['success']);
        $this->assertSame('Updated Page Name', $updated['name']);
        $this->assertFalse($updated['visible']);

        // Verify in database
        $cmrec = $DB->get_record('course_modules', ['id' => $page->cmid]);
        $this->assertSame(0, (int)$cmrec->visible);
        $pagerec = $DB->get_record('page', ['id' => $page->id]);
        $this->assertSame('Updated Page Name', $pagerec->name);

        // 3. Updating a module without a complete safe normaliser is rejected.
        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Unsupported assignment',
        ]);
        try {
            $tool->execute([
                'cmid' => (int)$assign->cmid,
                'action' => 'update_settings',
                'name' => 'Must not update',
            ]);
            $this->fail('Expected unsupported module update to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalid_action', $e->errorcode);
        }

        // 4. Invalid action
        try {
            $tool->execute([
                'cmid' => (int)$page->cmid,
                'action' => 'invalid_action',
            ]);
            $this->fail('Expected moodle_exception for invalid action');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalid_action', $e->errorcode);
        }
    }

    public function test_manage_grades_tool(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);

        // Create an assignment module which registers a grade item.
        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Midterm Essay',
            'grade' => 100,
        ]);

        $tool = new \tool_teachermcp\tools\manage_grades();

        // 1. List grade items
        $items = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'list_grade_items',
        ]);
        $this->assertSame((int)$course->id, $items['course_id']);
        $this->assertNotEmpty($items['grade_items']);
        $itemid = 0;
        foreach ($items['grade_items'] as $it) {
            if ($it['itemmodule'] === 'assign') {
                $itemid = $it['id'];
                break;
            }
        }
        $this->assertGreaterThan(0, $itemid);

        // 2. Update grade
        $update = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'update_grade',
            'grade_item_id' => $itemid,
            'student_user_id' => (int)$student->id,
            'final_grade' => 88.5,
            'feedback' => 'Well structured essay with sound reasoning.',
        ]);
        $this->assertTrue($update['success']);
        $this->assertSame(88.5, (float)$update['updated_grade']);

        // 3. Get student grades
        $grades = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'get_student_grades',
            'student_user_id' => (int)$student->id,
        ]);
        $this->assertSame((int)$student->id, $grades['student_user_id']);
        $this->assertNotEmpty($grades['grades']);
        $found = false;
        foreach ($grades['grades'] as $g) {
            if ($g['grade_item_id'] === $itemid) {
                $this->assertEquals(88.5, (float)$g['finalgrade']);
                $this->assertSame('Well structured essay with sound reasoning.', $g['feedback']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function test_grade_submission_tool(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Lab Report',
            'grade' => 100,
        ]);

        $this->setUser($teacher);
        $tool = new \tool_teachermcp\tools\grade_submission();

        // 1. List submissions
        $subs = $tool->execute([
            'cmid' => (int)$assign->cmid,
            'action' => 'list_submissions',
        ]);
        $this->assertSame((int)$assign->cmid, $subs['cmid']);
        $this->assertSame(1, $subs['total_students']);
        $this->assertSame((int)$student->id, $subs['submissions'][0]['student_id']);

        // 2. Save grade
        $saved = $tool->execute([
            'cmid' => (int)$assign->cmid,
            'action' => 'save_grade',
            'student_user_id' => (int)$student->id,
            'grade' => 92.0,
            'feedback_comment' => '<p>Excellent lab methodology.</p>',
        ]);
        $this->assertTrue($saved['success']);
        $this->assertEquals(92.0, (float)$saved['grade_saved']);

        // Verify assignment object reflects grade
        $context = \context_module::instance($assign->cmid);
        $cm = get_coursemodule_from_id('assign', $assign->cmid);
        $assignobj = new \assign($context, $cm, $course);
        $usergrade = $assignobj->get_user_grade($student->id, false);
        $this->assertNotNull($usergrade);
        $this->assertEquals(92.0, (float)$usergrade->grade);

        // 3. Out of range grade throws exception
        try {
            $tool->execute([
                'cmid' => (int)$assign->cmid,
                'action' => 'save_grade',
                'student_user_id' => (int)$student->id,
                'grade' => 150.0,
            ]);
            $this->fail('Expected error_grade_out_of_range');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_grade_out_of_range', $e->errorcode);
        }
    }

    public function test_manage_course_completion_tool(): void {
        global $CFG;
        $CFG->enablecompletion = 1;

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['enablecompletion' => 1]);
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\manage_course_completion();

        // 1. Get criteria
        $criteria = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'get_criteria',
        ]);
        $this->assertSame((int)$course->id, $criteria['course_id']);
        $this->assertTrue($criteria['completion_enabled']);
        $this->assertArrayHasKey('criteria', $criteria);

        // 2. Get student progress
        $progress = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'get_student_progress',
            'student_user_id' => (int)$student->id,
        ]);
        $this->assertSame((int)$course->id, $progress['course_id']);
        $this->assertSame((int)$student->id, $progress['student_user_id']);
        $this->assertArrayHasKey('activities_progress', $progress);
    }

    public function test_duplicate_activity_tool(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $generator->create_module('page', [
            'course' => $course->id,
            'name' => 'Original Module',
            'intro' => 'Test intro',
            'content' => 'Test content',
        ]);

        $tool = new \tool_teachermcp\tools\duplicate_activity();

        // Duplicate within same course
        $res = $tool->execute([
            'cmid' => (int)$page->cmid,
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame((int)$page->cmid, $res['source_cmid']);
        $this->assertGreaterThan(0, $res['new_cmid']);
        $this->assertNotSame((int)$page->cmid, $res['new_cmid']);

        // Verify duplicated module exists in database
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $res['new_cmid']]));
    }

    public function test_get_h5p_libraries_tool(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\get_h5p_libraries();
        $res = $tool->execute([
            'course_id' => (int)$course->id,
        ]);

        $this->assertArrayHasKey('total_installed_types', $res);
        $this->assertArrayHasKey('libraries', $res);
    }

    public function test_mcp_resources_reading(): void {
        global $generator;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course([
            'fullname' => 'Robotics 101',
            'shortname' => 'ROB101',
            'summary' => 'Introduction to robotics engineering.',
        ]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $this->setUser($teacher);

        $page = $generator->create_module('page', [
            'course' => $course->id,
            'name' => 'Microcontrollers Overview',
            'content' => 'Arduino and Raspberry Pi fundamentals.',
        ]);

        // 1. Read moodle://courses
        $coursesres = \tool_teachermcp\local\resource_registry::read_resource('moodle://courses');
        $this->assertNotNull($coursesres);
        $json = json_decode($coursesres['contents'][0]['text'], true);
        $this->assertArrayHasKey('courses', $json);
        $ids = array_column($json['courses'], 'id');
        $this->assertContains((int)$course->id, $ids);

        // 2. Read moodle://course/{id}/syllabus
        $syllabus = \tool_teachermcp\local\resource_registry::read_resource("moodle://course/{$course->id}/syllabus");
        $this->assertNotNull($syllabus);
        $md = $syllabus['contents'][0]['text'];
        $this->assertStringContainsString('Robotics 101', $md);
        $this->assertStringContainsString('Microcontrollers Overview', $md);

        // 3. Read moodle://course/{id}/announcements
        $announcements = \tool_teachermcp\local\resource_registry::read_resource("moodle://course/{$course->id}/announcements");
        $this->assertNotNull($announcements);
        $this->assertStringContainsString('Announcements: Robotics 101', $announcements['contents'][0]['text']);

        // 4. Read moodle://activity/{cmid}/rubric
        $rubric = \tool_teachermcp\local\resource_registry::read_resource("moodle://activity/{$page->cmid}/rubric");
        $this->assertNotNull($rubric);
        $rubricjson = json_decode($rubric['contents'][0]['text'], true);
        $this->assertSame((int)$page->cmid, $rubricjson['cmid']);

        // 5. Non-existent resource returns null
        $this->assertNull(\tool_teachermcp\local\resource_registry::read_resource('moodle://nonexistent'));

        // 6. A user without Teacher MCP access in another course cannot read it.
        $othercourse = $generator->create_course(['fullname' => 'Unrelated course']);
        $this->assertNull(\tool_teachermcp\local\resource_registry::read_resource(
            "moodle://course/{$othercourse->id}/syllabus"
        ));
    }

    public function test_token_events_sink(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'editingteacher');
        \tool_teachermcp\auth::invalidate_user_connect_cache($user->id);

        $eventsink = $this->redirectEvents();

        // 1. Trigger token_created
        $res = \tool_teachermcp\auth::generate_token_record($user->id, 'Desktop Assistant', 60);
        $events = $eventsink->get_events();
        $createdevents = array_filter($events, fn($e) => $e instanceof \tool_teachermcp\event\token_created);
        $this->assertNotEmpty($createdevents);
        $createevent = reset($createdevents);
        $this->assertSame((int)$user->id, (int)$createevent->relateduserid);
        $this->assertSame((int)$res['id'], $createevent->objectid);
        $this->assertSame('tool_teachermcp_tokens', $createevent->objecttable);

        // 2. Trigger token_revoked
        $eventsink->clear();
        \tool_teachermcp\auth::revoke_token($res['id'], $user->id);
        $events = $eventsink->get_events();
        $revokedevents = array_filter($events, fn($e) => $e instanceof \tool_teachermcp\event\token_revoked);
        $this->assertNotEmpty($revokedevents);
        $revokevent = reset($revokedevents);
        $this->assertSame((int)$user->id, (int)$revokevent->relateduserid);
        $this->assertSame((int)$res['id'], (int)$revokevent->objectid);

        $eventsink->close();
    }

    public function test_prompt_registry_direct(): void {
        $all = \tool_teachermcp\local\prompt_registry::get_all_prompts();
        $this->assertNotEmpty($all);
        $this->assertCount(15, $all);

        $prompt = \tool_teachermcp\local\prompt_registry::get_prompt('ingest_course', ['course_id' => 99]);
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('99', $prompt['messages'][0]['content']['text']);

        // Test newly added prompts
        $pAssign = \tool_teachermcp\local\prompt_registry::get_prompt('scaffold_assignment', ['course_id' => 55]);
        $this->assertNotNull($pAssign);
        $this->assertStringContainsString('create_assignment', $pAssign['messages'][0]['content']['text']);
        $this->assertStringContainsString('55', $pAssign['messages'][0]['content']['text']);

        $pCal = \tool_teachermcp\local\prompt_registry::get_prompt('schedule_event', ['course_id' => 55]);
        $this->assertNotNull($pCal);
        $this->assertStringContainsString('manage_course_calendar', $pCal['messages'][0]['content']['text']);

        // Missing required arg or non-existent
        $this->assertNull(\tool_teachermcp\local\prompt_registry::get_prompt('nonexistent_prompt', []));
    }

    public function test_unsafe_forum_tool_is_not_published(): void {
        $this->resetAfterTest(true);

        $this->assertArrayNotHasKey('manage_course_forum', \tool_teachermcp\server::get_all_tools());
    }

    public function test_external_tool_adapter_direct(): void {
        $declaration = new \tool_teachermcp\local\provider_tool_declaration(
            'mod_quiz',
            'grade_quiz',
            \tool_teachermcp\tools\base_tool::class,
            'read',
            'mod/quiz:grade',
            'mod_quiz/enable_ai'
        );

        $fakeTool = new class implements \tool_teachermcp\local\external_tool {
            public function get_description(): string {
                return 'Fake external tool description';
            }
            public function get_input_schema(): array {
                return ['type' => 'object'];
            }
            public function execute(array $arguments, \tool_teachermcp\local\execution_context $context): array {
                return [
                    'userid' => $context->get_userid(),
                    'echo' => $arguments['msg'] ?? '',
                ];
            }
        };

        $adapter = new \tool_teachermcp\local\external_tool_adapter(
            $declaration,
            $fakeTool,
            'Adapter description',
            ['type' => 'object']
        );

        $this->assertSame('mod_quiz.grade_quiz', $adapter->get_name());
        $this->assertSame('Adapter description', $adapter->get_description());
        $this->assertSame(['type' => 'object'], $adapter->get_input_schema());

        $execResult = $adapter->execute(['msg' => 'hello']);
        $this->assertSame('hello', $execResult['echo']);
    }
}

