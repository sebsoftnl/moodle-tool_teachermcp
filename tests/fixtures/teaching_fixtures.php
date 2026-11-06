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

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Native teaching fixtures shared by PHPUnit and the owned CLI harness.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teaching_fixtures {
    /**
     * Create allowed and denied course groups for teaching tests.
     *
     * @param \testing_data_generator $generator Moodle test generator.
     * @param \stdClass $course Course record.
     * @param \stdClass $teacher Teacher record.
     * @param array $students Student records.
     * @return array Created groups.
     */
    public static function groups(
        \testing_data_generator $generator,
        \stdClass $course,
        \stdClass $teacher,
        array $students
    ): array {
        global $DB;
        $allowed = $generator->create_group(['courseid' => $course->id, 'name' => 'Teaching Group A']);
        $denied = $generator->create_group(['courseid' => $course->id, 'name' => 'Teaching Group B']);
        foreach ([$teacher, $students['alpha'], $students['beta'], $students['gamma']] as $user) {
            $generator->create_group_member(['groupid' => $allowed->id, 'userid' => $user->id]);
        }
        $generator->create_group_member(['groupid' => $denied->id, 'userid' => $students['outside']->id]);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $role, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches(true);
        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /**
     * Create a native assignment with teaching fixture defaults.
     *
     * @param \testing_data_generator $generator Moodle test generator.
     * @param \stdClass $course Course record.
     * @param array $params Module overrides.
     * @return \assign Created assignment.
     */
    public static function assignment(\testing_data_generator $generator, \stdClass $course, array $params = []): \assign {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $instance = $generator->create_module('assign', $params + [
            'course' => $course->id, 'name' => 'Owned teaching assignment', 'grade' => 100, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0,
            'assignfeedback_comments_enabled' => 1, 'assignfeedback_file_enabled' => 1,
            'groupmode' => SEPARATEGROUPS, 'duedate' => 0, 'cutoffdate' => 0, 'markingworkflow' => 0,
            'sendnotifications' => 0, 'sendlatenotifications' => 0, 'sendstudentnotifications' => 0,
        ]);
        $cm = get_coursemodule_from_id('assign', $instance->cmid, 0, false, MUST_EXIST);
        return new \assign(\context_module::instance($cm->id), $cm, $course);
    }

    /**
     * Submit an assignment as the given student.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student record.
     * @param string $text Submission text.
     * @return \stdClass Native submission record.
     */
    public static function submit(\assign $assign, \stdClass $student, string $text): \stdClass {
        global $USER;
        $original = $USER;
        \core\session\manager::set_user($student);
        try {
            $notices = [];
            $data = (object)['userid' => $student->id,
                'onlinetext_editor' => ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()]];
            if (!$assign->save_submission($data, $notices) || $notices) {
                throw new \RuntimeException('Native submission fixture failed: ' . json_encode($notices));
            }
            $submission = $assign->get_instance()->teamsubmission
                ? $assign->get_group_submission($student->id, 0, false)
                : $assign->get_user_submission($student->id, false);
            if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                throw new \RuntimeException('Native submission fixture did not reach submitted status');
            }
            return $submission;
        } finally {
            \core\session\manager::set_user($original);
        }
    }

    /**
     * Configure and save an assignment rubric.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $teacher Teacher record.
     * @return \stdClass Saved rubric definition.
     */
    public static function rubric(\assign $assign, \stdClass $teacher): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $manager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        $manager->set_active_method('rubric');
        $controller = $manager->get_controller('rubric');
        $criteria = [];
        foreach ([['Accuracy', 2, 'Accurate'], ['Reasoning', 3, 'Explained']] as $index => [$description, $score, $level]) {
            $criteria['NEWID' . ($index + 1)] = ['description' => '<b>' . $description . '</b>',
                'descriptionformat' => FORMAT_HTML, 'sortorder' => $index, 'levels' => [
                    'NEWID1' => ['score' => 0, 'definition' => 'Missing', 'definitionformat' => FORMAT_HTML],
                    'NEWID2' => ['score' => $score, 'definition' => '<i>' . $level . '</i>', 'definitionformat' => FORMAT_HTML],
                ]];
        }
        $definition = (object)['name' => 'Task17 owned rubric',
            'description_editor' => ['text' => '<p>Owned rubric description</p>', 'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid()],
            'rubric' => ['criteria' => $criteria, 'options' => \gradingform_rubric_controller::get_default_options()],
            'saverubric' => 'Save rubric and make it ready', 'status' => \gradingform_controller::DEFINITION_STATUS_READY];
        $controller->update_definition($definition, $teacher->id);
        return $controller->get_definition(true);
    }

    /**
     * Create a stored analytics prediction without training a model.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $student Student record.
     * @param int $now Creation timestamp.
     * @return \stdClass Analytics model record.
     */
    public static function prediction(\stdClass $course, \stdClass $student, int $now): \stdClass {
        global $DB;
        $model = \core_analytics\model::create(
            \core_analytics\manager::get_target('\\core_course\\analytics\\target\\course_dropout'),
            [\core_analytics\manager::get_indicator('\\core\\analytics\\indicator\\any_write_action'),
                \core_analytics\manager::get_indicator('\\core\\analytics\\indicator\\read_actions')]
        );
        $record = $model->get_model_obj();
        if ($record->enabled || $record->trained) {
            throw new \RuntimeException('Stored prediction fixture must not enable or train a model');
        }
        $DB->insert_record('analytics_predictions', ['modelid' => $model->get_id(),
            'contextid' => \context_course::instance($course->id)->id, 'sampleid' => $student->id, 'rangeindex' => 0,
            'prediction' => 1, 'predictionscore' => 0.75, 'calculations' => '[]', 'timecreated' => $now]);
        return $record;
    }

    /**
     * Build a native quiz with the requested question types.
     *
     * @param \testing_data_generator $generator Moodle test generator.
     * @param \stdClass $course Course record.
     * @param \stdClass $teacher Teacher record.
     * @param array $types Question types.
     * @return \stdClass Quiz record.
     */
    public static function quiz(
        \testing_data_generator $generator,
        \stdClass $course,
        \stdClass $teacher,
        array $types
    ): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Owned native metric quiz',
            'groupmode' => SEPARATEGROUPS, 'preferredbehaviour' => 'deferredfeedback']);
        if (class_exists(\core_question\local\bank\question_bank_helper::class)) {
            $bank = $generator->create_module('qbank', ['course' => $course->id, 'name' => 'Owned teaching questions',
                'type' => \core_question\local\bank\question_bank_helper::TYPE_STANDARD]);
            $category = question_get_default_category(\context_module::instance($bank->cmid)->id, true);
        } else {
            $category = question_make_default_categories([\context_course::instance($course->id)]);
        }
        $editor = static fn($text) => ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => 0];
        foreach ($types as $index => $type) {
            $form = (object)['category' => $category->id, 'name' => 'Native slot ' . ($index + 1),
                'questiontext' => $editor('<p>Answer full or half.</p>'), 'generalfeedback' => $editor(''),
                'defaultmark' => 1, 'penalty' => 0, 'status' => 'ready'];
            if ($type === 'shortanswer') {
                $form->usecase = 0;
                $form->answer = ['full', 'half'];
                $form->fraction = ['1', '0.5'];
                $form->feedback = [$editor(''), $editor('')];
            } else if ($type === 'essay') {
                $form->responseformat = 'plain';
                $form->responserequired = 1;
                $form->responsefieldlines = 5;
                $form->attachments = 0;
                $form->attachmentsrequired = 0;
                $form->maxbytes = 0;
                $form->graderinfo = $editor('');
                $form->responsetemplate = $editor('');
            } else {
                throw new \InvalidArgumentException('Unsupported teaching fixture question type');
            }
            $question = \question_bank::get_qtype($type)->save_question(
                (object)['qtype' => $type, 'createdby' => $teacher->id, 'idnumber' => null],
                $form
            );
            quiz_add_quiz_question($question->id, $quiz);
        }
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        return $quiz;
    }

    /**
     * Submit a quiz attempt as a student, optionally finishing it.
     *
     * @param \testing_data_generator $generator Moodle test generator.
     * @param \stdClass $quiz Quiz record.
     * @param \stdClass $student Student record.
     * @param array $responses Student responses.
     * @param bool $finish Whether to finish the attempt.
     * @param array $previous Earlier responses.
     * @return \mod_quiz\quiz_attempt Quiz attempt.
     */
    public static function attempt(
        \testing_data_generator $generator,
        \stdClass $quiz,
        \stdClass $student,
        array $responses,
        bool $finish = true,
        array $previous = []
    ): \mod_quiz\quiz_attempt {
        global $USER;
        $original = $USER;
        \core\session\manager::set_user($student);
        try {
            $record = $generator->get_plugin_generator('mod_quiz')->create_attempt($quiz->id, $student->id);
            $usage = \question_engine::load_questions_usage_by_activity($record->uniqueid);
            foreach ([$previous, $responses] as $answers) {
                foreach ($answers as $slot => $response) {
                    $usage->process_action($slot, $response, time());
                }
            }
            \question_engine::save_questions_usage_by_activity($usage);
            $attempt = \mod_quiz\quiz_attempt::create($record->id);
            if ($finish) {
                if (method_exists($attempt, 'process_grade_submission')) {
                    $attempt->process_submit(time(), false);
                    $attempt->process_grade_submission(time());
                } else {
                    $attempt->process_finish(time(), false);
                }
            }
            return \mod_quiz\quiz_attempt::create($record->id);
        } finally {
            \core\session\manager::set_user($original);
        }
    }

    /**
     * Configure local messaging for the supplied recipients.
     *
     * @param array $recipients Recipient users.
     * @return void
     */
    public static function local_messages(array $recipients): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/messagelib.php');
        set_config('messaging', 1);
        set_config('moodle_instantmessage_disable', 0, 'message');
        foreach ($DB->get_records('message_processors') as $processor) {
            \core_message\api::update_processor_status($processor, 0);
        }
        get_message_processors(false, true);
        foreach ($recipients as $recipient) {
            set_user_preference('message_blocknoncontacts', \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER, $recipient);
        }
    }

    /**
     * Capture native table state for boundary assertions.
     *
     * @return array Native state snapshot.
     */
    public static function snapshot(): array {
        global $DB;
        $result = [];
        foreach (
            ['assign', 'assign_submission', 'assign_grades', 'assign_user_flags', 'assignfeedback_comments',
                'assignfeedback_file', 'grade_items', 'grade_grades', 'files', 'course_modules_completion', 'event',
                'messages', 'message_conversations', 'message_conversation_members'] as $table
        ) {
            $result[$table] = array_values($DB->get_records($table, [], 'id ASC'));
        }
        return $result;
    }
}
