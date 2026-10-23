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

namespace tool_teachermcp\tools;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use stdClass;
use context_course;
use core_question\local\bank\question_version_status;
use tool_teachermcp\local\question_category_resolver;
use tool_teachermcp\local\numerical_question;

/**
 * MCP tool for interacting with the Moodle Question Bank (Moodle 4.0+ schema).
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_question_bank extends base_tool {

    public function get_name(): string {
        return 'manage_question_bank';
    }

    public function get_description(): string {
        return 'Manage Question Bank: list/create categories, list/get/create/update/delete questions, and attach questions to quizzes (Moodle 4.0+ schema).';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle course ID.',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'list_categories',
                        'list_questions',
                        'get_question',
                        'create_question',
                        'update_question',
                        'delete_question',
                        'add_to_quiz',
                    ],
                    'description' => 'Action to perform.',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Question category ID (for listing or placing questions).',
                ],
                'question_id' => [
                    'type' => 'integer',
                    'description' => 'Question ID (for get, update, delete, or add_to_quiz).',
                ],
                'quiz_id' => [
                    'type' => 'integer',
                    'description' => 'Quiz activity instance ID (for add_to_quiz).',
                ],
                'qtype' => [
                    'type' => 'string',
                    'enum' => ['multichoice', 'truefalse', 'shortanswer', 'essay', 'numerical'],
                    'description' => 'Question type (for create_question).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Question name/title.',
                ],
                'questiontext' => [
                    'type' => 'string',
                    'description' => 'Full question text (HTML or plain text).',
                ],
                'generalfeedback' => [
                    'type' => 'string',
                    'description' => 'General feedback shown to students after answering.',
                ],
                'defaultmark' => [
                    'type' => 'number',
                    'description' => 'Default points/marks for this question (default: 1.0).',
                ],
                'penalty' => [
                    'type' => 'number',
                    'description' => 'Penalty for each incorrect attempt (default: 0.3333333).',
                ],
                'single' => [
                    'type' => 'boolean',
                    'description' => 'For multichoice: true for single correct answer, false for multiple correct answers (default: true).',
                ],
                'correct_truefalse' => [
                    'type' => 'boolean',
                    'description' => 'For truefalse: true if True is the correct answer, false if False is correct.',
                ],
                'options' => [
                    'type' => 'array',
                    'description' => 'Answer options for multichoice, shortanswer or numerical questions. For numerical questions, supplied options replace the answer list; omit them on update to preserve existing answers.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'answer' => ['type' => 'string', 'description' => 'Numerical answers use dot-decimal or scientific notation without units.'],
                            'fraction' => ['type' => 'number', 'description' => 'Grade fraction between -1.0 and 1.0; numerical answers use Moodle-supported fractions from 0.0 to 1.0.'],
                            'feedback' => ['type' => 'string'],
                            'tolerance' => ['type' => 'number', 'description' => 'Numerical answers: nonnegative absolute tolerance (default: 0).'],
                        ],
                        'required' => ['answer', 'fraction'],
                    ],
                ],
                'confirm_token' => [
                    'type' => 'string',
                    'description' => 'Cryptographic confirmation token required to proceed with delete_question (N3).',
                ],
            ],
            'required' => ['course_id', 'action'],
        ];
    }

    public function execute(array $params) {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $action = $params['action'] ?? 'list_categories';

        $course = $this->require_course_access($courseid, 'moodle/question:viewall');
        $context = context_course::instance($courseid);

        switch ($action) {
            case 'list_categories':
                return $this->action_list_categories($course, $context);

            case 'list_questions':
                return $this->action_list_questions($course, $context, $params);

            case 'get_question':
                return $this->action_get_question($course, $context, $params);

            case 'create_question':
                return $this->action_create_question($course, $context, $params);

            case 'update_question':
                return $this->action_update_question($course, $context, $params);

            case 'delete_question':
                return $this->action_delete_question($course, $context, $params);

            case 'add_to_quiz':
                return $this->action_add_to_quiz($course, $context, $params);

            default:
                throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', null, "Unsupported action: {$action}");
        }
    }

    /**
     * List categories in course context and child module contexts with question counts (Moodle 4.0+ schema).
     */
    protected function action_list_categories(stdClass $course, context_course $context): array {
        global $DB;

        // Find categories belonging to this course and its activities.
        $contexts = [$context->id];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->cms as $cm) {
            if (has_capability('moodle/question:viewall', $cm->context)) {
                $contexts[] = $cm->context->id;
            }
        }

        list($ctxsql, $ctxparams) = $DB->get_in_or_equal($contexts, SQL_PARAMS_NAMED);
        $categories = $DB->get_records_select('question_categories', "contextid $ctxsql", $ctxparams, 'name ASC');
        $list = [];

        foreach ($categories as $cat) {
            $sql = "SELECT COUNT(DISTINCT q.id)
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE qbe.questioncategoryid = :categoryid
                       AND q.parent = 0
                       AND qv.status <> :hiddenstatus";

            $count = $DB->count_records_sql($sql, [
                'categoryid' => $cat->id,
                'hiddenstatus' => question_version_status::QUESTION_STATUS_HIDDEN,
            ]);

            $list[] = [
                'category_id' => (int)$cat->id,
                'context_id' => (int)$cat->contextid,
                'name' => $cat->name,
                'info' => strip_tags($cat->info ?? ''),
                'question_count' => (int)$count,
            ];
        }

        return [
            'course_id' => $course->id,
            'total_categories' => count($list),
            'categories' => $list,
        ];
    }

    /**
     * List questions in category or course using Moodle 4.0+ schema (K4 scoped).
     */
    protected function action_list_questions(stdClass $course, context_course $context, array $params): array {
        global $DB;

        $categoryid = (int)($params['category_id'] ?? 0);

        if ($categoryid > 0) {
            $cat = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
            $catcontext = \context::instance_by_id($cat->contextid, IGNORE_MISSING);
            $catcoursecontext = $catcontext ? $catcontext->get_course_context(false) : null;
            if (!$catcoursecontext || $catcoursecontext->id != $context->id) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    'Category does not belong to this course.');
            }
            require_capability('moodle/question:viewall', $catcontext);
            $catids = [$categoryid];
        } else {
            $contexts = [$context->id];
            $modinfo = get_fast_modinfo($course);
            foreach ($modinfo->cms as $cm) {
                if (has_capability('moodle/question:viewall', $cm->context)) {
                    $contexts[] = $cm->context->id;
                }
            }
            list($ctxsql, $ctxparams) = $DB->get_in_or_equal($contexts, SQL_PARAMS_NAMED);
            $catids = $DB->get_fieldset_select('question_categories', 'id', "contextid $ctxsql", $ctxparams);
        }

        if (empty($catids)) {
            return ['course_id' => $course->id, 'total_questions' => 0, 'questions' => []];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED);
        $sql = "SELECT q.id, q.name, q.qtype, q.defaultmark, q.questiontext,
                       qbe.questioncategoryid, qv.version, qv.status
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE q.parent = 0
                   AND qbe.questioncategoryid $insql
                   AND qv.status <> :hiddenstatus
              ORDER BY q.name ASC";

        $inparams['hiddenstatus'] = question_version_status::QUESTION_STATUS_HIDDEN;
        $questions = $DB->get_records_sql($sql, $inparams);

        $list = [];
        foreach ($questions as $q) {
            $list[] = [
                'question_id' => (int)$q->id,
                'category_id' => (int)$q->questioncategoryid,
                'name' => $q->name,
                'qtype' => $q->qtype,
                'defaultmark' => (float)$q->defaultmark,
                'version' => (int)$q->version,
                'status' => $q->status,
                'questiontext' => shorten_text(strip_tags($q->questiontext), 120),
            ];
        }

        return [
            'course_id' => $course->id,
            'total_questions' => count($list),
            'questions' => $list,
        ];
    }

    /**
     * Inspect details of a specific question (Moodle 4.0+ schema with K4 context scoping).
     */
    protected function action_get_question(stdClass $course, context_course $context, array $params): array {
        global $DB;

        $questionid = (int)($params['question_id'] ?? 0);
        if (!$questionid) {
            throw new moodle_exception('missingparam', 'error', '', 'question_id');
        }

        $sql = "SELECT q.*, qbe.questioncategoryid, qv.version, qv.status, qc.contextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.id = :qid";

        $question = $DB->get_record_sql($sql, ['qid' => $questionid], MUST_EXIST);

        $qcontext = \context::instance_by_id($question->contextid, IGNORE_MISSING);
        if (!$qcontext) {
            throw new moodle_exception('error_item_not_found', 'tool_teachermcp');
        }

        // K4: Must belong to this course or module within this course.
        $qcoursecontext = $qcontext->get_course_context(false);
        if (!$qcoursecontext || $qcoursecontext->id != $context->id) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                'Question does not belong to this course.');
        }

        require_capability('moodle/question:viewall', $qcontext);

        if ($question->qtype === 'numerical') {
            \question_bank::get_qtype('numerical')->get_question_options($question);
            $answers = $question->options->answers;
        } else {
            $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
        }
        $answerslist = [];
        foreach ($answers as $ans) {
            $answer = [
                'answer_id' => (int)$ans->id,
                'answer' => strip_tags($ans->answer),
                'fraction' => (float)$ans->fraction,
                'feedback' => strip_tags($ans->feedback ?? ''),
            ];
            if ($question->qtype === 'numerical') {
                $answer['tolerance'] = (float)$ans->tolerance;
            }
            $answerslist[] = $answer;
        }

        $result = [
            'question_id' => (int)$question->id,
            'category_id' => (int)$question->questioncategoryid,
            'name' => $question->name,
            'qtype' => $question->qtype,
            'version' => (int)$question->version,
            'status' => $question->status,
            'defaultmark' => (float)$question->defaultmark,
            'penalty' => (float)$question->penalty,
            'questiontext' => strip_tags($question->questiontext),
            'generalfeedback' => strip_tags($question->generalfeedback ?? ''),
            'answers' => $answerslist,
        ];
        if ($question->qtype === 'numerical') {
            $result['numerical_options'] = numerical_question::describe_options($question->options);
        }
        return $result;
    }

    /**
     * Create a question using the Moodle Question API (K4).
     */
    protected function action_create_question(stdClass $course, context_course $context, array $params): array {
        global $DB, $USER;

        $qtype = trim($params['qtype'] ?? '');
        $supportedtypes = ['multichoice', 'truefalse', 'shortanswer', 'essay', 'numerical'];
        if (!in_array($qtype, $supportedtypes, true)) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                "Unsupported qtype '{$qtype}'. Must be one of: " . implode(', ', $supportedtypes));
        }

        $name = clean_param($params['name'] ?? '', PARAM_TEXT);
        if (empty($name)) {
            throw new moodle_exception('missingparam', 'error', '', 'name');
        }

        $questiontext = isset($params['questiontext']) ? $this->clean_agent_html($params['questiontext']) : '';
        if (empty($questiontext)) {
            throw new moodle_exception('missingparam', 'error', '', 'questiontext');
        }

        $this->validate_question_options($qtype, $params);
        $quizid = (int)($params['quiz_id'] ?? 0);
        $quiz = $quizid !== 0 ? $this->require_editable_quiz($course, $quizid) : null;
        $transaction = $DB->start_delegated_transaction();

        try {
            $category = question_category_resolver::resolve($course, (int)($params['category_id'] ?? 0));
            if ($quiz !== null) {
                require_capability('moodle/question:viewall', \context::instance_by_id($category->contextid));
            }
            $qtypeobj = \question_bank::get_qtype($qtype);

            $form = new stdClass();
            $form->category = $category->id;
            $form->name = $name;
            $form->questiontext = ['text' => $questiontext, 'format' => FORMAT_HTML];
            $form->generalfeedback = ['text' => isset($params['generalfeedback']) ? $this->clean_agent_html($params['generalfeedback']) : '', 'format' => FORMAT_HTML];
            $form->defaultmark = isset($params['defaultmark']) ? (float)$params['defaultmark'] : 1.0;
            $form->penalty = isset($params['penalty']) ? (float)$params['penalty'] : 0.3333333;
            $form->status = question_version_status::QUESTION_STATUS_READY;

            // Feedback defaults.
            $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
            $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
            $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
            $form->shownumcorrect = 0;

            if ($qtype === 'multichoice') {
                $issingle = !isset($params['single']) || !empty($params['single']);
                $form->single = $issingle ? 1 : 0;
                $form->shuffleanswers = 1;
                $form->answernumbering = 'abc';
                $form->showstandardinstruction = 0;

                $options = $params['options'] ?? [];
                $form->noanswers = count($options);
                $form->answer = [];
                $form->fraction = [];
                $form->feedback = [];

                foreach ($options as $idx => $opt) {
                    $form->answer[$idx] = ['text' => $this->clean_agent_html($opt['answer']), 'format' => FORMAT_HTML];
                    $form->fraction[$idx] = (string)(float)$opt['fraction'];
                    $form->feedback[$idx] = ['text' => $this->clean_agent_html($opt['feedback'] ?? ''), 'format' => FORMAT_HTML];
                }
            } else if ($qtype === 'truefalse') {
                $iscorrecttrue = !empty($params['correct_truefalse']);
                $form->correctanswer = $iscorrecttrue ? 1 : 0;
                $form->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
                $form->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
            } else if ($qtype === 'essay') {
                $form->responseformat = 'editor';
                $form->responserequired = 1;
                $form->responsefieldlines = 15;
                $form->attachments = 0;
                $form->attachmentsrequired = 0;
                $form->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
                $form->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
            } else if ($qtype === 'shortanswer') {
                $form->usecase = 0;
                $options = $params['options'] ?? [];
                $form->noanswers = count($options);
                $form->answer = [];
                $form->fraction = [];
                $form->feedback = [];

                foreach ($options as $idx => $opt) {
                    $form->answer[$idx] = clean_param($opt['answer'], PARAM_TEXT);
                    $form->fraction[$idx] = (string)(float)$opt['fraction'];
                    $form->feedback[$idx] = ['text' => $this->clean_agent_html($opt['feedback'] ?? ''), 'format' => FORMAT_HTML];
                }
            }

            if ($qtype === 'numerical') {
                numerical_question::prepare_form($form, $params);
            }

            $basequestion = new stdClass();
            $basequestion->qtype = $qtype;
            $basequestion->createdby = $USER->id;

            $created = $qtypeobj->save_question($basequestion, $form);

            $result = [
                'success' => true,
                'question_id' => (int)$created->id,
                'category_id' => (int)$category->id,
                'name' => $created->name,
                'qtype' => $created->qtype,
                'defaultmark' => (float)$created->defaultmark,
            ];

            if ($quiz !== null) {
                quiz_require_question_use($created->id);
                quiz_add_quiz_question($created->id, $quiz);
                \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
                $result['added_to_quiz_id'] = $quiz->id;
            }

            $transaction->allow_commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                $transaction->rollback($error);
            } finally {
                // Native module caches are outside the database transaction.
                $cache = \cache::make('core', 'coursemodinfo');
                $cache->acquire_lock($course->id);
                try {
                    $cache->delete($course->id);
                } finally {
                    $cache->release_lock($course->id);
                }
                \course_modinfo::clear_instance_cache();
            }
        }
    }

    /**
     * Update an existing question (K4 scoped).
     */
    protected function action_update_question(stdClass $course, context_course $context, array $params): array {
        global $CFG, $DB, $USER;

        $questionid = (int)($params['question_id'] ?? 0);
        if (!$questionid) {
            throw new moodle_exception('missingparam', 'error', '', 'question_id');
        }

        $sql = "SELECT q.*, qbe.questioncategoryid, qc.contextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.id = :qid";
        $existing = $DB->get_record_sql($sql, ['qid' => $questionid], MUST_EXIST);

        $qcontext = \context::instance_by_id($existing->contextid, IGNORE_MISSING);
        $qcoursecontext = $qcontext ? $qcontext->get_course_context(false) : null;
        if (!$qcoursecontext || $qcoursecontext->id != $context->id) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                'Question does not belong to this course.');
        }

        require_capability('moodle/question:editall', $qcontext);

        // B3: Validate category move and verify course context ownership and move capabilities.
        $targetcatid = (int)($params['category_id'] ?? 0);
        $newcat = null;
        if ($targetcatid > 0 && $targetcatid != $existing->questioncategoryid) {
            $newcat = $DB->get_record('question_categories', ['id' => $targetcatid], '*', MUST_EXIST);
            $newcatcontext = \context::instance_by_id($newcat->contextid, IGNORE_MISSING);
            $newcoursecontext = $newcatcontext ? $newcatcontext->get_course_context(false) : null;
            if (!$newcoursecontext || $newcoursecontext->id != $context->id) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    'Target question category does not belong to this course.');
            }

            require_once($CFG->dirroot . '/lib/questionlib.php');
            question_require_capability_on($existing, 'move');
            require_capability('moodle/question:add', $newcatcontext);

            $ismine = ($existing->createdby == $USER->id);
            if ($ismine) {
                if (!has_capability('moodle/question:moveall', $newcatcontext) && !has_capability('moodle/question:movemine', $newcatcontext)) {
                    require_capability('moodle/question:movemine', $newcatcontext);
                }
            } else {
                require_capability('moodle/question:moveall', $newcatcontext);
            }

        }

        if (isset($params['options']) && in_array($existing->qtype, ['multichoice', 'shortanswer'])) {
            $this->validate_question_options($existing->qtype, $params);
        }
        if ($existing->qtype === 'numerical' && array_key_exists('options', $params)) {
            numerical_question::validate_options($params['options']);
        }
        if ($newcat !== null) {
            question_move_questions_to_category([$existing->id], $newcat->id);
            $existing->questioncategoryid = $newcat->id;
        }

        $qtypeobj = \question_bank::get_qtype($existing->qtype);

        $form = new stdClass();
        $form->category = (int)$existing->questioncategoryid;
        $form->name = isset($params['name']) ? clean_param($params['name'], PARAM_TEXT) : $existing->name;
        $form->questiontext = [
            'text' => isset($params['questiontext']) ? $this->clean_agent_html($params['questiontext']) : $existing->questiontext,
            'format' => FORMAT_HTML,
        ];
        $form->generalfeedback = [
            'text' => isset($params['generalfeedback']) ? $this->clean_agent_html($params['generalfeedback']) : $existing->generalfeedback,
            'format' => FORMAT_HTML,
        ];
        $form->defaultmark = isset($params['defaultmark']) ? (float)$params['defaultmark'] : (float)$existing->defaultmark;
        $form->penalty = isset($params['penalty']) ? (float)$params['penalty'] : (float)$existing->penalty;
        $form->status = question_version_status::QUESTION_STATUS_READY;

        if ($existing->qtype === 'truefalse') {
            $existingoptions = $DB->get_record('question_truefalse', ['question' => $existing->id], '*', MUST_EXIST);
            $trueanswer = $DB->get_record('question_answers', ['id' => $existingoptions->trueanswer], '*', MUST_EXIST);
            $form->correctanswer = (int)$trueanswer->fraction;
            $form->feedbacktrue = ['text' => $trueanswer->feedback, 'format' => $trueanswer->feedbackformat];
            $falseanswer = $DB->get_record('question_answers', ['id' => $existingoptions->falseanswer], '*', MUST_EXIST);
            $form->feedbackfalse = ['text' => $falseanswer->feedback, 'format' => $falseanswer->feedbackformat];
        }

        if (isset($params['options']) && in_array($existing->qtype, ['multichoice', 'shortanswer'])) {
            $options = $params['options'];
            $form->noanswers = count($options);
            $form->answer = [];
            $form->fraction = [];
            $form->feedback = [];

            if ($existing->qtype === 'shortanswer') {
                $existingoptions = $DB->get_record('qtype_shortanswer_options', ['questionid' => $existing->id], '*', MUST_EXIST);
                $form->usecase = (int)$existingoptions->usecase;
                $existinganswers = $DB->get_records('question_answers', ['question' => $existing->id], 'id ASC');
                foreach ($existinganswers as $idx => $answer) {
                    $form->answer[$idx] = $answer->answer;
                    $form->fraction[$idx] = (string)(float)$answer->fraction;
                    $form->feedback[$idx] = ['text' => $answer->feedback, 'format' => $answer->feedbackformat];
                }
                foreach ($options as $idx => $opt) {
                    $form->answer[$idx] = clean_param($opt['answer'], PARAM_TEXT);
                    $form->fraction[$idx] = (string)(float)$opt['fraction'];
                    $form->feedback[$idx] = ['text' => $this->clean_agent_html($opt['feedback'] ?? ''), 'format' => FORMAT_HTML];
                }
            } else if ($existing->qtype === 'multichoice') {
                foreach ($options as $idx => $opt) {
                    $form->answer[$idx] = ['text' => $this->clean_agent_html($opt['answer']), 'format' => FORMAT_HTML];
                    $form->fraction[$idx] = (string)(float)$opt['fraction'];
                    $form->feedback[$idx] = ['text' => $this->clean_agent_html($opt['feedback'] ?? ''), 'format' => FORMAT_HTML];
                }

                $issingle = !isset($params['single']) || !empty($params['single']);
                $form->single = $issingle ? 1 : 0;
                $form->shuffleanswers = 1;
                $form->answernumbering = 'abc';
                $form->showstandardinstruction = 0;
            }
        }

        if ($existing->qtype === 'truefalse' && array_key_exists('correct_truefalse', $params)) {
            $form->correctanswer = !empty($params['correct_truefalse']) ? 1 : 0;
        }

        if ($existing->qtype === 'numerical') {
            numerical_question::prepare_form($form, $params, $existing);
        }

        $existing->modifiedby = $USER->id;
        $updated = $qtypeobj->save_question($existing, $form);

        return [
            'success' => true,
            'question_id' => (int)$updated->id,
            'name' => $updated->name,
            'qtype' => $updated->qtype,
            'defaultmark' => (float)$updated->defaultmark,
        ];
    }

    /**
     * Soft delete a question using Moodle question_delete_question (K4 scoped, N3 server-side confirmation).
     */
    protected function action_delete_question(stdClass $course, context_course $context, array $params): array {
        global $DB, $USER;

        // N3: Check site admin setting allow_delete_questions first.
        if (!get_config('tool_teachermcp', 'allow_delete_questions')) {
            throw new moodle_exception('error_delete_questions_disabled', 'tool_teachermcp');
        }

        $questionid = (int)($params['question_id'] ?? 0);
        if (!$questionid) {
            throw new moodle_exception('missingparam', 'error', '', 'question_id');
        }

        $sql = "SELECT q.*, qc.contextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.id = :qid";
        $existing = $DB->get_record_sql($sql, ['qid' => $questionid], MUST_EXIST);

        $qcontext = \context::instance_by_id($existing->contextid, IGNORE_MISSING);
        $qcoursecontext = $qcontext ? $qcontext->get_course_context(false) : null;
        if (!$qcoursecontext || $qcoursecontext->id != $context->id) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                'Question does not belong to this course.');
        }

        question_require_capability_on($existing, 'edit');

        // N3: Server-side confirmation token pattern.
        $token = trim($params['confirm_token'] ?? $params['confirmation_token'] ?? '');
        if (empty($token)) {
            $timestamp = time();
            $hash = hash_hmac('sha256', "delete_question:{$questionid}:{$USER->id}:{$timestamp}", get_site_identifier());
            $confirmationtoken = base64_encode("{$timestamp}:{$hash}");

            return [
                'success' => false,
                'requires_confirmation' => true,
                'question_id' => $questionid,
                'question_name' => $existing->name,
                'confirm_token' => $confirmationtoken,
                'expires_in_seconds' => 300,
                'message' => "Deleting a question is a destructive action. Call manage_question_bank with action='delete_question', question_id={$questionid}, and confirm_token='{$confirmationtoken}' within 5 minutes to permanently proceed.",
            ];
        }

        $rawtoken = base64_decode($token, true);
        if (!$rawtoken || strpos($rawtoken, ':') === false) {
            throw new moodle_exception('error_invalid_confirmation_token', 'tool_teachermcp');
        }
        list($tokentime, $tokenhash) = explode(':', $rawtoken, 2);
        $tokentime = (int)$tokentime;
        if (time() - $tokentime > 300 || $tokentime > time() + 60) {
            throw new moodle_exception('error_confirmation_token_expired', 'tool_teachermcp');
        }

        $expectedhash = hash_hmac('sha256', "delete_question:{$questionid}:{$USER->id}:{$tokentime}", get_site_identifier());
        if (!hash_equals($expectedhash, $tokenhash)) {
            throw new moodle_exception('error_invalid_confirmation_token', 'tool_teachermcp');
        }

        // Replay prevention.
        if (get_cache_flag('tool_teachermcp_usedtoken', $tokenhash)) {
            throw new moodle_exception('error_confirmation_token_expired', 'tool_teachermcp');
        }
        set_cache_flag('tool_teachermcp_usedtoken', $tokenhash, 1, time() + 600);

        question_delete_question($questionid);

        return [
            'success' => true,
            'question_id' => $questionid,
            'message' => "Question {$questionid} deleted or marked hidden.",
        ];
    }

    /**
     * Add existing question to a quiz (K4 scoped, B4 hardened).
     */
    protected function action_add_to_quiz(stdClass $course, context_course $context, array $params): array {
        global $DB;

        $quizid = (int)($params['quiz_id'] ?? 0);
        $questionid = (int)($params['question_id'] ?? 0);

        if (!$quizid || !$questionid) {
            throw new moodle_exception('missingparam', 'error', '', 'quiz_id and question_id');
        }

        $quiz = $this->require_editable_quiz($course, $quizid);

        // Verify question belongs to this course context.
        $sql = "SELECT q.*, qc.contextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.id = :qid";
        $question = $DB->get_record_sql($sql, ['qid' => $questionid], MUST_EXIST);

        $qcontext = \context::instance_by_id($question->contextid, IGNORE_MISSING);
        $qcoursecontext = $qcontext ? $qcontext->get_course_context(false) : null;
        if (!$qcoursecontext || $qcoursecontext->id != $context->id) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                'Question does not belong to this course.');
        }

        require_capability('moodle/question:viewall', $qcontext);
        quiz_require_question_use($question->id);
        quiz_add_quiz_question($question->id, $quiz);

        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

        return [
            'success' => true,
            'quiz_id' => (int)$quiz->id,
            'question_id' => (int)$question->id,
            'message' => "Question '{$question->name}' added to quiz '{$quiz->name}'.",
        ];
    }

    private function require_editable_quiz(stdClass $course, int $quizid): stdClass {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizid, 'course' => $course->id], '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($quiz->id, 'quiz', $course->id);
        $this->require_cm_access($cm->id, 'mod/quiz:manage');
        if (quiz_has_attempts($quiz->id)) {
            throw new moodle_exception('cannoteditafterattempts', 'quiz');
        }
        $quiz->cmid = $cm->id;
        return $quiz;
    }

    /**
     * Validate question options and answer fractions according to Moodle rules.
     */
    protected function validate_question_options(string $qtype, array $params): void {
        if ($qtype === 'numerical') {
            numerical_question::validate_options($params['options'] ?? []);
        } else if ($qtype === 'multichoice') {
            $options = $params['options'] ?? [];
            if (count($options) < 2) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    'Multichoice questions require at least 2 answer options.');
            }
            $hasfullmark = false;
            foreach ($options as $opt) {
                $frac = (float)($opt['fraction'] ?? 0.0);
                if (abs($frac - 1.0) < 0.001) {
                    $hasfullmark = true;
                }
            }
            if (!$hasfullmark) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    'At least one answer option must have a grade fraction of 1.0 (100% correct).');
            }
        } else if ($qtype === 'shortanswer') {
            $options = $params['options'] ?? [];
            if (empty($options)) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    'Shortanswer questions require at least one accepted answer option.');
            }
            $hasfullmark = false;
            foreach ($options as $opt) {
                if (abs((float)($opt['fraction'] ?? 0.0) - 1.0) < 0.001) {
                    $hasfullmark = true;
                }
            }
            if (!$hasfullmark) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    'Shortanswer questions require at least one answer with fraction 1.0.');
            }
        }
    }
}
