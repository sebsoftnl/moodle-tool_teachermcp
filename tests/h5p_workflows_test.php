<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/h5p_fixtures.php');

use tool_teachermcp\tests\fixtures\h5p_fixtures as fixtures;
use tool_teachermcp\tools\create_h5p_activity;
use tool_teachermcp\tools\get_h5p_libraries;

/**
 * Existing H5P destinations and payload contracts using the pinned core archive.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class h5p_workflows_test extends \advanced_testcase {
    public function test_h5p_filter_miss_is_not_reported_as_an_empty_inventory(): void {
        [$course, $teacher] = $this->fixture(false);
        $tool = new get_h5p_libraries();
        $empty = $tool->execute(['course_id' => (int)$course->id]);
        $this->assertSame(0, $empty['total_installed_types']);
        $this->assertStringContainsString('H5P libraries', $empty['usage_guide']);
        $this->setAdminUser();
        fixtures::install();
        $this->setUser($teacher);
        $match = $tool->execute(['course_id' => (int)$course->id, 'library_filter' => 'Blanks']);
        $this->assertSame(1, $match['total_installed_types']);
        $miss = $tool->execute(['course_id' => (int)$course->id, 'library_filter' => 'NotInstalled']);
        $this->assertSame([], $miss['libraries']);
        $this->assertSame(0, $miss['total_installed_types']);
        $this->assertStringContainsString('match this filter', $miss['usage_guide']);
        $this->assertStringNotContainsString('No runnable H5P libraries are installed', $miss['usage_guide']);
    }

    public function test_empty_and_seeded_inventory_use_native_local_libraries(): void {
        [$course, $teacher] = $this->fixture(false);
        $tool = new get_h5p_libraries();
        $args = ['course_id' => (int)$course->id];
        $empty = $tool->execute($args);
        $this->assertSame(0, $empty['total_installed_types']);
        $this->assertSame([], $empty['libraries']);
        $this->assertNotEmpty($empty['usage_guide']);
        $this->setAdminUser();
        $seed = fixtures::install();
        $this->setUser($teacher);
        $this->assertSame('H5P.Blanks', $seed['library']->machinename);
        $this->assertSame(1, (int)$seed['library']->majorversion);
        $this->assertSame(12, (int)$seed['library']->minorversion);
        $this->assertSame('H5P.Blanks', $seed['package']['manifest']['mainLibrary']);
        $file = get_file_storage()->get_file_by_id($seed['file_id']);
        $this->assertSame($file->get_contenthash(), $seed['content']->contenthash);
        $this->assertSame($file->get_pathnamehash(), $seed['content']->pathnamehash);
        $result = $tool->execute($args + ['library_filter' => 'Blanks', 'include_schema_examples' => false]);
        $this->assertSame(1, $result['total_installed_types']);
        $this->assertSame('H5P.Blanks', $result['libraries'][0]['machine_name']);
        $this->assertSame('1.12', $result['libraries'][0]['latest_version']);
        $this->assertArrayNotHasKey('example_params', $result['libraries'][0]);
    }

    public function test_default_creation_links_hidden_activity_bank_and_native_package(): void {
        global $DB;
        [$course, , $seed] = $this->fixture();
        $result = (new create_h5p_activity())->execute($this->args($course, $seed));
        $this->assertSame('success', $result['status']);
        $this->assertGreaterThan(0, $result['cmid']);
        $this->assertGreaterThan(0, $result['content_bank_id']);
        $this->assertFalse($result['visible']);
        $native = fixtures::activity($result['cmid']);
        $this->assertGreaterThan(0, (int)$native['cm']->instance);
        $this->assertSame((int)$native['cm']->instance, (int)$native['instance']->id);
        $this->assertSame((int)$course->id, (int)$native['instance']->course);
        $this->assertSame(0, (int)$native['cm']->visible);
        $this->assertSame($native['file']['pathnamehash'], $native['h5p']->pathnamehash);
        $this->assertSame($native['file']['contenthash'], $native['h5p']->contenthash);
        $this->assertSame($seed['package']['params'], $native['package']['params']);
        $bank = $DB->get_record('contentbank_content', ['id' => $result['content_bank_id']], '*', MUST_EXIST);
        $this->assertSame((int)\context_course::instance($course->id)->id, (int)$bank->contextid);
        $this->assertSame(1, $DB->count_records('grade_items', ['itemmodule' => 'h5pactivity', 'iteminstance' => $native['instance']->id]));
    }

    public function test_activity_only_preserves_explicit_visibility_without_bank_writes(): void {
        global $DB;
        [$course, , $seed] = $this->fixture();
        $before = $DB->get_records('contentbank_content', [], 'id ASC');
        foreach ([false, true] as $visible) {
            $result = (new create_h5p_activity())->execute($this->args($course, $seed) + [
                'add_to_content_bank' => false, 'visible' => $visible]);
            $this->assertNull($result['content_bank_id']);
            $this->assertSame($visible, $result['visible']);
            $native = fixtures::activity($result['cmid']);
            $this->assertSame((int)$visible, (int)$native['cm']->visible);
            $this->assertSame($seed['package']['params'], $native['package']['params']);
        }
        $this->assertEquals($before, $DB->get_records('contentbank_content', [], 'id ASC'));
    }

    public function test_bank_only_omits_activity_identity_and_visibility(): void {
        global $DB;
        [$course, , $seed] = $this->fixture();
        $before = $DB->get_records('course_modules', [], 'id ASC');
        $result = (new create_h5p_activity())->execute($this->args($course, $seed) + ['deploy_as_activity' => false]);
        $this->assertSame('success', $result['status']);
        $this->assertNull($result['cmid']);
        $this->assertNull($result['view_url']);
        $this->assertArrayNotHasKey('visible', $result);
        $this->assertGreaterThan(0, $result['content_bank_id']);
        $this->assertEquals($before, $DB->get_records('course_modules', [], 'id ASC'));
    }

    public function test_both_destinations_false_preserve_existing_noop_shape_and_state(): void {
        [$course, , $seed] = $this->fixture();
        $before = fixtures::snapshot((int)$course->id);
        $result = (new create_h5p_activity())->execute($this->args($course, $seed) + [
            'deploy_as_activity' => false, 'add_to_content_bank' => false]);
        $this->assertSame('success', $result['status']);
        $this->assertNull($result['cmid']);
        $this->assertNull($result['view_url']);
        $this->assertNull($result['content_bank_id']);
        $this->assertArrayNotHasKey('visible', $result);
        $this->assertEquals($before, fixtures::snapshot((int)$course->id));
    }

    public function test_raw_package_preserves_nested_booleans_and_literal_strings(): void {
        [$course, , $seed] = $this->fixture();
        $params = $seed['package']['params'];
        $params['behaviour']['enableRetry'] = false;
        $params['behaviour']['enableSolutionsButton'] = true;
        $params['owned_literal_values'] = ['false' => 'false', 'true' => 'true', 'zero' => '0'];
        $arguments = array_replace($this->args($course, $seed), ['params' => $params,
            'add_to_content_bank' => 'false', 'visible' => 'false']);
        $result = (new server())->execute_tool('create_h5p_activity', $arguments,
            (object)['permissions' => json_encode(['all_teacher_tools' => true])]);
        $this->assertFalse($result['visible']);
        $this->assertNull($result['content_bank_id']);
        $this->assertSame($params, fixtures::activity($result['cmid'])['package']['params']);
    }

    public function test_direct_json_object_string_preserves_existing_compatibility(): void {
        [$course, , $seed] = $this->fixture();
        $arguments = array_replace($this->args($course, $seed), [
            'params' => json_encode($seed['package']['params'], JSON_THROW_ON_ERROR), 'add_to_content_bank' => false]);
        $result = (new create_h5p_activity())->execute($arguments);
        $this->assertSame($seed['package']['params'], fixtures::activity($result['cmid'])['package']['params']);
    }

    public function test_existing_depth_and_compact_byte_guard_boundaries(): void {
        [$course, , $seed] = $this->fixture();
        $base = $this->args($course, $seed) + ['deploy_as_activity' => false, 'add_to_content_bank' => false];
        $tool = new create_h5p_activity();
        $depth20 = ['leaf' => 'value'];
        for ($i = 1; $i < 20; $i++) {
            $depth20 = ['level' => $depth20];
        }
        $bytes = 2621440;
        $boundary = ['text' => str_repeat('x', $bytes - strlen(json_encode(['text' => ''])))];
        $this->assertSame($bytes, strlen(json_encode($boundary)));
        foreach ([$depth20, $boundary] as $params) {
            $before = fixtures::snapshot((int)$course->id);
            $result = $tool->execute(array_replace($base, ['params' => $params]));
            $this->assertSame('success', $result['status']);
            $this->assertNull($result['cmid']);
            $this->assertEquals($before, fixtures::snapshot((int)$course->id));
        }
        foreach ([[['level' => $depth20], 'error_params_too_deep'],
                [['text' => $boundary['text'] . 'x'], 'error_invalid_params']] as [$params, $code]) {
            $before = fixtures::snapshot((int)$course->id);
            try {
                $tool->execute(array_replace($base, ['params' => $params]));
                $this->fail('H5P guard overflow must reject before persistent writes.');
            } catch (\moodle_exception $error) {
                $this->assertSame($code, $error->errorcode);
            }
            $this->assertEquals($before, fixtures::snapshot((int)$course->id));
        }
    }

    public function test_missing_disabled_library_and_invalid_section_do_not_write(): void {
        global $DB;
        [$course, , $seed] = $this->fixture();
        $tool = new create_h5p_activity();
        $base = $this->args($course, $seed);
        foreach ([['library_name' => 'H5P.NotInstalled'], ['section_number' => -1], ['section_number' => 99]] as $extra) {
            $before = fixtures::snapshot((int)$course->id);
            try {
                $tool->execute(array_replace($base, $extra));
                $this->fail('Missing library or section must reject without content writes.');
            } catch (\moodle_exception $error) {
                $this->assertSame(isset($extra['library_name']) ? 'error_library_not_found' : 'sectionnotexist', $error->errorcode);
            }
            $this->assertEquals($before, fixtures::snapshot((int)$course->id));
        }
        $DB->set_field('h5p_libraries', 'enabled', 0, ['id' => $seed['library']->id]);
        $before = fixtures::snapshot((int)$course->id);
        $inventory = (new get_h5p_libraries())->execute(['course_id' => (int)$course->id, 'library_filter' => 'Blanks']);
        $this->assertSame(0, $inventory['total_installed_types']);
        try {
            $tool->execute($base);
            $this->fail('Disabled requested library must reject before writes.');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_library_not_found', $error->errorcode);
        }
        $this->assertEquals($before, fixtures::snapshot((int)$course->id));
    }

    private function fixture(bool $seed = true): array {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'topics', 'numsections' => 2]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        \context_user::instance($teacher->id);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Preserved H5P sibling', 'content' => '<p>Keep this page</p>']);
        $installed = $seed ? fixtures::install() : null;
        $this->setUser($teacher);
        get_fast_modinfo($course->id);
        return [$course, $teacher, $installed];
    }

    private function args(\stdClass $course, array $seed): array {
        return ['course_id' => (int)$course->id, 'title' => 'Owned local Blanks',
            'library_name' => 'H5P.Blanks', 'params' => $seed['package']['params']];
    }
}
