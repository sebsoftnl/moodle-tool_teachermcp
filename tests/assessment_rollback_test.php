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

require_once(__DIR__ . '/fixtures/assessment_native_functions.php');

use core_question\local\bank\question_bank_helper;
use tool_teachermcp\tests\fixtures\assessment_faults;
use tool_teachermcp\tests\fixtures\assessment_injected_failure;
use tool_teachermcp\tests\fixtures\faulting_scaffold;
use tool_teachermcp\tools\manage_question_bank;

/**
 * Native assessment forwarding and post-write rollback contracts.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assessment_rollback_test extends \advanced_testcase {
    public function test_scaffold_forwarding_preserves_native_work(): void {
        global $DB;
        $fixture = $this->create_fixture(false, false);
        $before = assessment_faults::snapshot();
        $this->assertSame($before, assessment_faults::snapshot(), 'Observation must not modify database rows.');
        $this->assertFalse(assessment_faults::is_armed());
        assessment_faults::arm('scaffold', (int)$fixture['course']->id);
        try {
            $result = (new faulting_scaffold())->execute($this->scaffold_params($fixture['course']));
            $hits = assessment_faults::hits();
            $observations = assessment_faults::observations();
        } finally {
            assessment_faults::clear();
        }
        $this->assertFalse(assessment_faults::is_armed());
        $this->assertTrue($result['success']);
        $this->assertSame(['bank' => 2, 'question' => 2, 'module' => 1, 'slot' => 2], $hits);
        $after = assessment_faults::snapshot();
        $this->assertCount(2, $this->added($before, $after, 'question'));
        $this->assertCount(2, $this->added($before, $after, 'quiz_slots'));
        $this->assertCount(2, $this->added($before, $after, 'question_references'));
        $this->assertTrue($DB->record_exists('quiz', ['id' => $result['quiz_id']]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $result['cmid'], 'instance' => $result['quiz_id']]));
        $this->assertTrue($DB->record_exists('grade_items', [
            'itemmodule' => 'quiz', 'iteminstance' => $result['quiz_id'], 'courseid' => $fixture['course']->id,
        ]));
        $module = array_values(array_filter($observations, static fn($row) => $row['phase'] === 'module'))[0];
        $this->assertSame($result['cmid'], $module['ids']['cmid']);
        $this->assertSame($result['quiz_id'], $module['ids']['instanceid']);
    }

    public function test_create_attachment_forwarding_preserves_native_work_and_returns(): void {
        global $DB;
        $fixture = $this->create_fixture(false, true);
        $before = assessment_faults::snapshot();
        $this->assertFalse(assessment_faults::is_armed());
        assessment_faults::arm('create', (int)$fixture['course']->id);
        try {
            $result = (new manage_question_bank())->execute($this->create_params($fixture));
            $hits = assessment_faults::hits();
        } finally {
            assessment_faults::clear();
        }
        $this->assertFalse(assessment_faults::is_armed());
        $this->assertTrue($result['success']);
        $this->assertSame(['question' => 1, 'slot' => 1], $hits);
        $after = assessment_faults::snapshot();
        $this->assertCount(1, $this->added($before, $after, 'question'));
        $this->assertCount(1, $this->added($before, $after, 'quiz_slots'));
        $this->assertCount(1, $this->added($before, $after, 'question_references'));
        $quiz = $DB->get_record('quiz', ['id' => $fixture['quiz']->id], '*', MUST_EXIST);
        $this->assertFalse(\tool_teachermcp\tools\quiz_add_quiz_question($result['question_id'], $quiz));
        $this->assertSame($after, assessment_faults::snapshot(), 'Duplicate native attachment must remain a no-op.');
    }

    public function test_fault_scope_can_be_disarmed_after_real_slot_write(): void {
        $fixture = $this->create_fixture(false, true);
        $before = assessment_faults::snapshot();
        $expected = assessment_faults::arm('create', (int)$fixture['course']->id, 'slot');
        $caught = null;
        try {
            (new manage_question_bank())->execute($this->create_params($fixture));
        } catch (assessment_injected_failure $error) {
            $caught = $error;
        } finally {
            $observations = assessment_faults::observations();
            assessment_faults::clear();
        }
        $this->assertSame($expected, $caught, 'The exact injected failure must escape after real native work.');
        $this->assertFalse(assessment_faults::is_armed());
        $during = $observations[array_key_last($observations)];
        $this->assertSame('slot', $during['phase']);
        $this->assertCount(1, $this->added($before, $during['snapshot'], 'question'));
        $this->assertCount(1, $this->added($before, $during['snapshot'], 'quiz_slots'));
        $this->assertCount(1, $this->added($before, $during['snapshot'], 'question_references'));
        $this->assertSame($before, assessment_faults::snapshot(), 'Real injected work must roll back.');
        $result = (new manage_question_bank())->execute($this->create_params($fixture));
        $this->assertTrue($result['success'], 'Disarmed forwarding must permit the next native operation.');
        $this->assertSame([], assessment_faults::observations());
    }

    public function test_scaffold_bank_failure_with_new_default(): void {
        $this->assert_rollback('scaffold', 'bank', false);
    }

    public function test_scaffold_bank_failure_preserves_existing_bank(): void {
        $this->assert_rollback('scaffold', 'bank', true);
    }

    public function test_scaffold_question_failure_with_new_default(): void {
        $this->assert_rollback('scaffold', 'question', false);
    }

    public function test_scaffold_question_failure_preserves_existing_bank(): void {
        $this->assert_rollback('scaffold', 'question', true);
    }

    public function test_scaffold_module_failure_with_new_default(): void {
        $this->assert_rollback('scaffold', 'module', false);
    }

    public function test_scaffold_module_failure_preserves_existing_bank(): void {
        $this->assert_rollback('scaffold', 'module', true);
    }

    public function test_scaffold_second_slot_failure_with_new_default(): void {
        $this->assert_rollback('scaffold', 'slot', false);
    }

    public function test_scaffold_second_slot_failure_preserves_existing_bank(): void {
        $this->assert_rollback('scaffold', 'slot', true);
    }

    public function test_create_question_failure_with_new_default(): void {
        $this->assert_rollback('create', 'question', false);
    }

    public function test_create_question_failure_preserves_existing_bank(): void {
        $this->assert_rollback('create', 'question', true);
    }

    public function test_create_slot_failure_with_new_default(): void {
        $this->assert_rollback('create', 'slot', false);
    }

    public function test_create_slot_failure_preserves_existing_bank(): void {
        $this->assert_rollback('create', 'slot', true);
    }

    private function assert_rollback(string $operation, string $phase, bool $existingbank): void {
        global $DB;
        $fixture = $this->create_fixture($existingbank, $operation === 'create');
        $courseid = (int)$fixture['course']->id;
        $cachebefore = assessment_faults::modinfo($courseid);
        $before = assessment_faults::snapshot();
        $this->assertFalse($DB->is_transaction_started(), 'The fixture must not be hidden inside a test-reset transaction.');
        $this->assertFalse(assessment_faults::is_armed());
        $occurrence = $operation === 'scaffold' && $phase === 'slot' ? 2 : 1;
        $expected = assessment_faults::arm($operation, $courseid, $phase, $occurrence);
        $caught = null;
        try {
            if ($operation === 'scaffold') {
                (new faulting_scaffold())->execute($this->scaffold_params($fixture['course']));
            } else {
                (new manage_question_bank())->execute($this->create_params($fixture));
            }
        } catch (assessment_injected_failure $error) {
            $caught = $error;
        } finally {
            $observations = assessment_faults::observations();
            assessment_faults::clear();
        }
        $after = assessment_faults::snapshot();
        $immediate = $this->cache_observation($courseid);
        get_fast_modinfo($courseid, 0, true);
        $fresh = $this->cache_observation($courseid);
        $afterreads = assessment_faults::snapshot();

        $this->assertSame($expected, $caught, 'Only the intended post-write failure satisfies this scenario.');
        $this->assertFalse(assessment_faults::is_armed());
        $this->assertFalse($DB->is_transaction_started());
        $during = $observations[array_key_last($observations)];
        $this->assertSame($phase, $during['phase']);
        $this->assertSame($occurrence, $during['occurrence']);
        $this->assertTrue($during['transaction_active']);
        $questioncount = $phase === 'bank' ? 0 : ($operation === 'scaffold' && $phase !== 'question' ? 2 : 1);
        foreach (['question', 'question_truefalse', 'question_versions', 'question_bank_entries'] as $table) {
            $this->assertCount($questioncount, $this->added($before, $during['snapshot'], $table), $table);
        }
        if ($existingbank) {
            foreach ($this->added($before, $during['snapshot'], 'question_bank_entries') as $entry) {
                $category = array_values(array_filter(
                    $during['snapshot']['entities']['question_categories'],
                    static fn($row) => (int)$row['id'] === (int)$entry['questioncategoryid']
                ))[0];
                $this->assertSame((int)$fixture['category']->contextid, (int)$category['contextid']);
                $this->assertSame(
                    (int)$fixture['category']->id,
                    (int)$category[$operation === 'scaffold' ? 'parent' : 'id'],
                    'Existing-bank work must use the preserved fixture bank.'
                );
            }
        }
        $this->assertCount(2 * $questioncount, $this->added($before, $during['snapshot'], 'question_answers'));
        $slotcount = $phase === 'slot' ? $occurrence : 0;
        $this->assertCount($slotcount, $this->added($before, $during['snapshot'], 'quiz_slots'));
        $this->assertCount($slotcount, $this->added($before, $during['snapshot'], 'question_references'));
        $quizcount = $operation === 'scaffold' && in_array($phase, ['module', 'slot'], true) ? 1 : 0;
        $newquizzes = $this->added($before, $during['snapshot'], 'quiz');
        $this->assertCount($quizcount, $newquizzes);
        $bankcount = class_exists(question_bank_helper::class) && !$existingbank ? 1 : 0;
        $newmodules = $this->added($before, $during['snapshot'], 'course_modules');
        $this->assertCount($quizcount + $bankcount, $newmodules);
        if (class_exists(question_bank_helper::class)) {
            $newbanks = $this->added($before, $during['snapshot'], 'qbank');
            $this->assertCount($bankcount, $newbanks);
            foreach ($newbanks as $bank) {
                $this->assertSame(question_bank_helper::TYPE_STANDARD, $bank['type']);
            }
        }
        if ($phase === 'bank') {
            $this->assertNotEmpty($this->added($before, $during['snapshot'], 'question_categories'));
        }
        foreach ($newmodules as $module) {
            $this->assertContains((int)$module['id'], $during['modinfo']['cmids']);
            $contexts = array_filter($during['snapshot']['entities']['context'], static fn($row) =>
                (int)$row['contextlevel'] === CONTEXT_MODULE && (int)$row['instanceid'] === (int)$module['id']);
            $this->assertCount(1, $contexts);
            $section = array_values(array_filter(
                $during['snapshot']['entities']['course_sections'],
                static fn($row) => (int)$row['id'] === (int)$module['section']
            ))[0];
            $this->assertContains((int)$module['id'], array_map('intval', explode(',', $section['sequence'])));
        }
        foreach ($newquizzes as $quiz) {
            $items = array_filter($during['snapshot']['entities']['grade_items'], static fn($row) =>
                $row['itemmodule'] === 'quiz' && (int)$row['iteminstance'] === (int)$quiz['id']);
            $this->assertNotEmpty($items, 'The injected module phase must follow native grade-item creation.');
        }
        $this->assertSame($before, $after, 'Rollback must restore every table fingerprint and assessment relationship.');
        $this->assertSame($before, $afterreads, 'Cache observation must not repair or alter persisted rows.');
        $this->assertSame(
            ['immediate' => $cachebefore, 'fresh' => $cachebefore],
            ['immediate' => $immediate, 'fresh' => $fresh],
            'Rolled-back modules must not survive immediate or static-reset modinfo lookup.'
        );
    }

    private function cache_observation(int $courseid): array {
        try {
            return assessment_faults::modinfo($courseid);
        } catch (\Throwable $error) {
            return ['error' => get_class($error), 'message' => $error->getMessage()];
        }
    }

    private function create_fixture(bool $existingbank, bool $attachment): array {
        global $CFG;
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $sentinelcourse = $generator->create_course();
        $sentinelcategory = $this->create_native_bank($sentinelcourse, 'Unrelated bank');
        $generator->get_plugin_generator('core_question')->create_question(
            'truefalse',
            null,
            ['category' => $sentinelcategory->id, 'name' => 'Unrelated native question']
        );
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Preserved activity']);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $category = null;
        $existingquestion = null;
        if ($existingbank) {
            $category = $this->create_native_bank($course, 'Existing subject bank');
            $existingquestion = $generator->get_plugin_generator('core_question')->create_question(
                'truefalse',
                null,
                ['category' => $category->id, 'name' => 'Preserved subject question']
            );
        }
        $quiz = null;
        if ($attachment) {
            $quiz = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Existing target quiz']);
            if ($existingquestion !== null) {
                \quiz_add_quiz_question($existingquestion->id, $quiz);
                \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
            }
        }
        $this->setUser($teacher);
        assessment_faults::modinfo((int)$course->id);
        return ['course' => $course, 'teacher' => $teacher, 'quiz' => $quiz, 'category' => $category];
    }

    private function create_native_bank(\stdClass $course, string $name): \stdClass {
        if (class_exists(question_bank_helper::class)) {
            $bank = $this->getDataGenerator()->create_module('qbank', [
                'course' => $course->id, 'name' => $name, 'type' => question_bank_helper::TYPE_STANDARD,
            ]);
            return question_get_default_category(\context_module::instance($bank->cmid)->id, true);
        }
        return question_make_default_categories([\context_course::instance($course->id)]);
    }

    private function scaffold_params(\stdClass $course): array {
        return ['course_id' => (int)$course->id, 'quiz_title' => 'Rollback assessment', 'questions' => [
            ['name' => 'Rollback question one', 'text' => 'Two plus two equals five.',
                'qtype' => 'truefalse', 'correct_truefalse' => false],
            ['name' => 'Rollback question two', 'text' => 'Three plus three equals seven.',
                'qtype' => 'truefalse', 'correct_truefalse' => false],
        ]];
    }

    private function create_params(array $fixture): array {
        $params = ['course_id' => (int)$fixture['course']->id, 'action' => 'create_question',
            'quiz_id' => (int)$fixture['quiz']->id, 'qtype' => 'truefalse', 'name' => 'Rollback attachment question',
            'questiontext' => '<p>Two plus two equals five.</p>', 'correct_truefalse' => false];
        if ($fixture['category'] !== null) {
            $params['category_id'] = (int)$fixture['category']->id;
        }
        return $params;
    }

    private function added(array $before, array $after, string $table): array {
        $ids = array_map('intval', array_column($before['entities'][$table], 'id'));
        return array_values(array_filter(
            $after['entities'][$table],
            static fn($row) => !in_array((int)$row['id'], $ids, true)
        ));
    }
}
