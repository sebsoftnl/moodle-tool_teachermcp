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

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolve dashboard context against the same course catalog exposed to agents.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_courses {
    /**
     * Return the connection or dashboard data for the current user.
     *
     * @param int $requestedid requestedid value.
     * @return array Result of the operation.
     */
    public static function get(int $requestedid = 0): array {
        $courses = (new \tool_teachermcp\tools\get_teacher_courses())->execute([])['courses'];
        $selected = $requestedid === 0 ? ($courses[0] ?? null) : null;
        foreach ($courses as $course) {
            if ($course['id'] === $requestedid) {
                $selected = $course;
                break;
            }
        }
        return ['courses' => $courses, 'selected' => $selected];
    }
}
