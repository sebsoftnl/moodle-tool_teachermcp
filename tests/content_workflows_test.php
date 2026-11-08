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

require_once(__DIR__ . '/fixtures/content_files.php');

use tool_teachermcp\tests\fixtures\content_files;
use tool_teachermcp\tools\duplicate_activity;
use tool_teachermcp\tools\export_course_learning_material;
use tool_teachermcp\tools\manage_activity;
use tool_teachermcp\tools\manage_content_activity;
use tool_teachermcp\tools\read_activity_file;
use tool_teachermcp\tools\upload_course_asset;

/**
 * Existing teacher content and file workflows through public tools.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_workflows_test extends \advanced_testcase {
    public function test_page_and_book_six_action_workflow(): void {
        global $DB;
        [$course] = $this->fixture();
        $tool = new manage_content_activity();
        $text = "Content caf\u{00e9} \u{03a9}";
        $page = $tool->execute(['action' => 'create_page', 'course_id' => (int)$course->id,
            'section_number' => 1, 'title' => '<b>Original page</b>', 'content' => '<p>' . $text . '</p>']);
        $this->assertSame('Original page', $page['title']);
        $this->assertFalse($page['visible']);
        $this->assertSame(1, (int)get_fast_modinfo($course->id)->get_cm($page['cmid'])->sectionnum);
        $before = $tool->execute(['action' => 'get_content', 'cmid' => $page['cmid']]);
        $updated = $tool->execute(['action' => 'update_page', 'cmid' => $page['cmid'],
            'title' => 'Updated page', 'content' => '<p>' . $text . ' updated</p><script>alert(1)</script>']);
        $this->assertFalse($updated['visible']);
        $this->assertSame($before['revision'] + 1, $updated['revision']);
        $read = $tool->execute(['action' => 'get_content', 'cmid' => $page['cmid']]);
        $this->assertSame('Updated page', $read['title']);
        $this->assertStringContainsString($text . ' updated', $read['content']);
        $this->assertStringNotContainsString('<script', $read['content']);

        $book = $tool->execute(['action' => 'create_book', 'course_id' => (int)$course->id,
            'section_number' => 2, 'title' => 'Native book', 'chapter_title' => 'First chapter',
            'content' => '<p>' . $text . '</p>', 'visible' => true]);
        $this->assertTrue($book['visible']);
        $this->assertSame(2, (int)get_fast_modinfo($course->id)->get_cm($book['cmid'])->sectionnum);
        $sink = $this->redirectEvents();
        $second = $tool->execute(['action' => 'add_book_chapter', 'cmid' => $book['cmid'],
            'chapter_title' => 'Second chapter', 'content' => '<p>Second</p>', 'is_subchapter' => false]);
        $third = $tool->execute(['action' => 'add_book_chapter', 'cmid' => $book['cmid'],
            'chapter_title' => 'Third chapter', 'content' => '<p>Third</p>', 'is_subchapter' => true]);
        $this->assertSame(2, $second['page_number']);
        $this->assertSame(3, $third['page_number']);
        $this->assertTrue($third['is_subchapter']);
        $revision = (int)$DB->get_field('book', 'revision', ['id' => $book['book_id']], MUST_EXIST);
        $tool->execute(['action' => 'update_book_chapter', 'cmid' => $book['cmid'], 'chapter_id' => $third['chapter_id'],
            'chapter_title' => 'Third updated', 'content' => '<p>Revised third</p>', 'is_subchapter' => false]);
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(2, array_filter($events, static fn($event) => $event instanceof \mod_book\event\chapter_created));
        $this->assertCount(1, array_filter($events, static fn($event) => $event instanceof \mod_book\event\chapter_updated));
        $read = $tool->execute(['action' => 'get_content', 'cmid' => $book['cmid']]);
        $this->assertSame($revision + 1, $read['revision']);
        $this->assertSame(3, $read['total_chapters']);
        $this->assertSame(['First chapter', 'Second chapter', 'Third updated'], array_column($read['chapters'], 'title'));
        $this->assertSame([1, 2, 3], array_column($read['chapters'], 'page_number'));
        $this->assertFalse($read['chapters'][2]['is_subchapter']);
        $this->assertSame('<p>Revised third</p>', $read['chapters'][2]['content']);
    }

    public function test_raster_uploads_collision_and_data_uri_preserve_bytes(): void {
        [$course] = $this->fixture();
        $tool = new upload_course_asset();
        foreach (
            ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif', 'webp' => 'image/webp'] as $extension => $mime
        ) {
            $bytes = content_files::image($extension);
            $result = $tool->execute(['course_id' => (int)$course->id, 'filename' => 'asset.' . $extension,
                'file_content_base64' => base64_encode($bytes), 'alt_text' => 'Diagram "A" <&>']);
            $stored = get_file_storage()->get_file_by_id($result['file_id']);
            $this->assertSame('success', $result['status']);
            $this->assertSame($mime, $result['mimetype']);
            $this->assertSame('tool_teachermcp', $result['component']);
            $this->assertSame('course_assets', $result['filearea']);
            $this->assertSame(sha1($bytes), $stored->get_contenthash());
            $this->assertStringContainsString('Diagram &quot;A&quot; &lt;&amp;&gt;', $result['html_tag']);
            $this->assertStringContainsString('/pluginfile.php/', $result['url']);
        }
        foreach (['asset_1.png', 'asset_2.png'] as $filename) {
            $result = $tool->execute(['course_id' => (int)$course->id, 'filename' => 'asset.png',
                'file_content_base64' => 'data:image/png;base64,' . base64_encode(content_files::image())]);
            $this->assertSame($filename, $result['filename']);
        }
    }

    public function test_page_and_book_asset_destinations_are_native_file_areas(): void {
        [$course] = $this->fixture();
        $content = new manage_content_activity();
        $page = $content->execute(['action' => 'create_page', 'course_id' => (int)$course->id, 'title' => 'Asset page']);
        $book = $content->execute(['action' => 'create_book', 'course_id' => (int)$course->id, 'title' => 'Asset book']);
        $chapter = $content->execute(['action' => 'add_book_chapter', 'cmid' => $book['cmid'], 'chapter_title' => 'Second']);
        $tool = new upload_course_asset();
        foreach (
            [[$page['cmid'], [], 'mod_page', 'content', 0],
                [$book['cmid'], [], 'mod_book', 'chapter', $book['first_chapter_id']],
                [$book['cmid'], ['chapter_id' => $chapter['chapter_id']], 'mod_book', 'chapter', $chapter['chapter_id']]] as [$cmid, $extra, $component, $area, $itemid]
        ) {
            $result = $tool->execute(['course_id' => (int)$course->id, 'target_cmid' => $cmid,
                'filename' => 'diagram.png', 'file_content_base64' => base64_encode(content_files::image())] + $extra);
            $file = get_file_storage()->get_file_by_id($result['file_id']);
            $this->assertSame($component, $result['component']);
            $this->assertSame($area, $result['filearea']);
            $this->assertSame($itemid, $result['itemid']);
            $this->assertSame((int)\context_module::instance($cmid)->id, (int)$file->get_contextid());
            $this->assertSame(sha1(content_files::image()), $file->get_contenthash());
        }
    }

    public function test_reader_empty_single_multiple_and_valid_selectors(): void {
        [$course] = $this->fixture();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $context = \context_module::instance($folder->cmid);
        $tool = new read_activity_file();
        $empty = $tool->execute(['cmid' => (int)$folder->cmid]);
        $this->assertSame('empty', $empty['status']);
        $this->assertSame([], $empty['files']);
        $first = content_files::store($context, 'Alpha.txt', 'First local file', 'mod_folder');
        $single = $tool->execute(['cmid' => (int)$folder->cmid]);
        $this->assertArrayHasKey('selected_file', $single, 'The sole stored learning file must be discovered and selected.');
        $this->assertSame((int)$first->get_id(), $single['selected_file']['fileid']);
        $this->assertSame('First local file', $single['selected_file']['content_text']);
        $second = content_files::store($context, 'Bravo.txt', 'Second local file', 'mod_folder');
        $multiple = $tool->execute(['cmid' => (int)$folder->cmid]);
        $this->assertSame(2, $multiple['total_files']);
        $this->assertArrayNotHasKey('selected_file', $multiple);
        $this->assertNotEmpty($multiple['notice']);
        foreach ([['fileid' => (int)$second->get_id()], ['filename' => ' BRAVO.TXT ']] as $selector) {
            $result = $tool->execute(['cmid' => (int)$folder->cmid] + $selector);
            $this->assertSame((int)$second->get_id(), $result['selected_file']['fileid']);
            $this->assertTrue($result['selected_file']['extracted_success']);
            $this->assertSame('Second local file', $result['selected_file']['content_text']);
        }
    }

    public function test_invalid_content_targets_leave_existing_records_unchanged(): void {
        global $DB;
        [$course] = $this->fixture();
        $tool = new manage_content_activity();
        $page = $tool->execute(['action' => 'create_page', 'course_id' => (int)$course->id, 'title' => 'Existing page']);
        $book = $tool->execute(['action' => 'create_book', 'course_id' => (int)$course->id, 'title' => 'Existing book']);
        $other = $tool->execute(['action' => 'create_book', 'course_id' => (int)$course->id, 'title' => 'Other book']);
        $before = [$this->source_state($page['cmid']), $this->source_state($book['cmid']), $this->source_state($other['cmid'])];
        try {
            $tool->execute(['action' => 'add_book_chapter', 'cmid' => $page['cmid'], 'chapter_title' => 'Wrong module']);
            $this->fail('A Page cannot be used as a Book.');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidcoursemoduleid', $error->errorcode);
        }
        try {
            $tool->execute(['action' => 'update_book_chapter', 'cmid' => $book['cmid'],
                'chapter_id' => $other['first_chapter_id'], 'content' => 'Must not persist']);
            $this->fail('A foreign Book chapter cannot be updated through this Book.');
        } catch (\dml_missing_record_exception $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $counts = array_map(static fn($table) => $DB->count_records($table), ['course_modules', 'page', 'book', 'book_chapters']);
        foreach (['create_page', 'create_book'] as $action) {
            try {
                $tool->execute(['action' => $action, 'course_id' => (int)$course->id, 'section_number' => 99]);
                $this->fail('An absent section must reject before content creation.');
            } catch (\moodle_exception $error) {
                $this->assertSame('sectionnotexist', $error->errorcode);
            }
        }
        $this->assertEquals($before, [$this->source_state($page['cmid']), $this->source_state($book['cmid']),
            $this->source_state($other['cmid'])]);
        $this->assertSame($counts, array_map(static fn($table) => $DB->count_records($table), ['course_modules', 'page', 'book', 'book_chapters']));
    }

    public function test_export_preserves_material_order_and_caller_visibility(): void {
        global $DB;
        [$course, $teacher] = $this->fixture();
        $tool = new manage_content_activity();
        $page = $tool->execute(['action' => 'create_page', 'course_id' => (int)$course->id,
            'title' => 'Visible page', 'content' => '<p>Visible page text</p>', 'section_number' => 1, 'visible' => true]);
        $hidden = $tool->execute(['action' => 'create_page', 'course_id' => (int)$course->id,
            'title' => 'Hidden page', 'content' => '<p>Hidden page sentinel</p>', 'section_number' => 1]);
        $book = $tool->execute(['action' => 'create_book', 'course_id' => (int)$course->id,
            'title' => 'Visible book', 'content' => '<p>First chapter text</p>', 'section_number' => 1, 'visible' => true]);
        $tool->execute(['action' => 'add_book_chapter', 'cmid' => $book['cmid'], 'chapter_title' => 'Second chapter',
            'content' => '<p>Second chapter text</p>']);
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id, 'section' => 1]);
        content_files::store(\context_module::instance($folder->cmid), 'notes.txt', 'Folder source text', 'mod_folder');
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'section' => 1,
            'defaultfilename' => 'source.txt']);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:viewhiddenactivities', CAP_PROHIBIT, $role, \context_course::instance($course->id)->id);
        $this->setUser($teacher);
        get_fast_modinfo($course->id, 0, true);
        $this->assertFalse(get_fast_modinfo($course->id)->get_cm($hidden['cmid'])->uservisible);
        $exporter = new export_course_learning_material();
        $json = $exporter->execute(['course_id' => (int)$course->id, 'section_number' => 1, 'format' => 'json']);
        $this->assertCount(1, $json['sections']);
        $materials = $json['sections'][0]['materials'];
        $this->assertSame([$page['cmid'], $book['cmid'], (int)$folder->cmid, (int)$resource->cmid], array_column($materials, 'cmid'));
        $this->assertSame('Visible page text', $materials[0]['content']);
        $this->assertLessThan(strpos($materials[1]['content'], 'Second chapter text'), strpos($materials[1]['content'], 'First chapter text'));
        $this->assertStringContainsString('Folder source text', $materials[2]['content']);
        $this->assertStringContainsString('Test resource source.txt file', $materials[3]['content']);
        $markdown = $exporter->execute(['course_id' => (int)$course->id, 'section_number' => 1]);
        $this->assertFalse($markdown['truncated']);
        $this->assertStringNotContainsString('Hidden page sentinel', $markdown['content']);
        $this->assertStringContainsString('Folder source text', $markdown['content']);
    }

    public function test_export_preserves_page_and_summary_block_boundaries(): void {
        global $DB;
        [$course] = $this->fixture();
        $DB->set_field('course', 'summary', '<p>Course first.</p><p>Course second.</p>', ['id' => $course->id]);
        $DB->set_field('course_sections', 'summary', '<div>Section first.</div><div>Section second.</div>', ['course' => $course->id, 'section' => 1]);
        $page = (new manage_content_activity())->execute(['action' => 'create_page', 'course_id' => (int)$course->id,
            'section_number' => 1, 'title' => 'Readable page', 'visible' => true,
            'content' => '<p>Evapora<span>tion</span> changes liquid water into vapour.</p>'
                . '<p>QA page revision 2.<br>Next line.</p>']);
        rebuild_course_cache($course->id, true);
        $tool = new export_course_learning_material();
        $args = ['course_id' => (int)$course->id, 'section_number' => 1];
        $json = $tool->execute($args + ['format' => 'json']);
        $markdown = $tool->execute($args);
        $materials = array_column($json['sections'][0]['materials'], null, 'cmid');

        foreach ([$materials[$page['cmid']]['content'], $markdown['content']] as $text) {
            $this->assertMatchesRegularExpression(
                '/Evaporation changes liquid water into vapour\.\s+QA page revision 2\.\s+Next line\./u',
                $text,
                'Export must preserve paragraph and line-break boundaries without splitting inline text.'
            );
        }
        foreach ([$json['sections'][0]['summary'], $markdown['content']] as $text) {
            $this->assertMatchesRegularExpression('/Section first\.\s+Section second\./u', $text, 'Section summary blocks must remain separate in both output formats.');
        }
        $this->assertMatchesRegularExpression('/Course first\.\s+Course second\./u', $markdown['content']);
        $this->assertStringNotContainsString('<span>', $materials[$page['cmid']]['content']);
        $this->assertFalse($markdown['truncated']);
    }

    public function test_export_preserves_book_and_assignment_paragraphs(): void {
        global $DB;
        [$course] = $this->fixture();
        $book = (new manage_content_activity())->execute(['action' => 'create_book', 'course_id' => (int)$course->id,
            'section_number' => 1, 'title' => 'Readable book', 'chapter_title' => 'Chapter one', 'visible' => true,
            'content' => '<p>Book first.</p><p>Book second.</p>']);
        $instructions = '<p>Instruction first.</p><p>Instruction second.</p>';
        $assignment = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'section' => 1,
            'intro' => '<p>Brief first.</p><p>Brief second.</p>', 'introformat' => FORMAT_HTML,
            'activityeditor' => ['text' => $instructions, 'format' => FORMAT_HTML]]);
        $this->assertSame(
            $instructions,
            $DB->get_field('assign', 'activity', ['id' => $assignment->id], MUST_EXIST),
            'The native assignment fixture must contain the instructions being exported.'
        );
        $tool = new export_course_learning_material();
        $args = ['course_id' => (int)$course->id, 'section_number' => 1];
        $json = $tool->execute($args + ['format' => 'json']);
        $markdown = $tool->execute($args);
        $materials = array_column($json['sections'][0]['materials'], null, 'cmid');

        foreach ([$materials[$book['cmid']]['content'], $markdown['content']] as $text) {
            $this->assertMatchesRegularExpression('/Book first\.\s+Book second\./u', $text, 'Book paragraphs must not be joined during export.');
        }
        foreach ([$materials[$assignment->cmid]['content'], $markdown['content']] as $text) {
            $this->assertMatchesRegularExpression('/Brief first\.\s+Brief second\./u', $text, 'Assignment introduction paragraphs must remain separate.');
            $this->assertMatchesRegularExpression('/Instruction first\.\s+Instruction second\./u', $text, 'Assignment instruction paragraphs must remain separate.');
        }
    }

    public function test_settings_updates_preserve_page_body_and_attached_file_bytes(): void {
        global $DB;
        [$course] = $this->fixture();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'name' => 'Settings source', 'content' => '<p>Settings body</p><img src="@@PLUGINFILE@@/diagram.png" alt="Diagram">']);
        $file = content_files::store(\context_module::instance($page->cmid), 'diagram.png', content_files::image(), 'mod_page');
        $before = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $tool = new manage_activity();
        $settings = $tool->execute(['action' => 'get_settings', 'cmid' => (int)$page->cmid]);
        $this->assertSame('page', $settings['modname']);
        $updated = $tool->execute(['action' => 'update_settings', 'cmid' => (int)$page->cmid,
            'name' => 'Settings renamed', 'intro' => '<p>Changed intro</p>']);
        $after = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $this->assertTrue($updated['success']);
        $this->assertSame('Settings renamed', $after->name);
        $this->assertSame($before->content, $after->content);
        $this->assertSame($before->contentformat, $after->contentformat);
        $this->assertSame((int)$before->revision + 1, (int)$after->revision);
        $this->assertSame($file->get_contenthash(), get_file_storage()->get_file_by_id($file->get_id())->get_contenthash());
        $hidden = $tool->execute(['action' => 'update_settings', 'cmid' => (int)$page->cmid, 'visible' => false]);
        $this->assertFalse($hidden['visible']);
        $this->assertEquals($after, $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST));
    }

    public function test_native_same_course_page_and_book_copies_preserve_source(): void {
        global $DB;
        [$course] = $this->fixture();
        $content = new manage_content_activity();
        foreach (['page', 'book'] as $type) {
            $source = $content->execute(['action' => 'create_' . $type, 'course_id' => (int)$course->id,
                'title' => 'Copy source ' . $type, 'content' => '<p>Copy source body</p>', 'visible' => true]);
            $itemid = $type === 'book' ? $source['first_chapter_id'] : 0;
            content_files::store(\context_module::instance($source['cmid']), 'copy.png', content_files::image(), 'mod_' . $type, $type === 'book' ? 'chapter' : 'content', $itemid);
            $before = $this->source_state($source['cmid']);
            $this->assertCount(1, $before['files'], 'Source preservation must include a real stored file.');
            $copy = (new duplicate_activity())->execute(['cmid' => $source['cmid']]);
            $this->assertTrue($copy['success']);
            $this->assertGreaterThan(0, $copy['new_cmid']);
            $this->assertNotSame($source['cmid'], $copy['new_cmid']);
            $copystate = $this->source_state($copy['new_cmid']);
            $this->assertCount(1, $copystate['files'], 'Native duplication must retain the module-owned file.');
            $this->assertSame((int)$course->id, (int)$copystate['cm']->course);
            $this->assertSame($before['cm']->module, $copystate['cm']->module);
            $this->assertSame($before['cm']->visible, $copystate['cm']->visible);
            $this->assertSame(array_column($before['files'], 'hash'), array_column($copystate['files'], 'hash'));
            $this->assertEquals($before, $this->source_state($source['cmid']));
            if ($type === 'page') {
                $this->assertSame($before['instance']->content, $copystate['instance']->content);
                $content->execute(['action' => 'update_page', 'cmid' => $copy['new_cmid'], 'content' => '<p>Changed copy</p>']);
            } else {
                $this->assertSame(array_column($before['chapters'], 'content'), array_column($copystate['chapters'], 'content'));
                $first = reset($copystate['chapters']);
                $content->execute(['action' => 'update_book_chapter', 'cmid' => $copy['new_cmid'],
                    'chapter_id' => (int)$first->id, 'content' => '<p>Changed copy</p>']);
            }
            $this->assertEquals($before, $this->source_state($source['cmid']), 'Editing the copy must not change its source.');
        }
    }

    public function test_export_json_warning_and_mcp_text_budget_remain_distinct(): void {
        [$course] = $this->fixture();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'visible' => 1,
            'content' => str_repeat('x', 770000)]);
        $tool = new export_course_learning_material();
        $json = $tool->execute(['course_id' => (int)$course->id, 'format' => 'json']);
        $this->assertStringContainsString('750 KB', $json['warning']);
        $this->assertGreaterThan(750 * 1024, strlen(json_encode($json)));
        $this->assertSame(770000, strlen($json['sections'][0]['materials'][0]['content']));
        $markdown = $tool->execute(['course_id' => (int)$course->id]);
        $this->assertTrue($markdown['truncated']);
        $this->assertStringContainsString('[Output truncated at 750 KB.', $markdown['content']);
        set_config('max_mcp_response_bytes', 1024, 'tool_teachermcp');
        $bounded = server::format_tool_result_for_response($json);
        $this->assertLessThanOrEqual(1024, strlen($bounded));
        $this->assertTrue(mb_check_encoding($bounded, 'UTF-8'));
        $this->assertStringContainsString('[Output truncated at 1024 bytes.', $bounded);
        $small = ['content' => "Small caf\u{00e9}"];
        $this->assertSame($small, json_decode(server::format_tool_result_for_response($small), true, 512, JSON_THROW_ON_ERROR));
    }

    private function fixture(): array {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'topics', 'numsections' => 3]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    private function source_state(int $cmid): array {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $type = $DB->get_field('modules', 'name', ['id' => $cm->module], MUST_EXIST);
        $instance = $DB->get_record($type, ['id' => $cm->instance], '*', MUST_EXIST);
        $chapters = $type === 'book' ? array_values($DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum ASC')) : [];
        $files = $DB->get_records_select(
            'files',
            'contextid = :contextid AND filename <> :directory',
            ['contextid' => \context_module::instance($cmid)->id, 'directory' => '.'],
            'component, filearea, itemid, filepath, filename'
        );
        return ['cm' => $cm, 'instance' => $instance, 'chapters' => $chapters,
            'files' => array_values(array_map(static fn($file) => ['id' => (int)$file->id,
                'component' => $file->component, 'area' => $file->filearea, 'itemid' => (int)$file->itemid,
                'path' => $file->filepath, 'name' => $file->filename, 'hash' => $file->contenthash], $files))];
    }
}
