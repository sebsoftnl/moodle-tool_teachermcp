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
 * Explicit selection, preservation and resource-budget boundaries.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_boundaries_test extends \advanced_testcase {
    public function test_explicit_invalid_file_ids_never_select_another_file(): void {
        [$course, $teacher] = $this->fixture();
        $generator = $this->getDataGenerator();
        $assignment = $generator->create_module('assign', ['course' => $course->id]);
        $context = \context_module::instance($assignment->cmid);
        content_files::store($context, 'brief.txt', 'Public brief', 'mod_assign', 'introattachment');
        $private = content_files::store(
            $context,
            'submission.txt',
            'Private submission',
            'assignsubmission_file',
            'submission_files',
            1
        );
        $this->setAdminUser();
        $othercourse = $generator->create_course();
        $otherfolder = $generator->create_module('folder', ['course' => $othercourse->id]);
        $foreign = content_files::store(\context_module::instance($otherfolder->cmid), 'foreign.txt', 'Foreign content', 'mod_folder');
        $this->setUser($teacher);
        $outcomes = [];
        foreach (
            ['zero' => 0, 'negative' => -1, 'missing' => (int)$foreign->get_id() + 1000,
                'foreign' => (int)$foreign->get_id(), 'disallowed' => (int)$private->get_id()] as $name => $fileid
        ) {
            $outcomes[$name] = $this->selection((int)$assignment->cmid, ['fileid' => $fileid]);
        }
        $this->assertSame(
            array_fill_keys(array_keys($outcomes), 'filenotfound'),
            $outcomes,
            'An explicit invalid ID must reject, never substitute the sole allowed file.'
        );
    }

    public function test_explicit_filename_requires_a_match_even_with_zero_or_one_file(): void {
        [$course] = $this->fixture();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $outcomes = ['empty-area' => $this->selection((int)$folder->cmid, ['filename' => 'missing.txt'])];
        content_files::store(\context_module::instance($folder->cmid), 'only.txt', 'Only allowed content', 'mod_folder');
        foreach (['blank' => '', 'spaces' => '   ', 'unmatched' => 'missing.txt'] as $name => $filename) {
            $outcomes[$name] = $this->selection((int)$folder->cmid, ['filename' => $filename]);
        }
        $this->assertSame(array_fill_keys(array_keys($outcomes), 'filenotfound'), $outcomes);
    }

    public function test_file_id_precedence_does_not_depend_on_enumeration_order(): void {
        [$course] = $this->fixture();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $context = \context_module::instance($folder->cmid);
        $alpha = content_files::store($context, 'Alpha.txt', 'Alpha content', 'mod_folder');
        $bravo = content_files::store($context, 'Bravo.txt', 'Bravo content', 'mod_folder');
        $actual = [
            $this->selection((int)$folder->cmid, ['fileid' => (int)$alpha->get_id(), 'filename' => 'Bravo.txt']),
            $this->selection((int)$folder->cmid, ['fileid' => (int)$bravo->get_id(), 'filename' => 'Alpha.txt']),
            $this->selection((int)$folder->cmid, ['fileid' => (int)$bravo->get_id() + 1000, 'filename' => 'Alpha.txt']),
        ];
        $this->assertSame(['selected:' . $alpha->get_id(), 'selected:' . $bravo->get_id(), 'filenotfound'], $actual);
    }

    public function test_reader_preserves_existing_character_budget_clamps(): void {
        [$course] = $this->fixture();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $text = str_repeat("\u{03a9}", 160001);
        content_files::store(\context_module::instance($folder->cmid), 'unicode.txt', $text, 'mod_folder');
        foreach (
            [[[], 40000], [['max_length' => 0], 40000], [['max_length' => 1], 1000],
                [['max_length' => -1], 1000], [['max_length' => 200000], 150000]] as [$params, $limit]
        ) {
            $result = (new read_activity_file())->execute(['cmid' => (int)$folder->cmid] + $params);
            $this->assertSame(
                mb_substr($text, 0, $limit) . "\n\n... [Content truncated at {$limit} characters]",
                $result['selected_file']['content_text']
            );
        }
    }

    public function test_image_limits_apply_to_decoded_bytes_with_all_padding_variants(): void {
        global $DB, $CFG;
        [$course] = $this->fixture();
        set_config('maxbytes', 20 * 1024 * 1024);
        $actual = [];
        $expected = [];
        $padding = [];
        foreach ([1536, 1537, 1538] as $limit) {
            $DB->set_field('course', 'maxbytes', $limit, ['id' => $course->id]);
            $this->assertSame($limit, (int)get_max_upload_file_size($CFG->maxbytes, $limit));
            foreach ([-1, 0, 1] as $offset) {
                $bytes = content_files::png_at_size($limit + $offset);
                $this->assertSame($limit + $offset, strlen($bytes));
                $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($bytes)[2]);
                if ($offset === 0) {
                    $encoded = base64_encode($bytes);
                    $padding[] = strlen($encoded) - strlen(rtrim($encoded, '='));
                }
                $key = "{$limit}/{$offset}";
                $before = [$DB->count_records('files'), $DB->count_records('tool_teachermcp_assets')];
                $actual[$key] = $this->upload_result((int)$course->id, "boundary-{$limit}-{$offset}.png", $bytes);
                $expected[$key] = $offset <= 0 ? 'success' : 'error_file_too_large';
                if ($offset > 0) {
                    $this->assertSame($before, [$DB->count_records('files'), $DB->count_records('tool_teachermcp_assets')]);
                }
            }
        }
        sort($padding);
        $this->assertSame([0, 1, 2], $padding);
        $this->assertSame($expected, $actual, 'Base64 padding must not reject a valid image within the decoded byte limit.');
    }

    public function test_image_hard_cap_accepts_exactly_ten_mebibytes(): void {
        global $DB, $CFG;
        [$course] = $this->fixture();
        $hardlimit = 10 * 1024 * 1024;
        set_config('maxbytes', $hardlimit * 2);
        $DB->set_field('course', 'maxbytes', $hardlimit * 2, ['id' => $course->id]);
        $this->assertGreaterThan($hardlimit, get_max_upload_file_size($CFG->maxbytes, $hardlimit * 2));
        $actual = [];
        foreach ([-1, 0, 1] as $offset) {
            $actual[$offset] = $this->upload_result(
                (int)$course->id,
                "hard-{$offset}.png",
                content_files::png_at_size($hardlimit + $offset)
            );
        }
        $this->assertSame([-1 => 'success', 0 => 'success', 1 => 'error_file_too_large'], $actual);
    }

    public function test_page_asset_upload_requires_target_module_manage_capability_before_writes(): void {
        global $DB;
        [$course, $teacher] = $this->fixture();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $role, $context->id);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/course:manageactivities', \context_course::instance($course->id)));
        $this->assertTrue(has_capability('moodle/course:managefiles', \context_course::instance($course->id)));
        $this->assertFalse(has_capability('moodle/course:manageactivities', $context));
        $before = $this->settings_state($page);
        $assets = $DB->get_records('tool_teachermcp_assets', [], 'id ASC');
        try {
            (new upload_course_asset())->execute(['course_id' => (int)$course->id,
                'target_cmid' => (int)$page->cmid, 'filename' => 'denied.png',
                'file_content_base64' => base64_encode(content_files::image())]);
            $this->fail('A Page asset upload must require target-module manage capability before writing files.');
        } catch (\required_capability_exception $error) {
            $this->assertStringContainsString(get_capability_string('moodle/course:manageactivities'), $error->getMessage());
        }
        $this->assertEquals($before, $this->settings_state($page));
        $this->assertEquals($assets, $DB->get_records('tool_teachermcp_assets', [], 'id ASC'));
    }

    public function test_denied_combined_settings_update_has_no_persisted_or_cache_effect(): void {
        global $DB;
        [$course, $teacher] = $this->fixture();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'name' => 'Protected settings', 'content' => '<p>Protected body</p>']);
        content_files::store(\context_module::instance($page->cmid), 'keep.png', content_files::image(), 'mod_page');
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:activityvisibility', CAP_PROHIBIT, $role, \context_course::instance($course->id)->id);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/course:manageactivities', \context_module::instance($page->cmid)));
        $this->assertFalse(has_capability('moodle/course:activityvisibility', \context_module::instance($page->cmid)));
        $before = $this->settings_state($page);
        $sink = $this->redirectEvents();
        $outcomes = [];
        try {
            foreach ([true, false] as $visible) {
                try {
                    (new manage_activity())->execute(['action' => 'update_settings', 'cmid' => (int)$page->cmid,
                        'name' => 'Must not persist', 'intro' => 'Must not persist', 'visible' => $visible]);
                    $this->fail('Explicit visibility requires capability before any settings write.');
                } catch (\required_capability_exception $error) {
                    $this->assertStringContainsString(get_capability_string('moodle/course:activityvisibility'), $error->getMessage());
                }
                $outcomes[] = $this->settings_state($page);
            }
            $events = $sink->get_events();
        } finally {
            $sink->close();
        }
        $this->assertEquals([$before, $before], $outcomes, 'Denied settings changes must preserve rows, files and fresh modinfo.');
        $this->assertCount(0, array_filter($events, static fn($event) => $event instanceof \core\event\course_module_updated));
    }

    public function test_settings_name_update_preserves_existing_display_flags(): void {
        global $DB;
        [$course] = $this->fixture();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'printintro' => 1, 'printlastmodified' => 1]);
        $before = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $options = unserialize_array($before->displayoptions);
        $this->assertSame(1, (int)$options['printintro']);
        $this->assertSame(1, (int)$options['printlastmodified']);
        (new manage_activity())->execute(['action' => 'update_settings', 'cmid' => (int)$page->cmid, 'name' => 'Renamed only']);
        $after = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $this->assertSame($options, unserialize_array($after->displayoptions));
        $this->assertSame($before->content, $after->content);
        $this->assertSame((int)$before->revision + 1, (int)$after->revision);
    }

    public function test_settings_name_update_preserves_popup_dimensions(): void {
        global $DB, $CFG;
        [$course] = $this->fixture();
        require_once($CFG->libdir . '/resourcelib.php');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'display' => RESOURCELIB_DISPLAY_POPUP, 'popupwidth' => 420, 'popupheight' => 320,
            'printintro' => 1, 'printlastmodified' => 1]);
        $before = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $options = unserialize_array($before->displayoptions);
        $this->assertSame(420, (int)$options['popupwidth']);
        $this->assertSame(320, (int)$options['popupheight']);
        (new manage_activity())->execute(['action' => 'update_settings', 'cmid' => (int)$page->cmid, 'name' => 'Popup renamed']);
        $after = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $this->assertSame($options, unserialize_array($after->displayoptions));
        $this->assertSame($before->display, $after->display);
    }

    public function test_duplicate_invalid_sections_reject_before_restore_writes(): void {
        global $DB;
        [$course, $teacher] = $this->fixture();
        $target = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $this->getDataGenerator()->enrol_user($teacher->id, $target->id, 'editingteacher');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $tables = ['course_modules', 'page', 'book', 'book_chapters', 'files'];
        $before = array_map(static fn($table) => $DB->count_records($table), $tables);
        $outcomes = [];
        foreach ([(int)$course->id, (int)$target->id] as $courseid) {
            foreach ([-1, 99] as $section) {
                try {
                    $result = (new duplicate_activity())->execute(['cmid' => (int)$page->cmid,
                        'target_course_id' => $courseid, 'section_number' => $section]);
                    $outcomes[] = 'returned:' . $result['new_cmid'];
                } catch (\moodle_exception $error) {
                    $outcomes[] = $error->errorcode;
                }
            }
        }
        $after = array_map(static fn($table) => $DB->count_records($table), $tables);
        $this->assertSame($before, $after, 'Invalid target sections must reject before creating any copied content or files.');
        $this->assertSame(array_fill(0, 4, 'sectionnotexist'), $outcomes);
    }

    public function test_page_copy_targets_empty_same_course_sections(): void {
        $this->assert_copy_placement('page', false);
    }

    public function test_book_copy_targets_empty_same_course_sections(): void {
        $this->assert_copy_placement('book', false);
    }

    public function test_page_copy_targets_empty_other_course_sections(): void {
        $this->assert_copy_placement('page', true);
    }

    public function test_book_copy_targets_empty_other_course_sections(): void {
        $this->assert_copy_placement('book', true);
    }

    public function test_markdown_budget_cannot_split_a_unicode_character(): void {
        global $DB;
        [$course] = $this->fixture();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'name' => 'Unicode budget', 'content' => 'SEED_SENTINEL', 'visible' => 1]);
        $tool = new export_course_learning_material();
        $small = $tool->execute(['course_id' => (int)$course->id]);
        $offset = strpos($small['content'], 'SEED_SENTINEL');
        $this->assertNotFalse($offset);
        $remaining = 750 * 1024 - $offset;
        $content = str_repeat('x', ($remaining - 1) % 3) . str_repeat("\u{20ac}", 260000);
        $DB->set_field('page', 'content', $content, ['id' => $page->id]);
        $result = $tool->execute(['course_id' => (int)$course->id]);
        $this->assertTrue($result['truncated']);
        $this->assertTrue(mb_check_encoding($result['content'], 'UTF-8'), 'The Markdown prefix must stop at a complete UTF-8 character.');
        $this->assertNotFalse(json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('[Output truncated at 750 KB.', $result['content']);
    }

    public function test_export_preserves_unsupported_and_partial_document_notices(): void {
        [$course] = $this->fixture();
        $oversize = content_files::office_xml('pptx', 'Omitted text', file_extractor::MAX_ZIP_ENTRY_SIZE + 1);
        $partial = content_files::package(['ppt/slides/slide1.xml' => content_files::office_xml('pptx', 'Readable text'),
            'ppt/slides/slide2.xml' => $oversize]);
        unset($oversize);
        $inputs = ['scan.pdf' => content_files::pdf(['q 100 0 0 100 10 10 cm /Im0 Do Q'], false, true), 'slides.pptx' => $partial];
        $expected = [];
        $actual = [];
        foreach ($inputs as $filename => $bytes) {
            foreach (['resource' => 'content', 'folder' => 'content', 'assign' => 'introattachment'] as $type => $area) {
                $module = $this->getDataGenerator()->create_module($type, ['course' => $course->id, 'visible' => 1]);
                $file = content_files::store(\context_module::instance($module->cmid), $filename, $bytes, 'mod_' . $type, $area);
                $extraction = file_extractor::extract_text($file);
                $export = (new export_course_learning_material())->execute(['course_id' => (int)$course->id, 'format' => 'json']);
                $materials = array_merge(...array_column($export['sections'], 'materials'));
                $material = array_values(array_filter($materials, static fn($row) => $row['cmid'] === (int)$module->cmid))[0];
                $key = $filename . '/' . $type;
                $actual[$key] = ['notice' => !empty($extraction['notice']),
                    'exported' => !empty($extraction['notice']) && str_contains($material['content'], $extraction['notice'])];
                $expected[$key] = ['notice' => true, 'exported' => true];
            }
        }
        $this->assertSame($expected, $actual, 'Every extraction limitation must survive the export consumer.');
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

    private function selection(int $cmid, array $selector): string {
        try {
            $result = (new read_activity_file())->execute(['cmid' => $cmid] + $selector);
            return isset($result['selected_file']) ? 'selected:' . $result['selected_file']['fileid'] : 'unselected';
        } catch (\moodle_exception $error) {
            return $error->errorcode;
        }
    }

    private function upload_result(int $courseid, string $filename, string $bytes): string {
        try {
            $result = (new upload_course_asset())->execute(['course_id' => $courseid, 'filename' => $filename,
                'file_content_base64' => base64_encode($bytes)]);
            $this->assertSame(strlen($bytes), (int)$result['filesize']);
            $this->assertSame(sha1($bytes), get_file_storage()->get_file_by_id($result['file_id'])->get_contenthash());
            return $result['status'];
        } catch (\moodle_exception $error) {
            return $error->errorcode;
        }
    }

    private function settings_state(\stdClass $page): array {
        global $DB;
        get_fast_modinfo($page->course, 0, true);
        $cm = get_fast_modinfo($page->course)->get_cm($page->cmid);
        return ['page' => $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST),
            'cm' => $DB->get_record('course_modules', ['id' => $page->cmid], '*', MUST_EXIST),
            'course' => $DB->get_record('course', ['id' => $page->course], '*', MUST_EXIST),
            'files' => $DB->get_records('files', [], 'id ASC'),
            'modinfo' => ['name' => $cm->name, 'visible' => (int)$cm->visible, 'section' => (int)$cm->sectionnum]];
    }

    private function assert_copy_placement(string $type, bool $crosscourse): void {
        global $DB;
        [$course, $teacher] = $this->fixture();
        $target = $course;
        if ($crosscourse) {
            $target = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
            $this->getDataGenerator()->enrol_user($teacher->id, $target->id, 'editingteacher');
        }
        $source = (new manage_content_activity())->execute(['action' => 'create_' . $type, 'course_id' => (int)$course->id,
            'section_number' => 1, 'title' => 'Placement source',
            'content' => '<p>Preserved source</p><img src="@@PLUGINFILE@@/copy.png" alt="Copy">']);
        $itemid = $type === 'book' ? $source['first_chapter_id'] : 0;
        $file = content_files::store(
            \context_module::instance($source['cmid']),
            'copy.png',
            content_files::image(),
            'mod_' . $type,
            $type === 'book' ? 'chapter' : 'content',
            $itemid
        );
        $sourcecm = $DB->get_record('course_modules', ['id' => $source['cmid']], '*', MUST_EXIST);
        $instance = $DB->get_record($type, ['id' => $sourcecm->instance], '*', MUST_EXIST);
        foreach ([3, 0] as $section) {
            $targetsection = get_fast_modinfo($target->id)->get_section_info($section, MUST_EXIST);
            $this->assertSame('', $targetsection->sequence, 'The destination fixture must really be empty.');
            $copy = (new duplicate_activity())->execute(['cmid' => $source['cmid'],
                'target_course_id' => (int)$target->id, 'section_number' => $section]);
            $this->assertTrue($copy['success']);
            $this->assertGreaterThan(0, $copy['new_cmid']);
            $this->assertNotSame($source['cmid'], $copy['new_cmid']);
            $copiedcm = $DB->get_record('course_modules', ['id' => $copy['new_cmid']], '*', MUST_EXIST);
            $this->assertSame((int)$target->id, (int)$copiedcm->course);
            $this->assertSame((int)$targetsection->id, (int)$copiedcm->section, 'An existing empty target section must be honored.');
            $this->assertSame($sourcecm->module, $copiedcm->module);
            $this->assertSame($sourcecm->visible, $copiedcm->visible);
            $copyfile = $DB->get_record('files', ['contextid' => \context_module::instance($copy['new_cmid'])->id,
                'component' => 'mod_' . $type, 'filename' => 'copy.png'], '*', MUST_EXIST);
            $this->assertSame($file->get_contenthash(), $copyfile->contenthash);
            $this->assertEquals($sourcecm, $DB->get_record('course_modules', ['id' => $source['cmid']], '*', MUST_EXIST));
            $this->assertEquals($instance, $DB->get_record($type, ['id' => $sourcecm->instance], '*', MUST_EXIST));
        }
    }
}
