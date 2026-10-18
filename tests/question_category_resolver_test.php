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

use core_question\local\bank\question_bank_helper;
use tool_teachermcp\tools\manage_question_bank;
use tool_teachermcp\tools\scaffold_assessment_unit;

/**
 * Native question-category selection and creation permissions.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_category_resolver_test extends \advanced_testcase {
    public function test_default_creation_uses_native_context_and_reuses_category(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);

        $first = $this->create_question($course, ['name' => 'First native question']);
        $this->assertTrue($first['success']);
        $category = $this->question_category($first['question_id']);
        $this->assertSame((int)$category->id, $first['category_id']);
        $context = \context::instance_by_id($category->contextid);
        $this->assertSame((int)$course->id, (int)$context->get_course_context()->instanceid);
        $this->assertGreaterThan(0, (int)$category->parent);

        if ($this->is_modern()) {
            $this->assertSame(CONTEXT_MODULE, $context->contextlevel);
            $cm = get_fast_modinfo($course)->get_cm($context->instanceid);
            $this->assertSame('qbank', $cm->modname);
            $this->assertSame(question_bank_helper::TYPE_STANDARD,
                $DB->get_field('qbank', 'type', ['id' => $cm->instance], MUST_EXIST));
            $this->assertSame(question_bank_helper::get_bank_name_string('defaultbank', 'core_question',
                ['coursename' => $course->fullname]), $cm->name);
            $this->assertSame(0, (int)$cm->visible);
            $this->assertSame(0, (int)$DB->get_field('course_modules', 'visibleoncoursepage', ['id' => $cm->id], MUST_EXIST));
            $this->assertSame(1, $DB->count_records('course_modules', ['course' => $course->id]));
        } else {
            $this->assertSame(CONTEXT_COURSE, $context->contextlevel);
            $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        }

        $before = $this->entity_ids();
        $second = $this->create_question($course, ['name' => 'Second native question']);
        $after = $this->entity_ids();
        $this->assertTrue($second['success']);
        $this->assertSame($first['category_id'], $second['category_id']);
        $this->assertSame($before['course_modules'], $after['course_modules']);
        $this->assertSame($before['question_categories'], $after['question_categories']);
        $this->assertCount(count($before['question']) + 1, $after['question']);
    }

    public function test_read_actions_do_not_create_assessment_entities(): void {
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $before = $this->entity_ids();
        $tool = new manage_question_bank();
        $categories = $tool->execute(['course_id' => (int)$course->id, 'action' => 'list_categories']);
        $questions = $tool->execute(['course_id' => (int)$course->id, 'action' => 'list_questions']);
        $this->assertSame([], $categories['categories']);
        $this->assertSame([], $questions['questions']);
        $this->assertSame($before, $this->entity_ids());
    }

    public function test_default_selection_is_native_authorized_and_deterministic(): void {
        [$course, $teacher] = $this->create_fixture();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $privatecontext = \context_module::instance($quiz->cmid);
        $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => $privatecontext->id,
            'name' => 'Private quiz category',
        ]);
        if ($this->is_modern()) {
            $this->getDataGenerator()->create_module('qbank', [
                'course' => $course->id,
                'name' => 'First but system bank',
                'type' => question_bank_helper::TYPE_SYSTEM,
            ]);
            $denied = $this->create_default_fixture($course, 'First standard but denied');
            $this->set_question_permission($denied->contextid, CAP_PROHIBIT);
            $expected = $this->create_default_fixture($course, 'Z first authorized');
            $this->create_default_fixture($course, 'A later authorized');
        } else {
            $expected = $this->create_default_fixture($course);
        }
        $before = $this->entity_ids();
        $this->setUser($teacher);
        if ($this->is_modern()) {
            $this->assertFalse(has_capability('moodle/question:add', \context::instance_by_id($denied->contextid)));
        }
        $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($expected->contextid)));

        $result = $this->create_question($course);
        $this->assertSame((int)$expected->id, $result['category_id']);
        $this->assertSame((int)$expected->contextid, (int)$this->question_category($result['question_id'])->contextid);
        $after = $this->entity_ids();
        $this->assertSame($before['course_modules'], $after['course_modules']);
        $this->assertSame($before['question_categories'], $after['question_categories']);
    }

    public function test_existing_default_does_not_require_creation_privileges(): void {
        [$course, $teacher] = $this->create_fixture();
        $expected = $this->create_default_fixture($course);
        $coursecontext = \context_course::instance($course->id);
        $this->set_permission('moodle/course:manageactivities', $coursecontext->id, CAP_PROHIBIT);
        if ($this->is_modern()) {
            $this->set_permission('mod/qbank:addinstance', $coursecontext->id, CAP_PROHIBIT);
            $this->set_question_permission($coursecontext->id, CAP_PREVENT);
            $this->set_question_permission($expected->contextid, CAP_ALLOW);
        }
        $before = $this->entity_ids();
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/course:manageactivities', $coursecontext));
        $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($expected->contextid)));

        $result = $this->create_question($course);
        $this->assertSame((int)$expected->id, $result['category_id']);
        $after = $this->entity_ids();
        $this->assertSame($before['course_modules'], $after['course_modules']);
        $this->assertSame($before['question_categories'], $after['question_categories']);
    }

    public function test_default_question_add_denial_creates_nothing(): void {
        [$course, $teacher] = $this->create_fixture();
        $context = \context_course::instance($course->id);
        $this->set_question_permission($context->id, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/question:add', $context));
        $this->assert_denied_without_writes($course, [], 'nopermissions');
    }

    public function test_default_creation_respects_native_module_permissions(): void {
        $capabilities = ['moodle/course:manageactivities'];
        if ($this->is_modern()) {
            $capabilities[] = 'mod/qbank:addinstance';
        }
        foreach ($capabilities as $capability) {
            [$course, $teacher] = $this->create_fixture();
            $context = \context_course::instance($course->id);
            $this->set_permission($capability, $context->id, CAP_PROHIBIT);
            $this->setUser($teacher);
            $this->assertFalse(has_capability($capability, $context));
            $this->assertTrue(has_capability('moodle/question:add', $context));
            if ($this->is_modern()) {
                $errorcode = $capability === 'mod/qbank:addinstance' ? 'moduledisable' : 'nopermissions';
                $this->assert_denied_without_writes($course, [], $errorcode);
            } else {
                $result = $this->create_question($course);
                $this->assertTrue($result['success']);
                $this->assertSame((int)$context->id, (int)$this->question_category($result['question_id'])->contextid);
            }
        }
    }

    public function test_explicit_category_honors_actual_context_permission(): void {
        [$course, $teacher] = $this->create_fixture();
        $category = $this->create_explicit_category($course);
        $coursecontext = \context_course::instance($course->id);
        $this->set_question_permission($coursecontext->id, CAP_PREVENT);
        $this->set_question_permission($category->contextid, CAP_ALLOW);
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/question:add', $coursecontext));
        $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($category->contextid)));
        $before = $this->entity_ids();

        $result = $this->create_question($course, ['category_id' => (int)$category->id]);
        $this->assertTrue($result['success']);
        $this->assertSame((int)$category->id, $result['category_id']);
        $this->assertSame((int)$category->id, (int)$this->question_category($result['question_id'])->id);
        $after = $this->entity_ids();
        $this->assertSame($before['course_modules'], $after['course_modules']);
        $this->assertSame($before['question_categories'], $after['question_categories']);
    }

    public function test_explicit_category_denial_creates_nothing(): void {
        [$course, $teacher] = $this->create_fixture();
        $category = $this->create_explicit_category($course);
        $this->set_question_permission($category->contextid, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/question:add', \context_course::instance($course->id)));
        $this->assertFalse(has_capability('moodle/question:add', \context::instance_by_id($category->contextid)));
        $this->assert_denied_without_writes($course, ['category_id' => (int)$category->id], 'nopermissions');
    }

    public function test_foreign_category_is_denied_even_with_access_to_both_courses(): void {
        [$course, $teacher] = $this->create_fixture();
        $foreign = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $foreign->id, 'editingteacher');
        $category = $this->create_explicit_category($foreign);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($category->contextid)));
        $this->assert_denied_without_writes($course, ['category_id' => (int)$category->id], 'error_invalid_params');
    }

    public function test_scaffold_child_uses_resolved_parent_context_and_is_reused(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $tool = new scaffold_assessment_unit();
        $first = $tool->execute($this->scaffold_params($course, 'First pool question'));
        $questionid = (int)$DB->get_field('question', 'id', ['name' => 'First pool question'], MUST_EXIST);
        $child = $this->question_category($questionid);
        $parent = $DB->get_record('question_categories', ['id' => $child->parent], '*', MUST_EXIST);
        $this->assertTrue($first['success']);
        $this->assertSame((int)$parent->contextid, (int)$child->contextid);
        $context = \context::instance_by_id($parent->contextid);
        $this->assertSame($this->is_modern() ? CONTEXT_MODULE : CONTEXT_COURSE, $context->contextlevel);
        $this->assertSame((int)$parent->id, (int)question_get_default_category($context->id)->id);
        if ($this->is_modern()) {
            $cm = get_fast_modinfo($course)->get_cm($context->instanceid);
            $this->assertSame('qbank', $cm->modname);
            $this->assertSame(question_bank_helper::TYPE_STANDARD,
                $DB->get_field('qbank', 'type', ['id' => $cm->instance], MUST_EXIST));
        }

        $second = $tool->execute($this->scaffold_params($course, 'Second pool question'));
        $secondid = (int)$DB->get_field('question', 'id', ['name' => 'Second pool question'], MUST_EXIST);
        $this->assertTrue($second['success']);
        $this->assertSame((int)$child->id, (int)$this->question_category($secondid)->id);
        $this->assertSame(1, $DB->count_records('question_categories', [
            'contextid' => $parent->contextid, 'parent' => $parent->id, 'name' => $child->name,
        ]));
    }

    public function test_scaffold_checks_add_permission_at_resolved_context(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $expected = $this->create_default_fixture($course);
        $coursecontext = \context_course::instance($course->id);
        $this->set_question_permission($coursecontext->id, CAP_PREVENT);
        if ($this->is_modern()) {
            $this->set_question_permission($expected->contextid, CAP_ALLOW);
        }
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/question:add', $coursecontext));
        $before = $this->entity_ids();
        $tool = new scaffold_assessment_unit();
        if ($this->is_modern()) {
            $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($expected->contextid)));
            $result = $tool->execute($this->scaffold_params($course, 'Module allowed pool question'));
            $this->assertTrue($result['success']);
            $questionid = (int)$DB->get_field('question', 'id', ['name' => 'Module allowed pool question'], MUST_EXIST);
            $child = $this->question_category($questionid);
            $this->assertSame((int)$expected->id, (int)$child->parent);
            $this->assertSame((int)$expected->contextid, (int)$child->contextid);
        } else {
            try {
                $tool->execute($this->scaffold_params($course, 'Denied pool question'));
                $this->fail('Scaffold without native-context question-add must be denied.');
            } catch (\moodle_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
            $this->assertSame($before, $this->entity_ids());
        }
    }

    private function create_fixture(): array {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/questionlib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [$course, $teacher];
    }

    private function is_modern(): bool {
        return class_exists(question_bank_helper::class);
    }

    private function create_default_fixture(\stdClass $course, string $name = 'Existing native bank'): \stdClass {
        if ($this->is_modern()) {
            $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id, 'name' => $name]);
            return question_get_default_category(\context_module::instance($bank->cmid)->id, true);
        }
        return question_make_default_categories([\context_course::instance($course->id)]);
    }

    private function create_explicit_category(\stdClass $course): \stdClass {
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        return $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => \context_module::instance($quiz->cmid)->id,
            'name' => 'Explicit module category',
        ]);
    }

    private function set_question_permission(int $contextid, int $permission): void {
        $this->set_permission('moodle/question:add', $contextid, $permission);
    }

    private function set_permission(string $capability, int $contextid, int $permission): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, $permission, $roleid, $contextid);
        accesslib_clear_all_caches(true);
    }

    private function create_question(\stdClass $course, array $overrides = []): array {
        return (new manage_question_bank())->execute($overrides + [
            'course_id' => (int)$course->id,
            'action' => 'create_question',
            'qtype' => 'truefalse',
            'name' => 'Native context question',
            'questiontext' => '<p>Two plus two equals five.</p>',
            'correct_truefalse' => false,
        ]);
    }

    private function scaffold_params(\stdClass $course, string $questionname): array {
        return [
            'course_id' => (int)$course->id,
            'quiz_title' => 'Native pool',
            'questions' => [[
                'qtype' => 'truefalse', 'name' => $questionname,
                'text' => 'Two plus two equals five.', 'correct_truefalse' => false,
            ]],
        ];
    }

    private function question_category(int $questionid): \stdClass {
        global $DB;
        return $DB->get_record_sql('SELECT qc.*
                FROM {question_categories} qc
                JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               WHERE qv.questionid = :questionid', ['questionid' => $questionid], MUST_EXIST);
    }

    private function entity_ids(): array {
        global $DB;
        $result = [];
        foreach (['course_modules', 'question_categories', 'question', 'question_bank_entries', 'question_versions'] as $table) {
            $result[$table] = array_keys($DB->get_records($table, [], 'id ASC', 'id'));
        }
        return $result;
    }

    private function assert_denied_without_writes(\stdClass $course, array $params, string $errorcode): void {
        $before = $this->entity_ids();
        try {
            $this->create_question($course, $params);
            $this->fail('Question creation must reject unauthorized category resolution.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertSame($before, $this->entity_ids(), 'Denied category resolution must not create assessment entities.');
    }
}
