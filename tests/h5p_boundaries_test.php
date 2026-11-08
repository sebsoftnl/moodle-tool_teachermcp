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

require_once(__DIR__ . '/fixtures/h5p_fixtures.php');

use tool_teachermcp\tests\fixtures\h5p_fixtures as fixtures;
use tool_teachermcp\tests\fixtures\failed_h5p_save;
use tool_teachermcp\tools\create_h5p_activity;

/**
 * H5P authorization, reporting, input and native persistence boundaries.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class h5p_boundaries_test extends \advanced_testcase {
    public function test_bank_only_does_not_require_activity_visibility_or_addinstance(): void {
        global $DB;
        [$course, $teacher, $seed] = $this->fixture();
        $this->deny($course, $teacher, 'moodle/course:activityvisibility');
        $this->deny($course, $teacher, 'mod/h5pactivity:addinstance');
        $before = $DB->get_records('course_modules', [], 'id ASC');
        $result = (new create_h5p_activity())->execute($this->args($course, $seed) + [
            'deploy_as_activity' => false, 'visible' => true]);
        $this->assertGreaterThan(0, $result['content_bank_id']);
        $this->assertNull($result['cmid']);
        $this->assertArrayNotHasKey('visible', $result);
        $this->assertEquals($before, $DB->get_records('course_modules', [], 'id ASC'));
    }

    public function test_addinstance_denial_precedes_content_bank_and_file_writes(): void {
        [$course, $teacher, $seed] = $this->fixture();
        $this->deny($course, $teacher, 'mod/h5pactivity:addinstance');
        $this->assert_unchanged_error(
            $course,
            fn() => (new create_h5p_activity())->execute($this->args($course, $seed)),
            'nopermissions',
            get_capability_string('mod/h5pactivity:addinstance')
        );
    }

    public function test_no_destination_success_explicitly_reports_no_storage_or_deployment(): void {
        [$course, , $seed] = $this->fixture();
        $before = fixtures::snapshot((int)$course->id);
        $result = (new create_h5p_activity())->execute($this->args($course, $seed) + [
            'deploy_as_activity' => false, 'add_to_content_bank' => false]);
        $this->assertSame('success', $result['status']);
        $this->assertNull($result['cmid']);
        $this->assertNull($result['content_bank_id']);
        $this->assertEquals($before, fixtures::snapshot((int)$course->id));
        $this->assertStringContainsString('nothing was stored or deployed', strtolower($result['message']));
    }

    public function test_bank_only_upload_denial_cannot_claim_success(): void {
        [$course, $teacher, $seed] = $this->fixture();
        $this->deny($course, $teacher, 'moodle/contentbank:upload');
        $this->assert_unchanged_error($course, fn() => (new create_h5p_activity())->execute($this->args($course, $seed)
            + ['deploy_as_activity' => false]), 'nopermissions', get_capability_string('moodle/contentbank:upload'));
    }

    public function test_optional_bank_denial_retains_authorized_activity_and_warning(): void {
        global $DB;
        [$course, $teacher, $seed] = $this->fixture();
        $this->deny($course, $teacher, 'moodle/contentbank:upload');
        $before = $DB->get_records('contentbank_content', [], 'id ASC');
        $result = (new create_h5p_activity())->execute($this->args($course, $seed));
        $this->assertSame('success', $result['status']);
        $this->assertGreaterThan(0, $result['cmid']);
        $this->assertNull($result['content_bank_id']);
        $this->assertNotEmpty($result['warning']);
        $this->assertEquals($before, $DB->get_records('contentbank_content', [], 'id ASC'));
        $this->assertSame($seed['package']['params'], fixtures::activity($result['cmid'])['package']['params']);
    }

    public function test_bank_storage_keeps_only_the_native_content_file(): void {
        [$course, , $seed] = $this->fixture();
        $context = \context_course::instance($course->id);
        $result = (new create_h5p_activity())->execute($this->args($course, $seed) + ['deploy_as_activity' => false]);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'contentbank', 'public', $result['content_bank_id'], 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame($seed['package']['params'], fixtures::package(reset($files))['params']);
        $this->assertSame(
            [],
            $fs->get_area_files($context->id, 'contentbank', 'public', 0, 'id', false),
            'Content Bank import must not retain its temporary itemid=0 package.'
        );
    }

    public function test_unsupported_bank_only_upload_is_an_error_without_writes(): void {
        $this->preventResetByRollback();
        [$course, $teacher, $seed] = $this->fixture();
        $this->deny($course, $teacher, 'contenttype/h5p:upload');
        $context = \context_course::instance($course->id);
        $this->assertNull((new \core_contentbank\contentbank())->get_extension_supporter('.h5p', $context));
        $this->assert_unchanged_error($course, fn() => (new create_h5p_activity())->execute($this->args($course, $seed)
            + ['deploy_as_activity' => false]), 'error_h5p_save_failed');
    }

    public function test_mandatory_course_deploy_and_publication_capabilities_precede_writes(): void {
        global $DB;
        [$course, $teacher, $seed] = $this->fixture();
        $context = \context_course::instance($course->id);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['moodle/course:manageactivities', 'moodle/h5p:deploy', 'moodle/course:activityvisibility'] as $capability) {
            $this->deny($course, $teacher, $capability);
            try {
                $this->assert_unchanged_error($course, fn() => (new create_h5p_activity())->execute(
                    $this->args($course, $seed) + ['visible' => true]
                ), 'nopermissions', get_capability_string($capability));
            } finally {
                unassign_capability($capability, $role, $context->id);
                accesslib_clear_all_caches(true);
                $this->setUser($teacher);
            }
        }
    }

    public function test_nonmember_course_request_does_not_create_destinations(): void {
        [$course, , $seed] = $this->fixture();
        $other = $this->getDataGenerator()->create_course();
        $this->assert_unchanged_error($course, fn() => (new create_h5p_activity())->execute(
            array_replace($this->args($course, $seed), ['course_id' => (int)$other->id])
        ), 'nopermissions');
    }

    public function test_scalar_list_and_empty_json_roots_reject_before_creation(): void {
        [$course, , $seed] = $this->fixture();
        $outcomes = [];
        foreach (
            ['false' => 'false', 'true' => 'true', 'number' => '42', 'string' => '"text"',
                'json-list' => '["one","two"]', 'php-list' => ['one', 'two'], 'empty-object' => new \stdClass(),
                'empty-json-object' => '{}'] as $key => $params
        ) {
            $before = fixtures::snapshot((int)$course->id);
            $code = null;
            try {
                (new create_h5p_activity())->execute(array_replace($this->args($course, $seed), [
                    'title' => 'Invalid root ' . $key, 'params' => $params, 'add_to_content_bank' => false]));
            } catch (\moodle_exception $error) {
                $code = $error->errorcode;
            }
            $outcomes[$key] = ['code' => $code, 'unchanged' => $before == fixtures::snapshot((int)$course->id)];
        }
        $this->assertSame(
            array_fill_keys(array_keys($outcomes), ['code' => 'error_invalid_params', 'unchanged' => true]),
            $outcomes,
            'H5P content must be a nonempty JSON object, never a scalar or list deployment.'
        );
    }

    public function test_malformed_json_and_unencodable_values_reject_before_creation(): void {
        [$course, , $seed] = $this->fixture();
        $outcomes = [];
        foreach (
            ['syntax' => '{', 'utf8' => ['text' => "\xb1\x31"], 'infinite' => ['value' => INF],
                'nan' => ['value' => NAN]] as $key => $params
        ) {
            $before = fixtures::snapshot((int)$course->id);
            $code = null;
            try {
                (new create_h5p_activity())->execute(array_replace($this->args($course, $seed), [
                    'title' => 'Invalid encoding ' . $key, 'params' => $params, 'add_to_content_bank' => false]));
            } catch (\moodle_exception $error) {
                $code = $error->errorcode;
            }
            $outcomes[$key] = ['code' => $code, 'unchanged' => $before == fixtures::snapshot((int)$course->id)];
        }
        $this->assertSame(
            array_fill_keys(array_keys($outcomes), ['code' => 'error_invalid_params', 'unchanged' => true]),
            $outcomes,
            'Encoding failure must be an input error before any destination is written.'
        );
    }

    public function test_invalid_bank_only_content_does_not_leave_a_staging_file(): void {
        [$course, , $seed] = $this->fixture();
        $this->assert_unchanged_error($course, fn() => (new create_h5p_activity())->execute(
            array_replace($this->args($course, $seed), ['params' => 'false', 'deploy_as_activity' => false])
        ), 'error_invalid_params');
    }

    public function test_native_false_save_rolls_back_real_destination_writes(): void {
        $this->assert_failed_native_save(false);
    }

    public function test_native_null_save_rolls_back_real_destination_writes(): void {
        $this->assert_failed_native_save(true);
    }

    private function assert_failed_native_save(bool $returnnull): void {
        $this->preventResetByRollback();
        [$course, , $seed] = $this->fixture();
        $tool = new failed_h5p_save();
        $tool->returnnull = $returnnull;
        $before = fixtures::snapshot((int)$course->id);
        $code = null;
        try {
            $tool->execute($this->args($course, $seed));
        } catch (\moodle_exception $error) {
            $code = $error->errorcode;
        }
        $this->assertNotNull($tool->nativewrite);
        $this->assertGreaterThan(0, $tool->nativewrite['h5p_id']);
        foreach (['h5p_exists', 'instance_exists', 'file_exists'] as $key) {
            $this->assertTrue($tool->nativewrite[$key], 'Fault must follow real native destination writes.');
        }
        $this->assertEquals(
            $before,
            fixtures::snapshot((int)$course->id),
            'A failed native save must not leave bank, module, package, grade or cached course state.'
        );
        $this->assertSame('error_h5p_save_failed', $code);
    }

    private function assert_unchanged_error(\stdClass $course, callable $operation, string $code, ?string $message = null): void {
        $before = fixtures::snapshot((int)$course->id);
        $caught = null;
        try {
            $operation();
        } catch (\moodle_exception $error) {
            $caught = $error;
        }
        $this->assertEquals($before, fixtures::snapshot((int)$course->id), 'Rejected H5P request must preserve destination state.');
        $this->assertNotNull($caught, 'Rejected H5P request must report an explicit error.');
        $this->assertSame($code, $caught->errorcode);
        if ($message !== null) {
            $this->assertStringContainsString($message, $caught->getMessage());
        }
    }

    private function fixture(): array {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'topics', 'numsections' => 2]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        \context_user::instance($teacher->id);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Preserved H5P sibling', 'content' => '<p>Keep this page</p>']);
        $seed = fixtures::install();
        $this->setUser($teacher);
        get_fast_modinfo($course->id);
        return [$course, $teacher, $seed];
    }

    private function args(\stdClass $course, array $seed): array {
        return ['course_id' => (int)$course->id, 'title' => 'Owned H5P boundary',
            'library_name' => 'H5P.Blanks', 'params' => $seed['package']['params']];
    }

    private function deny(\stdClass $course, \stdClass $teacher, string $capability): void {
        global $DB;
        $context = \context_course::instance($course->id);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $role, $context->id, true);
        accesslib_clear_all_caches(true);
        $this->setUser($teacher);
        $this->assertFalse(has_capability($capability, $context));
    }
}
