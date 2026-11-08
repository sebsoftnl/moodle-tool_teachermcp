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
 * Test-only faulting_scaffold fixture class.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * Observes real bank/child work before saving, or real question work after the native save.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class faulting_scaffold extends \tool_teachermcp\tools\scaffold_assessment_unit {
    /**
     * Save the question with injectable native boundary observations.
     *
     * @param int $categoryid Question category ID.
     * @param array $qdef Question definition.
     * @return \stdClass Saved question.
     */
    protected function build_and_save_question(int $categoryid, array $qdef): \stdClass {
        if (!assessment_faults::observes('scaffold')) {
            return parent::build_and_save_question($categoryid, $qdef);
        }
        $courseid = assessment_faults::category_course($categoryid);
        assessment_faults::hit('bank', $courseid, ['categoryid' => $categoryid]);
        $question = parent::build_and_save_question($categoryid, $qdef);
        assessment_faults::hit('question', $courseid, ['categoryid' => $categoryid, 'questionid' => (int)$question->id]);
        return $question;
    }
}
