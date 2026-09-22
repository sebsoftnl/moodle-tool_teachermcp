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
 * Tests for MCP course-content, file, activity, H5P, and deletion tools.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\tools
 */
final class content_tools_test extends \advanced_testcase {
    public function test_file_extractor_whitelist(): void {
        $this->assertTrue(file_extractor::is_allowed_filearea('mod_resource', 'content'));
        $this->assertTrue(file_extractor::is_allowed_filearea('mod_folder', 'content'));
        $this->assertTrue(file_extractor::is_allowed_filearea('mod_page', 'content'));
        $this->assertTrue(file_extractor::is_allowed_filearea('mod_book', 'chapter'));
        $this->assertTrue(file_extractor::is_allowed_filearea('course', 'summary'));

        // Student submissions must be blocked.
        $this->assertFalse(file_extractor::is_allowed_filearea('assignsubmission_file', 'submission_files'));
        $this->assertFalse(file_extractor::is_allowed_filearea('assignfeedback_file', 'feedback_files'));
    }

    public function test_n1_manage_content_activity_module_type_and_events(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course1 = $generator->create_course();
        $course2 = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->setUser($teacher);

        // Create page and book in course1.
        $page = $generator->create_module('page', ['course' => $course1->id, 'name' => 'Page 1']);
        $book1 = $generator->create_module('book', ['course' => $course1->id, 'name' => 'Book 1']);

        // Create book in course2.
        $book2 = $generator->create_module('book', ['course' => $course2->id, 'name' => 'Book 2']);

        $tool = new \tool_teachermcp\tools\manage_content_activity();

        // 1. Calling add_book_chapter on a page cmid must be rejected.
        try {
            $tool->execute([
                'action' => 'add_book_chapter',
                'cmid' => (int)$page->cmid,
                'chapter_title' => 'Invalid Chapter',
                'content' => '<p>Test</p>',
            ]);
            $this->fail('Expected exception when calling add_book_chapter on a page cmid');
        } catch (\moodle_exception $e) {
            $this->assertEquals('invalidcoursemoduleid', $e->errorcode);
        }

        // 2. Valid chapter creation triggers mod_book\event\chapter_created event.
        $sink = $this->redirectEvents();
        $res = $tool->execute([
            'action' => 'add_book_chapter',
            'cmid' => (int)$book1->cmid,
            'chapter_title' => 'Chapter 1',
            'content' => '<p>Valid Book Chapter Content</p>',
        ]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('Chapter 1', $res['chapter_title']);

        $foundcreated = false;
        foreach ($events as $event) {
            if ($event instanceof \mod_book\event\chapter_created) {
                $foundcreated = true;
                break;
            }
        }
        $this->assertTrue($foundcreated);

        // 3. Modifying chapter in course1 must not affect book2 in course2.
        $book2chaptersbefore = $DB->count_records('book_chapters', ['bookid' => $book2->id]);
        $this->assertEquals(0, $book2chaptersbefore);
    }

    public function test_blok5_export_learning_material_limit(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\export_course_learning_material();
        $res = $tool->execute([
            'course_id' => (int)$course->id,
            'format' => 'json',
        ]);
        $this->assertIsArray($res);
        $this->assertEquals($course->id, $res['course_id']);
        // For standard small course, no warning.
        $this->assertArrayNotHasKey('warning', $res);
    }

    public function test_blok5_h5p_parameter_depth_and_size_limits(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\create_h5p_activity();

        // 1. Nesting depth > 20 levels must throw error_params_too_deep.
        $nested = ['leaf' => 'value'];
        for ($i = 0; $i < 22; $i++) {
            $nested = ['level_' . $i => $nested];
        }

        try {
            $tool->execute([
                'course_id' => (int)$course->id,
                'title' => 'Deep H5P Activity',
                'library_name' => 'H5P.MultiChoice',
                'params' => $nested,
            ]);
            $this->fail('Expected error_params_too_deep exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_params_too_deep', $e->errorcode);
        }

        // 2. Payload > 2.5 MB must throw error_invalid_params.
        $bigstring = str_repeat('A', 2700000);
        try {
            $tool->execute([
                'course_id' => (int)$course->id,
                'title' => 'Big H5P Activity',
                'library_name' => 'H5P.MultiChoice',
                'params' => ['text' => $bigstring],
            ]);
            $this->fail('Expected error_invalid_params exception for oversized payload');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_params', $e->errorcode);
            $this->assertStringContainsString('2.5 MB', $e->getMessage());
        }

        // 3. Exact machinename: Non-existent library must throw error_library_not_found.
        try {
            $tool->execute([
                'course_id' => (int)$course->id,
                'title' => 'Non-existent Library',
                'library_name' => 'H5P.FakeLibraryNameDoesNotExist',
                'params' => ['question' => 'Is this real?'],
            ]);
            $this->fail('Expected error_library_not_found exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_library_not_found', $e->errorcode);
        }
    }

    public function test_blok5_upload_course_asset_and_pluginfile(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\upload_course_asset();
        $pngbase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        // 1. Successful upload of valid PNG.
        $res1 = $tool->execute([
            'course_id' => (int)$course->id,
            'filename' => 'pixel.png',
            'file_content_base64' => $pngbase64,
            'alt_text' => 'Single Pixel',
        ]);
        $this->assertEquals('success', $res1['status']);
        $this->assertEquals('pixel.png', $res1['filename']);
        $this->assertEquals('tool_teachermcp', $res1['component']);
        $this->assertEquals('course_assets', $res1['filearea']);
        $this->assertStringContainsString('pixel.png', $res1['url']);
        $this->assertStringContainsString('Single Pixel', $res1['html_tag']);

        // 2. Upload same filename again -> unique collision resolution (_1, _2).
        $res2 = $tool->execute([
            'course_id' => (int)$course->id,
            'filename' => 'pixel.png',
            'file_content_base64' => $pngbase64,
        ]);
        $this->assertEquals('pixel_1.png', $res2['filename']);

        $res3 = $tool->execute([
            'course_id' => (int)$course->id,
            'filename' => 'pixel.png',
            'file_content_base64' => $pngbase64,
        ]);
        $this->assertEquals('pixel_2.png', $res3['filename']);

        // Verify all three files exist in Moodle file storage.
        $fs = get_file_storage();
        $coursecontext = \context_course::instance($course->id);
        $this->assertTrue($fs->file_exists($coursecontext->id, 'tool_teachermcp', 'course_assets', 0, '/', 'pixel.png'));
        $this->assertTrue($fs->file_exists($coursecontext->id, 'tool_teachermcp', 'course_assets', 0, '/', 'pixel_1.png'));
        $this->assertTrue($fs->file_exists($coursecontext->id, 'tool_teachermcp', 'course_assets', 0, '/', 'pixel_2.png'));

        // 3. SVG upload must be rejected.
        try {
            $tool->execute([
                'course_id' => (int)$course->id,
                'filename' => 'drawing.svg',
                'file_content_base64' => base64_encode('<svg><circle cx="5" cy="5" r="5"/></svg>'),
            ]);
            $this->fail('Expected error_invalid_image_format for SVG');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_image_format', $e->errorcode);
        }

        // 4. Remote URLs are disabled; agents must submit the image content.
        try {
            $tool->execute([
                'course_id' => (int)$course->id,
                'filename' => 'remote.png',
                'image_url' => 'https://example.com/image.png',
            ]);
            $this->fail('Expected error_remote_image_fetch_disabled for remote URL');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_remote_image_fetch_disabled', $e->errorcode);
        }

        // 5. Target CMID into mod_page.
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Lesson Page']);
        $respage = $tool->execute([
            'course_id' => (int)$course->id,
            'filename' => 'page_img.png',
            'file_content_base64' => $pngbase64,
            'target_cmid' => (int)$page->cmid,
        ]);
        $this->assertEquals('mod_page', $respage['component']);
        $this->assertEquals('content', $respage['filearea']);
        $this->assertEquals(0, $respage['itemid']);

        // 6. Test tool_teachermcp_pluginfile security.
        require_once(__DIR__ . '/../lib.php');
        $syscontext = \context_system::instance();

        // Non-course context returns false.
        $this->assertFalse(tool_teachermcp_pluginfile($course, null, $syscontext, 'course_assets', [0, 'pixel.png'], false));

        // Non-course_assets filearea returns false.
        $this->assertFalse(tool_teachermcp_pluginfile($course, null, $coursecontext, 'other_area', [0, 'pixel.png'], false));
    }

    public function test_blok5_delete_activity_ui_confirmation_guard(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Module To Delete Safely']);
        $cmid = (int)$page->cmid;

        // Default setting: delete_requires_ui_confirmation is 1 (enabled).
        set_config('delete_requires_ui_confirmation', 1, 'tool_teachermcp');

        $tool = new \tool_teachermcp\tools\delete_activity();
        $res = $tool->execute(['cmid' => $cmid]);

        $this->assertFalse($res['success']);
        $this->assertTrue($res['requires_ui_confirmation']);
        $this->assertEquals('ui_confirmation_required', $res['status']);
        $this->assertStringContainsString('confirm_delete.php?cmid=' . $cmid, $res['confirmation_url']);
        $this->assertNotEmpty($res['confirm_token']);
        $this->assertArrayHasKey('recycle_bin_enabled', $res['activity']);

        // Module must remain intact in DB.
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]));
    }

    public function test_blok5_delete_activity_token_verification_and_replay_protection(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Module For Token Deletion']);
        $cmid = (int)$page->cmid;

        // Enable automated token-based deletion.
        set_config('delete_requires_ui_confirmation', 0, 'tool_teachermcp');
        set_config('enable_delete_tool', 1, 'tool_teachermcp');

        $tool = new \tool_teachermcp\tools\delete_activity();

        // 1. Initial preview step (without token).
        $step1 = $tool->execute(['cmid' => $cmid]);
        $this->assertFalse($step1['success']);
        $this->assertTrue($step1['requires_confirmation']);
        $this->assertEquals('confirmation_required', $step1['status']);
        $this->assertNotEmpty($step1['confirm_token']);
        $this->assertArrayHasKey('recycle_bin_enabled', $step1['activity']);
        $validtoken = $step1['confirm_token'];

        // 2. Tampered token -> fails.
        try {
            $tool->execute([
                'cmid' => $cmid,
                'confirm_token' => base64_encode(time() . ':tampered_hash_value_12345'),
            ]);
            $this->fail('Expected exception for tampered confirmation token');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_confirmation_token', $e->errorcode);
        }

        // 3. Valid token -> successfully deletes activity!
        $step2 = $tool->execute([
            'cmid' => $cmid,
            'confirm_token' => $validtoken,
        ]);
        $this->assertTrue($step2['success']);
        $this->assertEquals('deleted', $step2['status']);
        $this->assertEquals($cmid, $step2['cmid']);

        // Verify module was deleted from database.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));

        // 4. Token replay attempt -> fails.
        try {
            $tool->execute([
                'cmid' => $cmid,
                'confirm_token' => $validtoken,
            ]);
            $this->fail('Expected exception on confirmation token replay');
        } catch (\moodle_exception $e) {
            $this->assertEquals('invalidcoursemodule', $e->errorcode);
        }
    }

    public function test_create_assignment_tool(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\create_assignment();
        $res = $tool->execute([
            'course_id' => (int)$course->id,
            'title' => 'AI Ethics Essay',
            'intro' => '<p>Write an essay on AI ethics.</p>',
            'due_date' => time() + (7 * 86400),
            'allow_online_text' => true,
            'allow_file_submissions' => true,
            'max_files' => 3,
            'max_grade' => 100,
            'rubric_criteria' => [
                ['description' => 'Critical Reasoning', 'weight_percentage' => 50],
                ['description' => 'Grammar & Clarity', 'weight_percentage' => 50],
            ],
        ]);

        $this->assertSame('success', $res['status']);
        $this->assertSame('assign', $res['activity_type']);
        $this->assertGreaterThan(0, $res['cmid']);
        $this->assertGreaterThan(0, $res['assignment_id']);
        $this->assertTrue($DB->record_exists('assign', ['id' => $res['assignment_id']]));

        try {
            $tool->execute([
                'course_id' => (int)$course->id,
                'title' => 'Invalid Assignment',
                'due_date' => time() + 7200,
                'cutoff_date' => time() + 3600,
            ]);
            $this->fail('Expected invalid assignment dates to be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalid_params', $e->errorcode);
        }

        $invalidassignments = [
            ['title' => ''],
            [
                'title' => 'No submission method',
                'allow_online_text' => false,
                'allow_file_submissions' => false,
            ],
            [
                'title' => 'Invalid rubric type',
                'rubric_criteria' => 'not-an-array',
            ],
            [
                'title' => 'Invalid rubric weight',
                'rubric_criteria' => [['description' => 'Criterion', 'weight_percentage' => -1]],
            ],
            [
                'title' => 'Invalid grade',
                'max_grade' => 'not-a-number',
            ],
        ];
        foreach ($invalidassignments as $params) {
            try {
                $tool->execute(['course_id' => (int)$course->id] + $params);
                $this->fail('Expected invalid assignment input to be rejected.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_invalid_params', $e->errorcode);
            }
        }
    }


    public function test_manage_course_calendar_tool(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tool = new \tool_teachermcp\tools\manage_course_calendar();

        // 1. Create event
        $createres = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'create_event',
            'name' => 'Midterm Review Session',
            'description' => '<p>Bring questions on chapter 1 to 4.</p>',
            'timestart' => time() + 7200,
            'duration_minutes' => 90,
        ]);
        $this->assertSame('success', $createres['status']);
        $eventid = $createres['event_id'];
        $this->assertGreaterThan(0, $eventid);

        // 2. List events
        $listres = $tool->execute([
            'course_id' => (int)$course->id,
            'action' => 'list_events',
        ]);
        $this->assertNotEmpty($listres['events']);
        $this->assertSame('Midterm Review Session', $listres['events'][0]['name']);

        // Deleting calendar entries is intentionally not exposed through this write tool.
        $schema = $tool->get_input_schema();
        $this->assertNotContains('delete_event', $schema['properties']['action']['enum']);
        $this->assertTrue($DB->record_exists('event', ['id' => $eventid]));
    }
}
