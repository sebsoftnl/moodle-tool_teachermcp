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
 * Tests for Teacher MCP privacy metadata, export contexts, and deletion behavior.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\privacy\provider
 */
final class privacy_test extends \advanced_testcase {
    public function test_blok6_privacy_provider(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        // 1. Verify metadata collection.
        $collection = new \core_privacy\local\metadata\collection('tool_teachermcp');
        $collection = \tool_teachermcp\privacy\provider::get_metadata($collection);
        $this->assertNotEmpty($collection->get_collection());

        $user = $generator->create_user();
        $course = $generator->create_course();

        // Insert dummy tokens for user.
        $tokenid = $DB->insert_record('tool_teachermcp_tokens', [
            'userid' => $user->id,
            'tokenhash' => hash('sha256', 'dummy_token'),
            'name' => 'Agent for User',
            'clientid' => 'client_abc',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $codeid = $DB->insert_record('tool_teachermcp_oauth_codes', [
            'userid' => $user->id,
            'codehash' => hash('sha256', 'dummy_code'),
            'clientid' => 'client_abc',
            'redirecturi' => 'https://example.com/oauth',
            'status' => 0,
            'timecreated' => time(),
            'timeexpires' => time() + 300,
        ]);

        // Insert dummy course asset file for user.
        $coursecontext = \context_course::instance($course->id);
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $coursecontext->id,
            'component' => 'tool_teachermcp',
            'filearea' => 'course_assets',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'test_asset.png',
            'userid' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $file = $fs->create_file_from_string($filerecord, 'fake_png_data');
        $DB->insert_record('tool_teachermcp_assets', [
            'userid' => $user->id,
            'contextid' => $coursecontext->id,
            'fileid' => $file->get_id(),
            'timecreated' => time(),
        ]);

        $usercontext = \context_user::instance($user->id);

        // 2. Verify context list contains both user context and course context.
        $contextlist = \tool_teachermcp\privacy\provider::get_contexts_for_userid($user->id);
        $contextids = array_map('intval', $contextlist->get_contextids());
        $this->assertContains((int)$usercontext->id, $contextids);
        $this->assertContains((int)$coursecontext->id, $contextids);

        // 3. Export user data.
        $approvedlist = new \core_privacy\local\request\approved_contextlist(
            $user,
            'tool_teachermcp',
            [$usercontext->id, $coursecontext->id]
        );
        \tool_teachermcp\privacy\provider::export_user_data($approvedlist);

        // 4. Delete data for user.
        \tool_teachermcp\privacy\provider::delete_data_for_user($approvedlist);

        // Tokens and OAuth codes for user must be removed.
        $this->assertFalse($DB->record_exists('tool_teachermcp_tokens', ['id' => $tokenid]));
        $this->assertFalse($DB->record_exists('tool_teachermcp_oauth_codes', ['id' => $codeid]));
        // Course asset file for user must be removed.
        $this->assertFalse($fs->file_exists($coursecontext->id, 'tool_teachermcp', 'course_assets', 0, '/', 'test_asset.png'));
    }

    public function test_privacy_erasure_preserves_page_and_book_content(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = self::getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $book = $generator->create_module('book', ['course' => $course->id]);
        $chapterid = $DB->insert_record('book_chapters', [
            'bookid' => $book->id, 'pagenum' => 1, 'subchapter' => 0,
            'title' => 'Chapter', 'content' => '<p>Teaching material</p>', 'contentformat' => FORMAT_HTML,
            'hidden' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $fs = get_file_storage();
        $files = [];
        foreach (
            [['mod_page', 'content', 0, \context_module::instance($page->cmid)],
                ['mod_book', 'chapter', (int)$chapterid, \context_module::instance($book->cmid)]] as [$component, $area, $itemid, $context]
        ) {
            $file = $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => $component, 'filearea' => $area,
                'itemid' => $itemid, 'filepath' => '/', 'filename' => 'diagram.png', 'userid' => $user->id,
            ], 'retained teaching material');
            $files[] = $file->get_id();
            $DB->insert_record('tool_teachermcp_assets', [
                'userid' => $user->id, 'contextid' => $context->id,
                'fileid' => $file->get_id(), 'timecreated' => time(),
            ]);
        }

        $approved = new \core_privacy\local\request\approved_contextlist(
            $user,
            'tool_teachermcp',
            [\context_module::instance($page->cmid)->id, \context_module::instance($book->cmid)->id]
        );
        \tool_teachermcp\privacy\provider::delete_data_for_user($approved);
        foreach ($files as $fileid) {
            $this->assertNotFalse($fs->get_file_by_id($fileid), 'Erasure cannot destroy activity-owned material.');
            $this->assertFalse($DB->record_exists('tool_teachermcp_assets', ['fileid' => $fileid]));
        }
    }
}
