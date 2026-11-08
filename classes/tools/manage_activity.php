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
    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public function get_name(): string {
        return 'manage_activity';
    }

    /**
     * Return the MCP tool description.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        return 'Get basic activity settings; update Page name, description and visibility; or publish/unpublish ' .
            'Assignment, Quiz, H5P and Book using visibility-only updates. Other module settings must be edited in ' .
            'Moodle.';
    }

    /**
     * Return the JSON input schema for this tool.
     *
     * @return array Result of the operation.
     */
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
                    'description' => '"get_settings" reads basic settings. "update_settings" edits Pages; for Assignment, Quiz, H5P and Book, ' .
                        'supply only cmid, action and visible.',
                    'enum' => ['get_settings', 'update_settings'],
                    'default' => 'get_settings',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New Page title/name. Not supported for other module types.',
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'New Page introduction/description. Not supported for other module types.',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Set the visibility flag for Page, Assignment, Quiz, H5P or Book. Course/section visibility and availability restrictions still apply.',
                ],
            ],
            'required' => ['cmid', 'action'],
        ];
    }

    /**
     * Execute the tool using the validated arguments.
     *
     * @param array $params params value.
     */
    public function execute(array $params) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $cmid = (int)($params['cmid'] ?? 0);
        $action = $params['action'] ?? 'get_settings';

        [$cm, $course, $context] = $this->require_cm_access($cmid, 'moodle/course:manageactivities');

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
                if (
                    !in_array($cm->modname, ['assign', 'quiz', 'h5pactivity', 'book'], true)
                        || !isset($params['visible'])
                        || array_diff(array_keys($params), ['cmid', 'action', 'visible'])
                ) {
                    throw new moodle_exception(
                        'error_invalid_action',
                        'tool_teachermcp',
                        '',
                        get_string('error_activity_settings_restricted', 'tool_teachermcp', $cm->modname)
                    );
                }
                require_capability('moodle/course:activityvisibility', $context);
                set_coursemodule_visible($cm->id, (int)$params['visible'], (int)$cm->visibleoncoursepage);
                \core\event\course_module_updated::create_from_cm($cm)->trigger();
                rebuild_course_cache($course->id, true);
                $updatedcm = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
                return [
                    'success' => true,
                    'message' => "Activity {$cm->id} ({$cm->modname}) visibility updated; availability restrictions still apply.",
                    'cmid' => (int)$cm->id,
                    'name' => $instancerecord->name,
                    'visible' => (bool)$updatedcm->visible,
                ];
            }
            if (isset($params['visible'])) {
                require_capability('moodle/course:activityvisibility', $context);
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
            // Moodle's page module stores only scalar display options in a serialized array.
            $displayoptions = unserialize_array($instancerecord->displayoptions);
            if ($displayoptions === false) {
                $displayoptions = [];
            }
            $moduleinfo->printintro = $displayoptions['printintro'] ?? 0;
            $moduleinfo->printlastmodified = $displayoptions['printlastmodified'] ?? 0;
            foreach (['popupwidth', 'popupheight'] as $option) {
                if (isset($displayoptions[$option])) {
                    $moduleinfo->{$option} = $displayoptions[$option];
                }
            }
            $moduleinfo->revision = $instancerecord->revision;

            if ($hascontentupdates) {
                update_moduleinfo($coursemodule, $moduleinfo, $course);
            }

            if (isset($params['visible'])) {
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

        throw new moodle_exception('error_invalid_action', 'tool_teachermcp', '', $action);
    }
}
