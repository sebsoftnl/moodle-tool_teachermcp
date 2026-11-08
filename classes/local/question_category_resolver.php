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

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\question_bank_helper;
use stdClass;

/**
 * Resolves writable native question categories within an authorized course.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_category_resolver {
    /**
     * Select an explicit category or resolve the course's native default bank.
     *
     * @param stdClass $course Course already checked for enrolment and tool access by the caller.
     * @param int $categoryid Explicit category ID, or zero for the native default.
     * @return stdClass Native question_categories record with question-add permission.
     */
    public static function resolve(stdClass $course, int $categoryid = 0): stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $coursecontext = \context_course::instance($course->id);

        if ($categoryid > 0) {
            $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
            $context = \context::instance_by_id($category->contextid, IGNORE_MISSING);
            $categorycourse = $context ? $context->get_course_context(false) : null;
            if (!$categorycourse || $categorycourse->id != $coursecontext->id) {
                throw new \moodle_exception(
                    'error_invalid_params',
                    'tool_teachermcp',
                    '',
                    null,
                    'Category does not belong to this course.'
                );
            }
            require_capability('moodle/question:add', $context);
            return $category;
        }

        if (!class_exists(question_bank_helper::class)) {
            require_capability('moodle/question:add', $coursecontext);
            return question_make_default_categories([$coursecontext]);
        }

        $modname = question_bank_helper::get_default_question_bank_activity_name();
        $banks = question_bank_helper::get_activity_instances_with_shareable_questions(
            [$course->id],
            [],
            ['moodle/question:add']
        );
        $selected = null;
        foreach ($banks as $bank) {
            $cm = $bank->cminfo;
            if (
                $cm->modname !== $modname || !$DB->record_exists($modname, [
                'id' => $cm->instance, 'type' => question_bank_helper::TYPE_STANDARD,
                ])
            ) {
                continue;
            }
            if ($selected === null || (int)$cm->id < (int)$selected->id) {
                $selected = $cm;
            }
        }

        if ($selected === null) {
            require_capability('moodle/question:add', $coursecontext);
            require_capability('moodle/course:manageactivities', $coursecontext);
            $selected = question_bank_helper::create_default_open_instance(
                $course,
                question_bank_helper::get_bank_name_string('defaultbank', 'core_question', ['coursename' => $course->fullname]),
                question_bank_helper::TYPE_STANDARD
            );
        }

        require_capability('moodle/question:add', $selected->context);
        return question_get_default_category($selected->context->id, true);
    }
}
