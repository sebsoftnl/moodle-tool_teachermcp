<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Side-effect-free access to native assignment grades.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignment_grading {
    /**
     * Read a grade without letting a latest-attempt lookup create a submission.
     *
     * @param \assign $assign Assignment whose access has already been checked.
     * @param int $userid Student ID.
     * @param int $attemptnumber Requested attempt, or -1 for latest.
     * @return \stdClass|null Existing grade, if any.
     */
    public static function read_grade(\assign $assign, int $userid, int $attemptnumber = -1): ?\stdClass {
        if ($attemptnumber < 0) {
            $submission = $assign->get_instance()->teamsubmission
                ? $assign->get_group_submission($userid, 0, false)
                : $assign->get_user_submission($userid, false);
            $attemptnumber = $submission ? (int)$submission->attemptnumber : 0;
        }
        // Native get_user_grade(..., false, -1) still creates the latest submission.
        return $assign->get_user_grade($userid, false, $attemptnumber) ?: null;
    }
}
