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
use core_question\local\bank\question_version_status;
use tool_teachermcp\tools\manage_question_bank;
use tool_teachermcp\tools\scaffold_assessment_unit;

/**
 * Behavioral contracts for question operations and quiz attachments.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_actions_test extends \advanced_testcase {
    public function test_supported_question_types_create_get_and_list(): void {
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $questions = [];
        foreach (['truefalse' => [], 'essay' => [], 'shortanswer' => [
            'options' => [['answer' => 'four', 'fraction' => 1.0]],
        ], 'multichoice' => [
            'options' => [['answer' => 'four', 'fraction' => 1.0], ['answer' => 'five', 'fraction' => 0.0]],
        ]] as $qtype => $options) {
            $created = $this->create_question($course, $options + ['qtype' => $qtype, 'name' => 'Native ' . $qtype]);
            $this->assertTrue($created['success']);
            $this->assertSame(['success', 'question_id', 'category_id', 'name', 'qtype', 'defaultmark'], array_keys($created));
            $this->assertSame($qtype, $created['qtype']);
            $read = $this->call($course, 'get_question', ['question_id' => $created['question_id']]);
            $this->assertSame($created['question_id'], $read['question_id']);
            $this->assertSame($created['category_id'], $read['category_id']);
            $this->assertSame($qtype, $read['qtype']);
            $this->assertSame('Two plus two equals five.', $read['questiontext']);
            $questions[] = $created['question_id'];
        }
        $listed = $this->call($course, 'list_questions');
        $this->assertEqualsCanonicalizing($questions, array_column($listed['questions'], 'question_id'));
        $categories = $this->call($course, 'list_categories');
        $this->assertContains($created['category_id'], array_column($categories['categories'], 'category_id'));
    }

    public function test_false_grading_survives_updates_and_scaffolding(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $created = $this->create_question($course);
        $this->assert_false_is_correct($created['question_id']);
        $renamed = $this->call($course, 'update_question', [
            'question_id' => $created['question_id'], 'name' => 'Renamed false question',
        ]);
        $this->assert_false_is_correct($renamed['question_id']);
        $true = $this->call($course, 'update_question', [
            'question_id' => $renamed['question_id'], 'correct_truefalse' => true,
        ]);
        $false = $this->call($course, 'update_question', [
            'question_id' => $true['question_id'], 'correct_truefalse' => false,
        ]);
        $this->assert_false_is_correct($false['question_id']);

        $scaffold = (new scaffold_assessment_unit())->execute($this->scaffold_params($course));
        $this->assertTrue($scaffold['success']);
        $this->assertFalse($scaffold['visible']);
        $questionid = (int)$DB->get_field('question', 'id', ['name' => 'Actions scaffold false'], MUST_EXIST);
        $this->assert_false_is_correct($questionid);
        $this->assertSame(1, $DB->count_records('quiz_slots', ['quizid' => $scaffold['quiz_id']]));
    }

    public function test_update_move_and_attachment_preserve_native_relationships(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $source = $this->create_bank_category($course, 'Source');
        $destination = $this->create_bank_category($course, 'Destination');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->setUser($teacher);
        $created = $this->create_question($course, ['category_id' => (int)$source->id, 'defaultmark' => 2.5]);
        $this->assertNotEquals($source->contextid, $destination->contextid);
        $moved = $this->call($course, 'update_question', [
            'question_id' => $created['question_id'], 'category_id' => (int)$destination->id, 'name' => 'Moved question',
        ]);
        $this->assertTrue($moved['success']);
        $read = $this->call($course, 'get_question', ['question_id' => $moved['question_id']]);
        $this->assertSame((int)$destination->id, $read['category_id']);
        $this->assertSame('Moved question', $read['name']);
        $attached = $this->call($course, 'add_to_quiz', [
            'question_id' => $moved['question_id'], 'quiz_id' => (int)$quiz->id,
        ]);
        $this->assertSame(['success', 'quiz_id', 'question_id', 'message'], array_keys($attached));
        $this->assertTrue($attached['success']);
        $direct = $this->create_question($course, ['name' => 'Direct attached question',
            'quiz_id' => (int)$quiz->id, 'defaultmark' => 1.5]);
        $this->assertEquals($quiz->id, $direct['added_to_quiz_id']);
        $this->assertSame(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
        $this->assertEquals(4.0, $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id], MUST_EXIST));
        $entryids = $DB->get_fieldset_sql('SELECT qr.questionbankentryid FROM {question_references} qr
                JOIN {quiz_slots} qs ON qs.id = qr.itemid
               WHERE qs.quizid = :quizid AND qr.component = :component AND qr.questionarea = :area',
            ['quizid' => $quiz->id, 'component' => 'mod_quiz', 'area' => 'slot']);
        foreach ([$moved['question_id'], $direct['question_id']] as $questionid) {
            $entryid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid], MUST_EXIST);
            $this->assertContains($entryid, $entryids);
        }

        $short = $this->create_question($course, ['qtype' => 'shortanswer',
            'options' => [['answer' => 'four', 'fraction' => 1.0]]]);
        $updated = $this->call($course, 'update_question', ['question_id' => $short['question_id'],
            'options' => [['answer' => 'vier', 'fraction' => 1.0]]]);
        $answers = $DB->get_fieldset_select('question_answers', 'answer', 'question = ?', [$updated['question_id']]);
        $this->assertContains('vier', $answers);
    }

    public function test_explicit_read_and_foreign_ownership_guards_preserve_state(): void {
        [$course, $teacher] = $this->create_fixture();
        $category = $this->create_bank_category($course, 'Restricted read');
        $foreign = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $foreign->id, 'editingteacher');
        $foreigncategory = $this->create_bank_category($foreign, 'Foreign');
        $this->setUser($teacher);
        $localquestion = $this->create_question($course, ['category_id' => (int)$category->id]);
        $foreignquestion = $this->create_question($foreign, ['category_id' => (int)$foreigncategory->id]);
        $this->set_permission('moodle/question:viewall', $category->contextid, CAP_PROHIBIT);
        $this->assertTrue(has_capability('moodle/question:viewall', \context_course::instance($course->id)));
        $this->assertFalse(has_capability('moodle/question:viewall', \context::instance_by_id($category->contextid)));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'list_questions', [
            'category_id' => (int)$category->id,
        ]), 'nopermissions');
        $this->assert_unchanged_denial(fn() => $this->call($course, 'get_question', [
            'question_id' => $localquestion['question_id'],
        ]), 'nopermissions');
        $this->assert_unchanged_denial(fn() => $this->call($course, 'get_question', [
            'question_id' => $foreignquestion['question_id'],
        ]), 'error_invalid_params');
        $this->assert_unchanged_denial(fn() => $this->call($course, 'update_question', [
            'question_id' => $localquestion['question_id'], 'category_id' => (int)$foreigncategory->id,
        ]), 'error_invalid_params');
    }

    public function test_update_and_delete_retain_distinct_edit_permissions(): void {
        [$course, $teacher] = $this->create_fixture();
        $category = $this->create_bank_category($course, 'Edit permissions');
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'editingteacher');
        $this->setUser($other);
        $foreignauthor = $this->create_question($course, ['category_id' => (int)$category->id, 'name' => 'Other author']);
        $this->setUser($teacher);
        $owned = $this->create_question($course, ['category_id' => (int)$category->id, 'name' => 'Owned question']);
        $this->set_permission('moodle/question:editall', $category->contextid, CAP_PROHIBIT);
        $this->set_permission('moodle/question:editmine', $category->contextid, CAP_ALLOW);
        $context = \context::instance_by_id($category->contextid);
        $this->assertFalse(has_capability('moodle/question:editall', $context));
        $this->assertTrue(has_capability('moodle/question:editmine', $context));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'update_question', [
            'question_id' => $owned['question_id'], 'name' => 'Denied rename',
        ]), 'nopermissions');
        set_config('allow_delete_questions', 1, 'tool_teachermcp');
        $before = $this->snapshot();
        $preview = $this->call($course, 'delete_question', ['question_id' => $owned['question_id']]);
        $this->assertTrue($preview['requires_confirmation']);
        $this->assertSame($before, $this->snapshot());
        $this->assert_unchanged_denial(fn() => $this->call($course, 'delete_question', [
            'question_id' => $foreignauthor['question_id'],
        ]), 'nopermissions');
    }

    public function test_delete_confirmation_expiry_and_deleted_question_replay(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $created = $this->create_question($course);
        $params = ['question_id' => $created['question_id']];
        set_config('allow_delete_questions', 0, 'tool_teachermcp');
        $this->assert_unchanged_denial(fn() => $this->call($course, 'delete_question', $params), 'error_delete_questions_disabled');
        set_config('allow_delete_questions', 1, 'tool_teachermcp');
        $before = $this->snapshot();
        $preview = $this->call($course, 'delete_question', $params);
        $this->assertFalse($preview['success']);
        $this->assertTrue($preview['requires_confirmation']);
        $this->assertSame(300, $preview['expires_in_seconds']);
        $this->assertSame($before, $this->snapshot());
        $this->assert_unchanged_denial(fn() => $this->call($course, 'delete_question', $params + [
            'confirm_token' => base64_encode(time() . ':tampered'),
        ]), 'error_invalid_confirmation_token');
        $timestamp = time() - 301;
        $hash = hash_hmac('sha256', "delete_question:{$created['question_id']}:{$teacher->id}:{$timestamp}", get_site_identifier());
        $this->assert_unchanged_denial(fn() => $this->call($course, 'delete_question', $params + [
            'confirm_token' => base64_encode($timestamp . ':' . $hash),
        ]), 'error_confirmation_token_expired');
        $confirmed = $params + ['confirm_token' => $preview['confirm_token']];
        $result = $this->call($course, 'delete_question', $confirmed);
        $this->assertTrue($result['success']);
        $this->assertFalse($DB->record_exists('question', ['id' => $created['question_id']]));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'delete_question', $confirmed), 'invalidrecordunknown');
    }

    public function test_in_use_delete_hides_question_and_rejects_used_token(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->setUser($teacher);
        $created = $this->create_question($course, ['quiz_id' => (int)$quiz->id]);
        $this->assertTrue(questions_in_use([$created['question_id']]));
        set_config('allow_delete_questions', 1, 'tool_teachermcp');
        $params = ['question_id' => $created['question_id']];
        $preview = $this->call($course, 'delete_question', $params);
        $params['confirm_token'] = $preview['confirm_token'];
        $result = $this->call($course, 'delete_question', $params);
        $this->assertTrue($result['success']);
        $this->assertTrue($DB->record_exists('question', ['id' => $created['question_id']]));
        $this->assertSame(question_version_status::QUESTION_STATUS_HIDDEN,
            $DB->get_field('question_versions', 'status', ['questionid' => $created['question_id']], MUST_EXIST));
        [, $tokenhash] = explode(':', base64_decode($params['confirm_token']), 2);
        $this->assertEquals(1, get_cache_flag('tool_teachermcp_usedtoken', $tokenhash));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'delete_question', $params), 'error_confirmation_token_expired');
    }

    public function test_native_attempt_guard_distinguishes_previews(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $generator = $this->getDataGenerator();
        $previewquiz = $generator->create_module('quiz', ['course' => $course->id]);
        $studentquiz = $generator->create_module('quiz', ['course' => $course->id]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        $first = $this->create_question($course, ['quiz_id' => (int)$previewquiz->id]);
        $this->call($course, 'add_to_quiz', ['quiz_id' => (int)$studentquiz->id, 'question_id' => $first['question_id']]);
        $preview = $generator->get_plugin_generator('mod_quiz')->create_attempt($previewquiz->id, $teacher->id);
        $this->assertSame(1, (int)$preview->preview);
        $this->assertFalse(quiz_has_attempts($previewquiz->id));
        $second = $this->create_question($course, ['quiz_id' => (int)$previewquiz->id, 'name' => 'After preview']);
        $this->assertTrue($second['success']);

        $this->setUser($student);
        $attempt = $generator->get_plugin_generator('mod_quiz')->create_attempt($studentquiz->id, $student->id);
        $this->assertSame(0, (int)$attempt->preview);
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->setUser($teacher);
        $this->assertTrue(quiz_has_attempts($studentquiz->id));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'add_to_quiz', [
            'quiz_id' => (int)$studentquiz->id, 'question_id' => $second['question_id'],
        ]), 'cannoteditafterattempts');
        $this->assert_error(fn() => $this->create_question($course, [
            'quiz_id' => (int)$studentquiz->id, 'name' => 'Rejected after student attempt',
        ]), 'cannoteditafterattempts');
        $this->assertSame(1, $DB->count_records('quiz_slots', ['quizid' => $studentquiz->id]));
    }

    public function test_implicit_category_list_omits_denied_bank_context(): void {
        [$course, $allowed, $denied] = $this->create_read_fixture();
        $before = $this->snapshot();
        $result = $this->call($course, 'list_categories');
        $contexts = array_column($result['categories'], 'context_id');
        $this->assertContains((int)$allowed->contextid, $contexts);
        $this->assertNotContains((int)$denied->contextid, $contexts, 'A denied bank must not expose categories or counts.');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_implicit_question_list_omits_denied_bank_context(): void {
        [$course, , , $allowed, $denied] = $this->create_read_fixture();
        $before = $this->snapshot();
        $result = $this->call($course, 'list_questions');
        $ids = array_column($result['questions'], 'question_id');
        $this->assertContains($allowed['question_id'], $ids);
        $this->assertNotContains($denied['question_id'], $ids, 'A denied bank must not expose question data.');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_attachment_requires_actual_bank_view(): void {
        [$course, , $category, $quiz, $question] = $this->create_attachment_fixture();
        $this->set_permission('moodle/question:viewall', $category->contextid, CAP_PROHIBIT);
        $this->assertTrue(has_capability('moodle/question:viewall', \context_course::instance($course->id)));
        $this->assertFalse(has_capability('moodle/question:viewall', \context::instance_by_id($category->contextid)));
        $this->assertTrue(question_has_capability_on($question['question_id'], 'use'));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'add_to_quiz', [
            'quiz_id' => (int)$quiz->id, 'question_id' => $question['question_id'],
        ]), 'nopermissions');
    }

    public function test_existing_attachment_requires_native_question_use(): void {
        [$course, , $category, $quiz, $question] = $this->create_attachment_fixture();
        $this->set_permission('moodle/question:useall', $category->contextid, CAP_PROHIBIT);
        $this->set_permission('moodle/question:usemine', $category->contextid, CAP_PROHIBIT);
        $this->assertTrue(has_capability('moodle/question:viewall', \context::instance_by_id($category->contextid)));
        $this->assertFalse(question_has_capability_on($question['question_id'], 'use'));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'add_to_quiz', [
            'quiz_id' => (int)$quiz->id, 'question_id' => $question['question_id'],
        ]), 'nopermissions');
    }

    public function test_usemine_allows_owner_but_not_other_author_attachment(): void {
        global $DB;
        [$course, $teacher, $category, $quiz, $owned] = $this->create_attachment_fixture();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'editingteacher');
        $this->setUser($other);
        $notowned = $this->create_question($course, ['category_id' => (int)$category->id, 'name' => 'Other author use']);
        $this->setUser($teacher);
        $this->set_permission('moodle/question:useall', $category->contextid, CAP_PROHIBIT);
        $this->set_permission('moodle/question:usemine', $category->contextid, CAP_ALLOW);
        $this->assertEquals($teacher->id, $DB->get_field('question', 'createdby', ['id' => $owned['question_id']], MUST_EXIST));
        $this->assertEquals($other->id, $DB->get_field('question', 'createdby', ['id' => $notowned['question_id']], MUST_EXIST));
        $this->assertTrue(question_has_capability_on($owned['question_id'], 'use'));
        $this->assertFalse(question_has_capability_on($notowned['question_id'], 'use'));
        $result = $this->call($course, 'add_to_quiz', ['quiz_id' => (int)$quiz->id, 'question_id' => $owned['question_id']]);
        $this->assertTrue($result['success']);
        $this->assert_unchanged_denial(fn() => $this->call($course, 'add_to_quiz', [
            'quiz_id' => (int)$quiz->id, 'question_id' => $notowned['question_id'],
        ]), 'nopermissions');
    }

    public function test_missing_optional_quiz_creates_nothing(): void {
        global $DB;
        [$course, $teacher] = $this->create_fixture();
        $missingid = (int)$DB->get_field_sql('SELECT COALESCE(MAX(id), 0) + 1 FROM {quiz}');
        $this->setUser($teacher);
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['quiz_id' => $missingid]), 'invalidrecord');
    }

    public function test_negative_optional_quiz_is_not_silently_ignored(): void {
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['quiz_id' => -1]), 'invalidrecord');
    }

    public function test_foreign_optional_quiz_creates_nothing(): void {
        [$course, $teacher] = $this->create_fixture();
        $foreign = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $foreign->id, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $foreign->id]);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('mod/quiz:manage', \context_module::instance($quiz->cmid)));
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['quiz_id' => (int)$quiz->id]), 'invalidrecord');
    }

    public function test_optional_quiz_manage_denial_creates_nothing(): void {
        [$course, $teacher] = $this->create_fixture();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $this->set_permission('mod/quiz:manage', $context->id, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertFalse(has_capability('mod/quiz:manage', $context));
        $this->assertTrue(has_capability('moodle/question:add', \context_course::instance($course->id)));
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['quiz_id' => (int)$quiz->id]), 'nopermissions');
    }

    public function test_optional_quiz_attempt_denial_creates_nothing(): void {
        [$course, $teacher] = $this->create_fixture();
        $generator = $this->getDataGenerator();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $category = $generator->get_plugin_generator('core_question')->create_question_category([
            'contextid' => \context_module::instance($quiz->cmid)->id,
        ]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        $this->create_question($course, ['category_id' => (int)$category->id, 'quiz_id' => (int)$quiz->id]);
        $this->setUser($student);
        $attempt = $generator->get_plugin_generator('mod_quiz')->create_attempt($quiz->id, $student->id);
        $this->assertSame(0, (int)$attempt->preview);
        $this->setUser($teacher);
        $this->assertTrue(quiz_has_attempts($quiz->id));
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['quiz_id' => (int)$quiz->id]), 'cannoteditafterattempts');
    }

    public function test_invalid_multichoice_options_do_not_resolve_a_new_bank(): void {
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['qtype' => 'multichoice',
            'options' => [['answer' => 'Only answer', 'fraction' => 1.0]],
        ]), 'error_invalid_params');
    }

    public function test_invalid_shortanswer_options_do_not_resolve_a_new_bank(): void {
        [$course, $teacher] = $this->create_fixture();
        $this->setUser($teacher);
        $this->assert_unchanged_denial(fn() => $this->create_question($course, [
            'qtype' => 'shortanswer', 'options' => [],
        ]), 'error_invalid_params');
    }

    public function test_create_attachment_use_denial_rolls_back_new_bank_and_question(): void {
        $this->preventResetByRollback();
        [$course, $teacher] = $this->create_fixture();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_course::instance($course->id);
        $this->set_permission('moodle/question:useall', $context->id, CAP_PROHIBIT);
        $this->set_permission('moodle/question:usemine', $context->id, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/question:add', $context));
        $this->assertTrue(has_capability('moodle/question:viewall', $context));
        $this->assertFalse(has_capability('moodle/question:useall', $context));
        $this->assertFalse(has_capability('moodle/question:usemine', $context));
        $this->assert_unchanged_denial(fn() => $this->create_question($course, ['quiz_id' => (int)$quiz->id]), 'nopermissions');
    }

    public function test_create_attachment_use_denial_preserves_existing_bank(): void {
        $this->preventResetByRollback();
        [$course, , $category, $quiz, $existing] = $this->create_attachment_fixture();
        $this->set_permission('moodle/question:useall', $category->contextid, CAP_PROHIBIT);
        $this->set_permission('moodle/question:usemine', $category->contextid, CAP_PROHIBIT);
        $this->assertFalse(question_has_capability_on($existing['question_id'], 'use'));
        $this->assert_unchanged_denial(fn() => $this->create_question($course, [
            'category_id' => (int)$category->id, 'quiz_id' => (int)$quiz->id, 'name' => 'Must roll back',
        ]), 'nopermissions');
    }

    public function test_create_attachment_view_denial_creates_nothing(): void {
        $this->preventResetByRollback();
        [$course, , $category, $quiz, $existing] = $this->create_attachment_fixture();
        $this->set_permission('moodle/question:viewall', $category->contextid, CAP_PROHIBIT);
        $this->assertTrue(has_capability('moodle/question:viewall', \context_course::instance($course->id)));
        $this->assertTrue(question_has_capability_on($existing['question_id'], 'use'));
        $this->assert_unchanged_denial(fn() => $this->create_question($course, [
            'category_id' => (int)$category->id, 'quiz_id' => (int)$quiz->id,
        ]), 'nopermissions');
    }

    public function test_destination_add_denial_prevents_question_move(): void {
        [$course, , $destination, $question] = $this->create_move_fixture();
        $this->set_permission('moodle/question:add', $destination->contextid, CAP_PROHIBIT);
        $this->assertTrue(question_has_capability_on($question['question_id'], 'move'));
        $this->assertTrue(has_capability('moodle/question:moveall', \context::instance_by_id($destination->contextid)));
        $this->assertFalse(has_capability('moodle/question:add', \context::instance_by_id($destination->contextid)));
        $this->assert_unchanged_denial(fn() => $this->call($course, 'update_question', [
            'question_id' => $question['question_id'], 'category_id' => (int)$destination->id, 'name' => 'Denied move',
        ]), 'nopermissions');
    }

    public function test_existing_source_and_destination_move_guards_remain_required(): void {
        foreach (['source', 'destination'] as $side) {
            [$course, $source, $destination, $question] = $this->create_move_fixture();
            $contextid = $side === 'source' ? $source->contextid : $destination->contextid;
            $this->set_permission('moodle/question:moveall', $contextid, CAP_PROHIBIT);
            $this->set_permission('moodle/question:movemine', $contextid, CAP_PROHIBIT);
            $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($destination->contextid)));
            $this->assertFalse(has_capability('moodle/question:moveall', \context::instance_by_id($contextid)));
            $this->assertFalse(has_capability('moodle/question:movemine', \context::instance_by_id($contextid)));
            $this->assert_unchanged_denial(fn() => $this->call($course, 'update_question', [
                'question_id' => $question['question_id'], 'category_id' => (int)$destination->id,
            ]), 'nopermissions');
        }
    }

    public function test_invalid_options_do_not_move_an_existing_question(): void {
        [$course, , $destination, $question] = $this->create_move_fixture('shortanswer');
        $this->assert_unchanged_denial(fn() => $this->call($course, 'update_question', [
            'question_id' => $question['question_id'], 'category_id' => (int)$destination->id,
            'name' => 'Must not move or rename', 'options' => [],
        ]), 'error_invalid_params');
    }

    public function test_scaffold_bank_view_denial_preserves_existing_entities(): void {
        $this->preventResetByRollback();
        [$course, $teacher] = $this->create_fixture();
        $category = class_exists(question_bank_helper::class) ? $this->create_bank_category($course, 'Scaffold bank')
            : question_make_default_categories([\context_course::instance($course->id)]);
        $this->set_permission('moodle/question:viewall', $category->contextid, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/question:add', \context::instance_by_id($category->contextid)));
        $this->assertFalse(has_capability('moodle/question:viewall', \context::instance_by_id($category->contextid)));
        $this->assert_unchanged_denial(fn() => (new scaffold_assessment_unit())->execute($this->scaffold_params($course)), 'nopermissions');
    }

    public function test_scaffold_use_denial_rolls_back_new_entities(): void {
        $this->preventResetByRollback();
        [$course, $teacher] = $this->create_fixture();
        $context = \context_course::instance($course->id);
        $this->set_permission('moodle/question:useall', $context->id, CAP_PROHIBIT);
        $this->set_permission('moodle/question:usemine', $context->id, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/question:viewall', $context));
        $this->assertTrue(has_capability('moodle/question:add', $context));
        $this->assertFalse(has_capability('moodle/question:useall', $context));
        $this->assertFalse(has_capability('moodle/question:usemine', $context));
        $this->assert_unchanged_denial(fn() => (new scaffold_assessment_unit())->execute($this->scaffold_params($course)), 'nopermissions');
    }

    public function test_scaffold_requires_manage_permission_on_created_quiz(): void {
        $this->preventResetByRollback();
        [$course, $teacher] = $this->create_fixture();
        $context = \context_course::instance($course->id);
        $this->set_permission('mod/quiz:manage', $context->id, CAP_PROHIBIT);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('mod/quiz:addinstance', $context));
        $this->assertTrue(has_capability('moodle/course:manageactivities', $context));
        $this->assertFalse(has_capability('mod/quiz:manage', $context));
        $this->assert_unchanged_denial(fn() => (new scaffold_assessment_unit())->execute($this->scaffold_params($course)), 'nopermissions');
    }

    private function create_fixture(): array {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [$course, $teacher];
    }

    private function create_bank_category(\stdClass $course, string $name): \stdClass {
        $generator = $this->getDataGenerator();
        $modname = class_exists(question_bank_helper::class) ? 'qbank' : 'quiz';
        $module = $generator->create_module($modname, ['course' => $course->id, 'name' => $name]);
        return $generator->get_plugin_generator('core_question')->create_question_category([
            'contextid' => \context_module::instance($module->cmid)->id, 'name' => $name . ' category',
        ]);
    }

    private function create_read_fixture(): array {
        [$course, $teacher] = $this->create_fixture();
        $allowed = $this->create_bank_category($course, 'Allowed bank');
        $denied = $this->create_bank_category($course, 'Denied bank');
        $this->setUser($teacher);
        $allowedquestion = $this->create_question($course, ['category_id' => (int)$allowed->id, 'name' => 'Allowed question']);
        $deniedquestion = $this->create_question($course, ['category_id' => (int)$denied->id, 'name' => 'Denied question']);
        $this->set_permission('moodle/question:viewall', $denied->contextid, CAP_PROHIBIT);
        $this->assertTrue(has_capability('moodle/question:viewall', \context_course::instance($course->id)));
        $this->assertTrue(has_capability('moodle/question:viewall', \context::instance_by_id($allowed->contextid)));
        $this->assertFalse(has_capability('moodle/question:viewall', \context::instance_by_id($denied->contextid)));
        return [$course, $allowed, $denied, $allowedquestion, $deniedquestion];
    }

    private function create_attachment_fixture(): array {
        [$course, $teacher] = $this->create_fixture();
        $category = $this->create_bank_category($course, 'Attachment bank');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->setUser($teacher);
        $question = $this->create_question($course, ['category_id' => (int)$category->id]);
        return [$course, $teacher, $category, $quiz, $question];
    }

    private function create_move_fixture(string $qtype = 'truefalse'): array {
        [$course, $teacher] = $this->create_fixture();
        $source = $this->create_bank_category($course, 'Move source');
        $destination = $this->create_bank_category($course, 'Move destination');
        $this->setUser($teacher);
        $question = $this->create_question($course, ['category_id' => (int)$source->id, 'qtype' => $qtype,
            'options' => [['answer' => 'four', 'fraction' => 1.0]]]);
        return [$course, $source, $destination, $question];
    }

    private function call(\stdClass $course, string $action, array $params = []): array {
        return (new manage_question_bank())->execute($params + ['course_id' => (int)$course->id, 'action' => $action]);
    }

    private function create_question(\stdClass $course, array $params = []): array {
        return $this->call($course, 'create_question', $params + ['qtype' => 'truefalse', 'name' => 'Actions false question',
            'questiontext' => '<p>Two plus two equals five.</p>', 'correct_truefalse' => false]);
    }

    private function scaffold_params(\stdClass $course): array {
        return ['course_id' => (int)$course->id, 'quiz_title' => 'Actions scaffold quiz', 'questions' => [[
            'name' => 'Actions scaffold false', 'text' => 'Two plus two equals five.',
            'qtype' => 'truefalse', 'correct_truefalse' => false,
        ]]];
    }

    private function set_permission(string $capability, int $contextid, int $permission): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, $permission, $roleid, $contextid, true);
        accesslib_clear_all_caches(true);
    }

    private function assert_false_is_correct(int $questionid): void {
        $question = \question_bank::load_question($questionid);
        [$correct] = $question->grade_response(['answer' => 0]);
        [$incorrect] = $question->grade_response(['answer' => 1]);
        $this->assertEquals(1.0, $correct, 'False must receive full credit.');
        $this->assertEquals(0.0, $incorrect, 'True must receive no credit.');
    }

    private function snapshot(): array {
        global $DB;
        $tables = ['course_modules', 'question_categories', 'question', 'question_answers', 'question_bank_entries',
            'question_versions', 'question_truefalse', 'quiz', 'quiz_slots', 'question_references'];
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

    private function assert_error(callable $action, string $errorcode): void {
        try {
            $action();
            $this->fail('Expected the action to be rejected with ' . $errorcode);
        } catch (\moodle_exception $error) {
            $this->assertSame($errorcode, $error->errorcode);
        }
    }

    private function assert_unchanged_denial(callable $action, string $errorcode): void {
        $before = $this->snapshot();
        $this->assert_error($action, $errorcode);
        $this->assertSame($before, $this->snapshot(), 'A denied action must preserve assessment entity IDs and values.');
    }
}
