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

/**
 * MCP tool to list course groups and student members accessible by teacher.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_group_students extends base_tool {

    public function get_name(): string {
        return 'get_group_students';
    }

    public function get_description(): string {
        return 'Retrieve students in course groups where the authenticated teacher has grading or viewing permissions.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Moodle course ID.',
                ],
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Optional specific group ID to query. If omitted, returns all accessible groups in the course.',
                ],
            ],
            'required' => ['course_id'],
        ];
    }

    public function execute(array $params) {
        global $USER, $DB;

        $courseid = (int)($params['course_id'] ?? 0);
        $targetgroupid = (int)($params['group_id'] ?? 0);

        // H2: Teachers have course access via enrollment; require viewparticipants instead of course:view.
        $course = $this->require_course_access($courseid, 'moodle/course:viewparticipants');
        $context = \context_course::instance($courseid);

        $canaccessall = has_capability('moodle/site:accessallgroups', $context);

        // Get allowed groups for user.
        if ($canaccessall) {
            $allowedgroups = groups_get_all_groups($courseid);
        } else {
            $allowedgroups = groups_get_all_groups($courseid, $USER->id);
        }

        if ($targetgroupid > 0) {
            if (!isset($allowedgroups[$targetgroupid])) {
                throw new moodle_exception('error_no_group_access', 'tool_teachermcp', '', null,
                    "Group ID {$targetgroupid} is not accessible or does not exist in this course.");
            }
            $groupsquery = [$targetgroupid => $allowedgroups[$targetgroupid]];
        } else {
            $groupsquery = $allowedgroups;
        }

        $result = [];
        $userfields = \core_user\fields::for_name()->including('id', 'email')->get_sql('u', false, '', '', false)->selects;
        foreach ($groupsquery as $gid => $grp) {
            $members = groups_get_members($gid, $userfields);
            $students = [];
            foreach ($members as $m) {
                $students[] = [
                    'student_id' => (int)$m->id,
                    'fullname' => fullname($m),
                    'email' => $m->email,
                ];
            }

            $result[] = [
                'group_id' => (int)$grp->id,
                'name' => $grp->name,
                'description' => strip_tags($grp->description ?? ''),
                'member_count' => count($students),
                'students' => $students,
            ];
        }

        return [
            'course_id' => $courseid,
            'total_groups' => count($result),
            'groups' => $result,
        ];
    }
}
