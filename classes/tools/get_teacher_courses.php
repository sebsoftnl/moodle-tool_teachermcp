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

/**
 * MCP tool to list courses where the teacher is enrolled or has teaching roles.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_teacher_courses extends base_tool {

    public function get_name(): string {
        return 'get_teacher_courses';
    }

    public function get_description(): string {
        return 'Retrieve list of all courses where the authenticated teacher is enrolled with teaching or grading rights.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'only_active' => [
                    'type' => 'boolean',
                    'description' => 'If true, returns only currently ongoing courses based on start and end dates.',
                    'default' => false,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params) {
        global $USER, $DB;

        $onlyactive = !empty($params['only_active']);
        $now = time();

        $courses = enrol_get_all_users_courses($USER->id, true);
        $result = [];

        foreach ($courses as $c) {
            $context = \context_course::instance($c->id);

            // Filter out courses where user has no connectagent capability.
            if (!has_capability('tool/teachermcp:connectagent', $context) && !is_siteadmin()) {
                continue;
            }

            // Filter out courses where user has no teacher/grader capabilities.
            $canupdate = has_capability('moodle/course:update', $context);
            $cangrade = has_capability('moodle/grade:viewall', $context);
            $canmanage = has_capability('moodle/course:manageactivities', $context);

            if (!$canupdate && !$cangrade && !$canmanage && !is_siteadmin()) {
                continue;
            }

            if ($onlyactive) {
                if ($c->enddate > 0 && $c->enddate < $now) {
                    continue;
                }
                if ($c->startdate > 0 && $c->startdate > $now) {
                    continue;
                }
            }

            $catname = $DB->get_field('course_categories', 'name', ['id' => $c->category]);

            $result[] = [
                'id' => (int)$c->id,
                'shortname' => $c->shortname,
                'fullname' => $c->fullname,
                'idnumber' => $c->idnumber,
                'category' => $catname ?: 'Default',
                'startdate' => $c->startdate ? (int)$c->startdate : null,
                'startdate_iso' => $c->startdate ? date('c', $c->startdate) : null,
                'enddate' => $c->enddate ? (int)$c->enddate : null,
                'enddate_iso' => $c->enddate ? date('c', $c->enddate) : null,
                'visible' => (bool)$c->visible,
                'can_edit' => $canupdate,
                'can_grade' => $cangrade,
            ];
        }

        return [
            'total_courses' => count($result),
            'courses' => $result,
        ];
    }
}
