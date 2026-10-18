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

/**
 * Test-only forwarding must be loaded before the first tool invocation to bind namespaced native calls.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp\tools;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/assessment_faults.php');

use tool_teachermcp\tests\fixtures\assessment_faults;

function create_module($moduleinfo) {
    $created = \create_module($moduleinfo);
    assessment_faults::hit('module', (int)$created->course, [
        'cmid' => (int)$created->coursemodule, 'instanceid' => (int)$created->instance,
    ]);
    return $created;
}

function quiz_require_question_use($questionid) {
    \quiz_require_question_use($questionid);
    assessment_faults::question_authorized((int)$questionid);
}

function quiz_add_quiz_question($questionid, $quiz, $page = 0, $maxmark = null) {
    $result = \quiz_add_quiz_question($questionid, $quiz, $page, $maxmark);
    assessment_faults::hit('slot', (int)$quiz->course, ['questionid' => (int)$questionid, 'quizid' => (int)$quiz->id]);
    return $result;
}
