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
use Throwable;
use question_version_status;

/**
 * MCP tool to scaffold an assessment unit (Quiz + question pool) via official Moodle APIs.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scaffold_assessment_unit extends base_tool {

    public function get_name(): string {
        return 'scaffold_assessment_unit';
    }

    public function get_description(): string {
        return 'Scaffold an assessment unit: creates questions in the question bank, generates a new Quiz activity, and attaches the questions to slots.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle course ID to add the quiz to.',
                ],
                'quiz_title' => [
                    'type' => 'string',
                    'description' => 'Title of the new Quiz activity.',
                ],
                'quiz_intro' => [
                    'type' => 'string',
                    'description' => 'Introductory instructions for students taking the quiz.',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Course section number (0 = general top section, 1 = section 1, etc.).',
                    'default' => 0,
                ],
                'time_limit_minutes' => [
                    'type' => 'integer',
                    'description' => 'Time limit in minutes (0 = no limit).',
                    'default' => 0,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Whether the quiz should be immediately visible to students (default: false).',
                    'default' => false,
                ],
                'questions' => [
                    'type' => 'array',
                    'description' => 'List of questions to create and attach to the quiz.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Short question name/label.'],
                            'text' => ['type' => 'string', 'description' => 'The question body text.'],
                            'qtype' => [
                                'type' => 'string',
                                'enum' => ['multichoice', 'truefalse', 'essay', 'shortanswer'],
                                'description' => 'Question type.',
                            ],
                            'defaultmark' => ['type' => 'number', 'description' => 'Points for this question (default 1.0).'],
                            'generalfeedback' => ['type' => 'string', 'description' => 'General feedback shown after attempt.'],
                            'options' => [
                                'type' => 'array',
                                'description' => 'Options for multichoice or shortanswer questions.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'answer' => ['type' => 'string', 'description' => 'Option text.'],
                                        'fraction' => ['type' => 'number', 'description' => 'Score fraction (1.0 = correct, 0.0 = wrong).'],
                                        'feedback' => ['type' => 'string', 'description' => 'Specific feedback if this option is chosen.'],
                                    ],
                                    'required' => ['answer', 'fraction'],
                                ],
                            ],
                            'correct_truefalse' => [
                                'type' => 'boolean',
                                'description' => 'Required for truefalse questions (true = True is correct, false = False is correct).',
                            ],
                        ],
                        'required' => ['name', 'text', 'qtype'],
                    ],
                ],
            ],
            'required' => ['course_id', 'quiz_title', 'questions'],
        ];
    }

    public function execute(array $params) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');

        $courseid = (int)($params['course_id'] ?? 0);
        $quiztitle = clean_param($params['quiz_title'] ?? '', PARAM_TEXT);
        $quizintro = clean_text($params['quiz_intro'] ?? '');
        $sectionnum = (int)($params['section_number'] ?? 0);
        $timelimit = (int)($params['time_limit_minutes'] ?? 0);
        $visible = !empty($params['visible']);
        $questionsdata = $params['questions'] ?? [];

        if (empty($quiztitle)) {
            throw new moodle_exception('missingparam', 'error', '', 'quiz_title');
        }

        if (empty($questionsdata)) {
            throw new moodle_exception('missingparam', 'error', '', 'questions');
        }

        // H6: Capability checks.
        $course = $this->require_course_access($courseid, [
            'moodle/course:manageactivities',
            'mod/quiz:addinstance',
            'moodle/question:add',
        ]);
        $context = \context_course::instance($course->id);

        if ($visible) {
            require_capability('moodle/course:activityvisibility', $context);
        }

        // H6: Section number must exist in course.
        $modinfo = get_fast_modinfo($course);
        $allsections = $modinfo->get_section_info_all();
        if (!array_key_exists($sectionnum, $allsections)) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp', '', null,
                "Section number {$sectionnum} does not exist in this course.");
        }

        // H6: Pre-validate question definitions before starting transaction.
        $allowedfractions = \question_bank::fraction_options_full();
        foreach ($questionsdata as $qidx => $qdef) {
            $this->validate_question_definition($qdef, $qidx, $allowedfractions);
        }

        // H6: Execute in delegated database transaction.
        $transaction = $DB->start_delegated_transaction();

        try {
            // Find default category or create subcategory under it (never parent=0).
            $defaultcat = question_get_default_category($context->id);
            $catname = clean_param($quiztitle . ' Questions', PARAM_TEXT);

            $category = $DB->get_record('question_categories', [
                'contextid' => $context->id,
                'name' => $catname,
                'parent' => $defaultcat->id,
            ]);

            if (!$category) {
                $category = new stdClass();
                $category->name = $catname;
                $category->contextid = $context->id;
                $category->info = 'Automated question pool generated by Teacher MCP';
                $category->infoformat = FORMAT_HTML;
                $category->stamp = make_unique_id_code();
                $category->parent = $defaultcat->id;
                $category->sortorder = 999;
                $category->id = $DB->insert_record('question_categories', $category);
            }

            // 1. Create questions.
            $createdquestions = [];
            foreach ($questionsdata as $qdef) {
                $createdquestions[] = $this->build_and_save_question($category->id, $qdef);
            }

            // 2. Prepare full moduleinfo and call create_module().
            $quizconfig = get_config('quiz');
            $moduleinfo = new stdClass();
            $moduleinfo->modulename = 'quiz';
            $moduleinfo->course = $course->id;
            $moduleinfo->section = $sectionnum;
            $moduleinfo->visible = $visible ? 1 : 0;
            $moduleinfo->visibleoncoursepage = 1;
            $moduleinfo->name = $quiztitle;
            $moduleinfo->introeditor = [
                'text' => $quizintro,
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
            $moduleinfo->timeopen = 0;
            $moduleinfo->timeclose = 0;
            $moduleinfo->timelimit = $timelimit * 60;
            $moduleinfo->overduehandling = $quizconfig->overduehandling ?? 'autosubmit';
            $moduleinfo->graceperiod = $quizconfig->graceperiod ?? 86400;
            $moduleinfo->preferredbehaviour = $quizconfig->preferredbehaviour ?? 'deferredfeedback';
            $moduleinfo->attempts = $quizconfig->attempts ?? 0;
            $moduleinfo->attemptonlast = 0;
            $moduleinfo->grademethod = $quizconfig->grademethod ?? 1;
            $moduleinfo->decimalpoints = $quizconfig->decimalpoints ?? 2;
            $moduleinfo->questiondecimalpoints = $quizconfig->questiondecimalpoints ?? -1;

            // Explicit quiz fields required by quiz_process_options() and core generator (H6).
            $moduleinfo->quizpassword = '';
            $moduleinfo->subnet = '';
            $moduleinfo->browsersecurity = '-';
            $moduleinfo->delay1 = 0;
            $moduleinfo->delay2 = 0;
            $moduleinfo->showuserpicture = 0;
            $moduleinfo->showblocks = 0;
            $moduleinfo->navmethod = $quizconfig->navmethod ?? 'free';
            $moduleinfo->canredoquestions = 0;
            $moduleinfo->shufflequestions = 0;
            $moduleinfo->completionminattempts = 0;
            $moduleinfo->completionattemptsexhausted = 0;
            $moduleinfo->completionpassorattemptsexhausted = 0;

            // Review options based on quiz site defaults and display_options.
            $moduleinfo->attemptduring = 1;
            $moduleinfo->correctnessduring = 1;
            $moduleinfo->maxmarksduring = 1;
            $moduleinfo->marksduring = 1;
            $moduleinfo->specificfeedbackduring = 1;
            $moduleinfo->generalfeedbackduring = 1;
            $moduleinfo->rightanswerduring = 1;
            $moduleinfo->overallfeedbackduring = 0;
            $moduleinfo->attemptimmediately = 1;
            $moduleinfo->correctnessimmediately = 1;
            $moduleinfo->maxmarksimmediately = 1;
            $moduleinfo->marksimmediately = 1;
            $moduleinfo->specificfeedbackimmediately = 1;
            $moduleinfo->generalfeedbackimmediately = 1;
            $moduleinfo->rightanswerimmediately = 1;
            $moduleinfo->overallfeedbackimmediately = 1;
            $moduleinfo->attemptopen = 1;
            $moduleinfo->correctnessopen = 1;
            $moduleinfo->maxmarksopen = 1;
            $moduleinfo->marksopen = 1;
            $moduleinfo->specificfeedbackopen = 1;
            $moduleinfo->generalfeedbackopen = 1;
            $moduleinfo->rightansweropen = 1;
            $moduleinfo->overallfeedbackopen = 1;
            $moduleinfo->attemptclosed = 1;
            $moduleinfo->correctnessclosed = 1;
            $moduleinfo->maxmarksclosed = 1;
            $moduleinfo->marksclosed = 1;
            $moduleinfo->specificfeedbackclosed = 1;
            $moduleinfo->generalfeedbackclosed = 1;
            $moduleinfo->rightanswerclosed = 1;
            $moduleinfo->overallfeedbackclosed = 1;

            $moduleinfo->reviewattempt = $quizconfig->reviewattempt ?? 0x11110;
            $moduleinfo->reviewcorrectness = $quizconfig->reviewcorrectness ?? 0x10000;
            $moduleinfo->reviewmarks = $quizconfig->reviewmarks ?? 0x10000;
            $moduleinfo->reviewmaxmarks = 0x10000;
            $moduleinfo->reviewspecificfeedback = $quizconfig->reviewspecificfeedback ?? 0x10000;
            $moduleinfo->reviewgeneralfeedback = $quizconfig->reviewgeneralfeedback ?? 0x10000;
            $moduleinfo->reviewrightanswer = $quizconfig->reviewrightanswer ?? 0x10000;
            $moduleinfo->reviewoverallfeedback = $quizconfig->reviewoverallfeedback ?? 0x10000;

            // Feedback fields for quiz_process_options().
            $moduleinfo->feedbacktext = [['text' => '', 'format' => FORMAT_HTML]];
            $moduleinfo->feedbackboundaries = [];

            $moduleinfo->questionsperpage = $quizconfig->questionsperpage ?? 1;
            $moduleinfo->shuffleanswers = $quizconfig->shuffleanswers ?? 1;
            $moduleinfo->grade = 10.0;

            $createdmodule = create_module($moduleinfo);
            $cmid = (int)$createdmodule->coursemodule;
            $quizid = (int)$createdmodule->instance;

            // 3. Attach questions to quiz slots.
            $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
            $quiz->cmid = $cmid;

            $slotsadded = 0;
            foreach ($createdquestions as $qrecord) {
                quiz_add_quiz_question($qrecord->id, $quiz);
                $slotsadded++;
            }

            // Recompute quiz grades and refresh course cache.
            \mod_quiz\quiz_settings::create($quizid)->get_grade_calculator()->recompute_quiz_sumgrades();
            \course_modinfo::purge_course_module_cache($course->id, $cmid);
            rebuild_course_cache($course->id, true);

            $transaction->allow_commit();

            return [
                'success' => true,
                'quiz_id' => $quizid,
                'cmid' => $cmid,
                'course_id' => (int)$course->id,
                'section_number' => $sectionnum,
                'title' => $quiztitle,
                'visible' => $visible,
                'total_questions_created' => count($createdquestions),
                'slots_attached' => $slotsadded,
                'view_url' => $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cmid,
            ];
        } catch (Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Validate a question definition against Moodle core grading rules (H6).
     */
    protected function validate_question_definition(array $qdef, int $idx, array $allowedfractions): void {
        $qtype = $qdef['qtype'] ?? '';
        $name = trim($qdef['name'] ?? '');
        $text = trim($qdef['text'] ?? '');

        if (empty($name) || empty($text)) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                "Question at index {$idx} must have non-empty 'name' and 'text'.");
        }

        if ($qtype === 'multichoice') {
            $options = $qdef['options'] ?? [];
            if (count($options) < 2) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    "Multichoice question '{$name}' must have at least 2 options.");
            }
            $hascorrect = false;
            foreach ($options as $opt) {
                $f = (float)($opt['fraction'] ?? 0.0);
                if (abs($f - 1.0) < 0.0001) {
                    $hascorrect = true;
                }
                $this->assert_valid_fraction($f, $allowedfractions, $name);
            }
            if (!$hascorrect) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    "Multichoice question '{$name}' must have at least one option with fraction 1.0.");
            }
        } else if ($qtype === 'shortanswer') {
            $options = $qdef['options'] ?? [];
            if (empty($options)) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    "Shortanswer question '{$name}' must have at least one answer.");
            }
            $hascorrect = false;
            foreach ($options as $opt) {
                $f = (float)($opt['fraction'] ?? 0.0);
                if (abs($f - 1.0) < 0.0001) {
                    $hascorrect = true;
                }
                $this->assert_valid_fraction($f, $allowedfractions, $name);
            }
            if (!$hascorrect) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    "Shortanswer question '{$name}' must have at least one answer with fraction 1.0.");
            }
        } else if ($qtype === 'truefalse') {
            if (!isset($qdef['correct_truefalse'])) {
                throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                    "Truefalse question '{$name}' requires 'correct_truefalse' boolean.");
            }
        }
    }

    /**
     * Check that a fraction is in Moodle's allowed fraction options.
     */
    protected function assert_valid_fraction(float $fraction, array $allowedfractions, string $qname): void {
        $found = false;
        foreach ($allowedfractions as $key => $label) {
            if (abs((float)$key - $fraction) < 0.0001) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new moodle_exception('error_invalid_params', 'tool_teachermcp', '', null,
                "Question '{$qname}' has invalid fraction '{$fraction}'.");
        }
    }

    /**
     * Save an individual question into Moodle question bank.
     */
    protected function build_and_save_question(int $categoryid, array $qdef): stdClass {
        $qtype = $qdef['qtype'];
        $qtypeobj = \question_bank::get_qtype($qtype);

        $form = new stdClass();
        $form->category = $categoryid;
        $form->name = clean_param($qdef['name'], PARAM_TEXT);
        $form->questiontext = ['text' => clean_text($qdef['text']), 'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => clean_text($qdef['generalfeedback'] ?? ''), 'format' => FORMAT_HTML];
        $form->defaultmark = isset($qdef['defaultmark']) ? (float)$qdef['defaultmark'] : 1.0;
        $form->penalty = 0.3333333;
        $form->status = question_version_status::QUESTION_STATUS_READY;

        // Feedback defaults.
        $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->shownumcorrect = 0;

        if ($qtype === 'multichoice') {
            $form->single = 1;
            $form->shuffleanswers = 1;
            $form->answernumbering = 'abc';
            $form->showstandardinstruction = 0;

            $options = $qdef['options'] ?? [];
            $form->noanswers = count($options);
            $form->answer = [];
            $form->fraction = [];
            $form->feedback = [];

            foreach ($options as $idx => $opt) {
                $form->answer[$idx] = ['text' => clean_text($opt['answer']), 'format' => FORMAT_HTML];
                $form->fraction[$idx] = (string)(float)$opt['fraction'];
                $form->feedback[$idx] = ['text' => clean_text($opt['feedback'] ?? ''), 'format' => FORMAT_HTML];
            }
        } else if ($qtype === 'truefalse') {
            $form->correctanswer = !empty($qdef['correct_truefalse']) ? 1 : 0;
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
            $options = $qdef['options'] ?? [];
            $form->noanswers = count($options);
            $form->answer = [];
            $form->fraction = [];
            $form->feedback = [];

            foreach ($options as $idx => $opt) {
                $form->answer[$idx] = clean_param($opt['answer'], PARAM_TEXT);
                $form->fraction[$idx] = (string)(float)$opt['fraction'];
                $form->feedback[$idx] = ['text' => clean_text($opt['feedback'] ?? ''), 'format' => FORMAT_HTML];
            }
        }

        return $qtypeobj->save_question(new stdClass(), $form);
    }
}
