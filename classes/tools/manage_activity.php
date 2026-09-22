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
 * MCP tool to inspect and update activity settings and visibility.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_activity extends base_tool {

    public function get_name(): string {
        return 'manage_activity';
    }

    public function get_description(): string {
        return 'Get settings for a course activity, or update the name, description, and visibility of a Moodle Page activity.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module ID (cmid) of the activity.',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: "get_settings" for any activity or "update_settings" for a Moodle Page activity.',
                    'enum' => ['get_settings', 'update_settings'],
                    'default' => 'get_settings',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New activity title/name (when updating).',
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'New activity introduction/description text (when updating).',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Set visibility status (true = visible to students, false = hidden).',
                ],
            ],
            'required' => ['cmid', 'action'],
        ];
    }

    public function execute(array $params) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $cmid = (int)($params['cmid'] ?? 0);
        $action = $params['action'] ?? 'get_settings';

        list($cm, $course, $context) = $this->require_cm_access($cmid, 'moodle/course:manageactivities');

        $instancerecord = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);

        if ($action === 'get_settings') {
            return [
                'cmid' => (int)$cm->id,
                'course_id' => (int)$course->id,
                'modname' => $cm->modname,
                'name' => $instancerecord->name,
                'intro' => $instancerecord->intro ?? '',
                'visible' => (bool)$cm->visible,
                'timecreated' => !empty($instancerecord->timecreated) ? (int)$instancerecord->timecreated : null,
                'timemodified' => !empty($instancerecord->timemodified) ? (int)$instancerecord->timemodified : null,
            ];
        }

        if ($action === 'update_settings') {
            if ($cm->modname !== 'page') {
                throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', null,
                    'Updating settings is currently supported only for Page activities.');
            }
            // H7: Use core modlib API to update instance and grade item definitions.
            $coursemodule = get_coursemodule_from_id('page', $cm->id, 0, false, MUST_EXIST);
            [, , , $moduleinfo] = get_moduleinfo_data($coursemodule, $course);
            $hascontentupdates = false;

            if (isset($params['name'])) {
                $moduleinfo->name = clean_param($params['name'], PARAM_TEXT);
                $hascontentupdates = true;
            }

            if (isset($params['intro'])) {
                $cleanintro = $this->clean_agent_html($params['intro']);
                $moduleinfo->intro = $cleanintro;
                $moduleinfo->introformat = FORMAT_HTML;
                $moduleinfo->introeditor = [
                    'text' => $cleanintro,
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
                $hascontentupdates = true;
            }

            $moduleinfo->page = [
                'text' => $instancerecord->content,
                'format' => $instancerecord->contentformat,
                'itemid' => 0,
            ];
            $moduleinfo->display = $instancerecord->display;
            $moduleinfo->printintro = 0;
            $moduleinfo->printlastmodified = 0;
            $moduleinfo->revision = $instancerecord->revision;

            if ($hascontentupdates) {
                update_moduleinfo($coursemodule, $moduleinfo, $course);
            }

            // H7: Changing visibility requires moodle/course:activityvisibility.
            if (isset($params['visible'])) {
                require_capability('moodle/course:activityvisibility', $context);
                set_coursemodule_visible($cm->id, (int)$params['visible']);
                \core\event\course_module_updated::create_from_cm($cm)->trigger();
                rebuild_course_cache($course->id, true);
            }

            // Fetch live state from database.
            $updatedcm = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
            $currentname = $DB->get_field($cm->modname, 'name', ['id' => $cm->instance]);

            return [
                'success' => true,
                'message' => "Activity {$cm->id} ({$cm->modname}) successfully updated.",
                'cmid' => (int)$cm->id,
                'name' => $currentname,
                'visible' => (bool)$updatedcm->visible,
            ];
        }

        throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', null, "Unsupported action: {$action}");
    }
}
