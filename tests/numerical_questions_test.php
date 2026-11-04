<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\question_bank_helper;
use tool_teachermcp\tools\manage_question_bank;
use tool_teachermcp\tools\scaffold_assessment_unit;

/**
 * Numerical authoring through the public assessment tools and native grading.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class numerical_questions_test extends \advanced_testcase {
    public function test_numerical_schema_and_dispatch_contract(): void {
        $this->resetAfterTest(true);
        $bank = new manage_question_bank();
        $schema = $bank->get_input_schema();
        $this->assertContains('numerical', $schema['properties']['qtype']['enum']);
        $this->assertSame('number', $schema['properties']['options']['items']['properties']['tolerance']['type']);
        $schema = (new scaffold_assessment_unit())->get_input_schema();
        $question = $schema['properties']['questions']['items']['properties'];
        $this->assertContains('numerical', $question['qtype']['enum']);
        $this->assertSame('number', $question['options']['items']['properties']['tolerance']['type']);
        $this->assertTrue(server::validate_tool_arguments($bank, [
            'course_id' => 1, 'action' => 'create_question', 'qtype' => 'numerical',
            'name' => 'Number', 'questiontext' => 'Enter a number.',
            'options' => [['answer' => '0', 'fraction' => 1.0]],
        ]));
    }

    public function test_create_read_and_native_tolerance_grading(): void {
        [$course] = $this->fixture();
        $created = $this->create($course, [
            ['answer' => '9.81', 'fraction' => 1.0, 'tolerance' => 0.01, 'feedback' => '<p>Correct.</p>'],
            ['answer' => '9.7', 'fraction' => 0.5, 'tolerance' => 0.01, 'feedback' => 'Partial credit.'],
        ]);
        $read = $this->call($course, 'get_question', ['question_id' => $created['question_id']]);
        $this->assertSame('numerical', $read['qtype']);
        $this->assertSame('9.81', $read['answers'][0]['answer']);
        $this->assertSame(0.01, $read['answers'][0]['tolerance']);
        $this->assertSame(1.0, $read['answers'][0]['fraction']);
        $this->assertSame('Correct.', $read['answers'][0]['feedback']);
        $this->assertSame([], $read['numerical_options']['units']);
        $this->assertSame(\qtype_numerical::UNITNONE, $read['numerical_options']['showunits']);
        foreach (['9.81' => 1.0, '9.80' => 1.0, '9.82' => 1.0, '9.83' => 0.0, '9.7' => 0.5] as $answer => $grade) {
            $this->assert_grade($created['question_id'], (string)$answer, $grade);
        }
        $native = \question_bank::load_question($created['question_id']);
        $native->ap->set_characters(',', '.');
        [$fraction] = $native->grade_response(['answer' => '9,81']);
        $this->assertEquals(1.0, $fraction, 'Native localized student responses must still grade correctly.');
    }

    public function test_zero_negative_and_scientific_answers(): void {
        [$course] = $this->fixture();
        foreach (['0', '-2.5', '1e-3'] as $answer) {
            $created = $this->create($course, [['answer' => $answer, 'fraction' => 1.0]]);
            $read = $this->call($course, 'get_question', ['question_id' => $created['question_id']]);
            $this->assertSame(0.0, $read['answers'][0]['tolerance'], 'Omitted tolerance defaults to exact numerical grading.');
            $this->assert_grade($created['question_id'], $answer, 1.0);
            $this->assert_grade($created['question_id'], (string)((float)$answer + 1), 0.0);
        }
    }

    public function test_numerical_scaffolding_and_direct_attachment(): void {
        global $DB;
        [$course] = $this->fixture();
        $quiz = (new scaffold_assessment_unit())->execute([
            'course_id' => (int)$course->id, 'quiz_title' => 'Numerical practice',
            'questions' => [
                ['name' => 'Numerical scaffold', 'text' => 'Enter 12.5.', 'qtype' => 'numerical',
                    'options' => [['answer' => '12.5', 'fraction' => 1.0, 'tolerance' => 0.1]]],
                ['name' => 'Existing type control', 'text' => 'One equals one.', 'qtype' => 'truefalse',
                    'correct_truefalse' => true],
            ],
        ]);
        $this->assertTrue($quiz['success']);
        $this->assertFalse($quiz['visible']);
        $this->assertSame(2, $quiz['slots_attached']);
        $id = (int)$DB->get_field('question', 'id', ['name' => 'Numerical scaffold'], MUST_EXIST);
        $this->assert_grade($id, '12.55', 1.0);
        $this->assert_grade($id, '12.7', 0.0);
        $attached = $this->create($course, [['answer' => '0', 'fraction' => 1.0]], ['quiz_id' => $quiz['quiz_id']]);
        $this->assertEquals($quiz['quiz_id'], $attached['added_to_quiz_id']);
        $this->assertSame(3, $DB->count_records('quiz_slots', ['quizid' => $quiz['quiz_id']]));
        $this->assertEquals(3.0, $DB->get_field('quiz', 'sumgrades', ['id' => $quiz['quiz_id']], MUST_EXIST));
    }

    public function test_update_preserves_omitted_answers_and_replaces_supplied_options(): void {
        global $DB;
        [$course] = $this->fixture();
        $created = $this->create($course, [['answer' => '10', 'fraction' => 1.0, 'tolerance' => 0.25, 'feedback' => 'Keep me.']]);
        $renamed = $this->call($course, 'update_question', ['question_id' => $created['question_id'], 'name' => 'Renamed number']);
        $this->assertNotSame($created['question_id'], $renamed['question_id']);
        $read = $this->call($course, 'get_question', ['question_id' => $renamed['question_id']]);
        $this->assertSame('10', $read['answers'][0]['answer']);
        $this->assertSame(0.25, $read['answers'][0]['tolerance']);
        $this->assertSame('Keep me.', $read['answers'][0]['feedback']);
        $this->assert_grade($renamed['question_id'], '10.2', 1.0);
        $changed = $this->call($course, 'update_question', ['question_id' => $renamed['question_id'],
            'options' => [['answer' => '0', 'fraction' => 1.0, 'tolerance' => 0.1, 'feedback' => 'Revised.']]]);
        $this->assert_grade($changed['question_id'], '0.05', 1.0);
        $this->assert_grade($changed['question_id'], '10', 0.0);
        $this->assert_grade($created['question_id'], '10.2', 1.0);
        $entries = $DB->get_fieldset_select('question_versions', 'questionbankentryid', 'questionid IN (?, ?, ?)',
            [$created['question_id'], $renamed['question_id'], $changed['question_id']]);
        $this->assertCount(1, array_unique($entries), 'Updates must remain native versions of the same bank entry.');
    }

    public function test_native_units_hints_and_files_survive_metadata_updates(): void {
        global $DB;
        [$course, $teacher] = $this->fixture();
        $category = $this->category($course, 'Native numerical bank');
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question('numerical', 'pi', [
            'category' => $category->id,
        ]);
        \question_bank::get_qtype('numerical');
        $DB->insert_record('question_numerical_units', (object)['question' => $question->id, 'unit' => 'm', 'multiplier' => 1]);
        $DB->insert_record('question_numerical_units', (object)['question' => $question->id, 'unit' => 'cm', 'multiplier' => 0.01]);
        $DB->set_field('question_numerical_options', 'showunits', \qtype_numerical::UNITSELECT, ['question' => $question->id]);
        $DB->set_field('question_numerical_options', 'unitgradingtype', 1, ['question' => $question->id]);
        $DB->set_field('question_numerical_options', 'unitpenalty', 0.2, ['question' => $question->id]);
        $hintid = $DB->insert_record('question_hints', (object)['questionid' => $question->id,
            'hint' => '<p>Hint <a href="@@PLUGINFILE@@/hint.txt">file</a></p>', 'hintformat' => FORMAT_HTML]);
        $answerid = (int)$DB->get_field('question_answers', 'id', ['question' => $question->id, 'fraction' => 1], MUST_EXIST);
        $DB->set_field('question_answers', 'feedback', '<p>Feedback <a href="@@PLUGINFILE@@/feedback.txt">file</a></p>', ['id' => $answerid]);
        $DB->set_field('question', 'questiontext', '<p>Question <a href="@@PLUGINFILE@@/question.txt">file</a></p>', ['id' => $question->id]);
        $entry = get_question_bank_entry($question->id);
        $DB->set_field('question_bank_entries', 'idnumber', 'native-number', ['id' => $entry->id]);
        foreach (['questiontext' => [$question->id, 'question.txt'], 'answerfeedback' => [$answerid, 'feedback.txt'],
            'hint' => [$hintid, 'hint.txt']] as $area => [$itemid, $filename]) {
            get_file_storage()->create_file_from_string(['contextid' => $category->contextid, 'component' => 'question',
                'filearea' => $area, 'itemid' => $itemid, 'filepath' => '/', 'filename' => $filename], $filename);
        }
        $this->setUser($teacher);
        $before = $this->call($course, 'get_question', ['question_id' => (int)$question->id]);
        $beforehints = array_values($DB->get_records('question_hints', ['questionid' => $question->id], 'id ASC'));
        $this->assertSame(0.2, $before['numerical_options']['unitpenalty']);
        $updated = $this->call($course, 'update_question', ['question_id' => (int)$question->id, 'name' => 'Preserved native number']);
        $after = $this->call($course, 'get_question', ['question_id' => $updated['question_id']]);
        $this->assertSame($before['numerical_options'], $after['numerical_options']);
        foreach (['answer', 'fraction', 'feedback', 'tolerance'] as $field) {
            $this->assertSame(array_column($before['answers'], $field), array_column($after['answers'], $field));
        }
        $this->assertSame('native-number', get_question_bank_entry($updated['question_id'])->idnumber);
        $afterhints = array_values($DB->get_records('question_hints', ['questionid' => $updated['question_id']], 'id ASC'));
        $this->assertSame(array_column($beforehints, 'hint'), array_column($afterhints, 'hint'));
        $this->assertSame(array_column($beforehints, 'hintformat'), array_column($afterhints, 'hintformat'));
        $newhint = $afterhints[array_search($hintid, array_column($beforehints, 'id'))];
        $this->assertStringContainsString('Hint', $newhint->hint);
        $newanswerid = (int)$DB->get_field('question_answers', 'id', ['question' => $updated['question_id'], 'fraction' => 1], MUST_EXIST);
        foreach (['questiontext' => [$updated['question_id'], 'question.txt'], 'answerfeedback' => [$newanswerid, 'feedback.txt'],
            'hint' => [$newhint->id, 'hint.txt']] as $area => [$itemid, $filename]) {
            $file = get_file_storage()->get_file($category->contextid, 'question', $area, $itemid, '/', $filename);
            $this->assertNotFalse($file, 'Metadata update must retain native numerical files in ' . $area);
            $this->assertSame($filename, $file->get_content());
        }
    }

    public function test_invalid_numerical_inputs_do_not_create_assessment_records(): void {
        [$course] = $this->fixture();
        foreach ([[], [['answer' => 'text', 'fraction' => 1.0]], [['answer' => '1e309', 'fraction' => 1.0]],
            [['answer' => '0', 'fraction' => 1.0, 'tolerance' => -1]], [['answer' => '0', 'fraction' => 0.5]],
            [['answer' => '0', 'fraction' => 1.0, 'tolerance' => 'bad']], [['answer' => '0', 'fraction' => 1.1]],
            [['answer' => [], 'fraction' => 1.0]], [['answer' => '0', 'fraction' => 1.0, 'feedback' => []]]]
                as $options) {
            $this->assert_unchanged_error(fn() => $this->create($course, $options));
            $this->assert_unchanged_error(fn() => (new scaffold_assessment_unit())->execute([
                'course_id' => (int)$course->id, 'quiz_title' => 'Invalid numerical', 'questions' => [[
                    'name' => 'Invalid', 'text' => 'Enter a number.', 'qtype' => 'numerical', 'options' => $options,
                ]],
            ]));
        }
    }

    public function test_invalid_numerical_update_does_not_move_or_version_question(): void {
        [$course, $teacher] = $this->fixture();
        $source = $this->category($course, 'Source');
        $destination = $this->category($course, 'Destination');
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question('numerical', 'pi',
            ['category' => $source->id]);
        $this->setUser($teacher);
        $this->assert_unchanged_error(fn() => $this->call($course, 'update_question', [
            'question_id' => (int)$question->id, 'category_id' => (int)$destination->id,
            'options' => [['answer' => 'invalid', 'fraction' => 1.0, 'tolerance' => 0.1]],
        ]));
    }

    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $teacher = $generator->create_user(['lang' => 'en']);
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    private function category(\stdClass $course, string $name): \stdClass {
        $generator = $this->getDataGenerator();
        $module = $generator->create_module(class_exists(question_bank_helper::class) ? 'qbank' : 'quiz',
            ['course' => $course->id, 'name' => $name]);
        return $generator->get_plugin_generator('core_question')->create_question_category([
            'contextid' => \context_module::instance($module->cmid)->id, 'name' => $name,
        ]);
    }

    private function call(\stdClass $course, string $action, array $params): array {
        return (new manage_question_bank())->execute($params + ['course_id' => (int)$course->id, 'action' => $action]);
    }

    private function create(\stdClass $course, array $options, array $extra = []): array {
        return $this->call($course, 'create_question', $extra + ['qtype' => 'numerical', 'name' => 'Numerical question',
            'questiontext' => '<p>Enter the requested number.</p>', 'options' => $options]);
    }

    private function assert_grade(int $questionid, string $answer, float $expected): void {
        $question = \question_bank::load_question($questionid);
        [$fraction] = $question->grade_response(['answer' => $answer]);
        $this->assertEquals($expected, $fraction, 'Native numerical grading for response ' . $answer);
    }

    private function snapshot(): array {
        global $DB;
        $tables = ['course_modules', 'question_categories', 'question', 'question_answers', 'question_bank_entries',
            'question_versions', 'question_numerical', 'question_numerical_options', 'question_numerical_units',
            'question_hints', 'quiz', 'quiz_slots', 'question_references', 'files'];
        if (class_exists(question_bank_helper::class)) {
            $tables[] = 'qbank';
        }
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = array_map(static fn($record) => (array)$record,
                array_values($DB->get_records($table, [], 'id ASC')));
        }
        return $result;
    }

    private function assert_unchanged_error(callable $operation): void {
        $before = $this->snapshot();
        try {
            $operation();
            $this->fail('Invalid numerical input must be rejected before mutation.');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_invalid_params', $error->errorcode);
        }
        $this->assertSame($before, $this->snapshot(), 'Invalid numerical input must not create, move or version records.');
    }
}
