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
 * MCP tool to retrieve detailed course contents, sections, and activities.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_contents extends base_tool {

    public function get_name(): string {
        return 'get_course_contents';
    }

    public function get_description(): string {
        return 'Retrieve structure, sections, and activities (quizzes, assignments, resources, forums) of a specified course.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'The Moodle course ID to inspect.',
                ],
                'include_descriptions' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include intro/description texts for activities.',
                    'default' => true,
                ],
            ],
            'required' => ['course_id'],
        ];
    }

    public function execute(array $params) {
        $courseid = (int)($params['course_id'] ?? 0);
        $includedescs = !empty($params['include_descriptions'] ?? true);

        // H2: Teachers have course access via enrollment; require manageactivities instead of course:view.
        $course = $this->require_course_access($courseid, 'moodle/course:manageactivities');

        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();

        $sectionsdata = [];

        foreach ($sections as $sectionnum => $secinfo) {
            // Check if teacher can view section.
            if (!$secinfo->uservisible) {
                continue;
            }

            $activities = [];
            if (!empty($modinfo->sections[$sectionnum])) {
                foreach ($modinfo->sections[$sectionnum] as $cmid) {
                    $cm = $modinfo->cms[$cmid];
                    if (!$cm->uservisible) {
                        continue;
                    }

                    $act = [
                        'cmid' => (int)$cm->id,
                        'instance_id' => (int)$cm->instance,
                        'modname' => $cm->modname,
                        'name' => $cm->name,
                        'visible' => (bool)$cm->visible,
                        'completion_enabled' => ($cm->completion > 0),
                    ];

                    if ($includedescs) {
                        if (!empty($cm->content)) {
                            $act['description'] = strip_tags($cm->content);
                        } else {
                            global $DB;
                            try {
                                $intro = $DB->get_field($cm->modname, 'intro', ['id' => $cm->instance]);
                                if ($intro) {
                                    $act['description'] = strip_tags($intro);
                                }
                            } catch (\Throwable $e) {
                                // Some modules may not have an intro column.
                            }
                        }
                    }

                    $activities[] = $act;
                }
            }

            $secname = $secinfo->name;
            if (empty($secname)) {
                if (get_string_manager()->string_exists('sectionname', 'format_' . $course->format)) {
                    $secname = get_string('sectionname', 'format_' . $course->format) . ' ' . $sectionnum;
                } else {
                    $secname = "Section {$sectionnum}";
                }
            }

            $sectionsdata[] = [
                'section_number' => (int)$sectionnum,
                'name' => $secname,
                'summary' => $includedescs ? strip_tags($secinfo->summary) : '',
                'visible' => (bool)$secinfo->visible,
                'activity_count' => count($activities),
                'activities' => $activities,
            ];
        }

        return [
            'course_id' => $courseid,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'sections' => $sectionsdata,
        ];
    }
}
