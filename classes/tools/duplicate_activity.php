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

use core_courseformat\formatactions;
use core_courseformat\local\cmactions;
use moodle_exception;
use stdClass;

/**
 * MCP tool to duplicate an activity within a course or to another course.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duplicate_activity extends base_tool {
    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public function get_name(): string {
        return 'duplicate_activity';
    }

    /**
     * Return the MCP tool description.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        return 'Duplicate an existing activity or resource within the same course or into another course where teacher has editing rights.';
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
                    'description' => 'Course module ID of the source activity to duplicate.',
                ],
                'target_course_id' => [
                    'type' => 'integer',
                    'description' => 'Target course ID (optional; if omitted, duplicates in the source course).',
                ],
                'section_number' => [
                    'type' => 'integer',
                    'description' => 'Target section number (optional; defaults to same section).',
                ],
            ],
            'required' => ['cmid'],
        ];
    }

    /**
     * Execute the tool using the validated arguments.
     *
     * @param array $params params value.
     */
    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $cmid = (int)($params['cmid'] ?? 0);
        $targetcourseid = (int)($params['target_course_id'] ?? 0);
        $sectionnum = isset($params['section_number']) ? (int)$params['section_number'] : null;

        [$cm, $sourcecourse, $sourcecontext] = $this->require_cm_access($cmid, 'moodle/course:manageactivities');

        if ($targetcourseid > 0 && $targetcourseid !== (int)$sourcecourse->id) {
            $targetcourse = $this->require_course_access($targetcourseid, 'moodle/course:manageactivities');
        } else {
            $targetcourse = $sourcecourse;
        }

        $targetcontext = \context_course::instance($targetcourse->id);
        require_capability('moodle/backup:backupactivity', $sourcecontext);
        require_capability('moodle/restore:restoreactivity', $targetcontext);

        // Check if plugin supports backup.
        if (!plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2)) {
            throw new moodle_exception(
                'duplicatenosupport',
                'error',
                '',
                null,
                "Module '{$cm->modname}' does not support duplication."
            );
        }

        if ($sectionnum !== null) {
            $targetsections = get_fast_modinfo($targetcourse)->get_section_info_all();
            if (!isset($targetsections[$sectionnum])) {
                throw new moodle_exception('sectionnotexist', 'error');
            }
        }

        if ($targetcourse->id === $sourcecourse->id && $sectionnum === null) {
            if (method_exists(cmactions::class, 'duplicate')) {
                $newcm = formatactions::cm($sourcecourse)->duplicate((int)$cm->id);
            } else {
                $newcm = duplicate_module($sourcecourse, $cm);
            }
            return [
                'success' => true,
                'message' => "Activity '{$cm->name}' successfully duplicated.",
                'source_cmid' => $cmid,
                'new_cmid' => (int)$newcm->id,
                'new_name' => $newcm->name,
                'course_id' => (int)$sourcecourse->id,
            ];
        }

        // Cross-course or custom section duplication via backup/restore controller.
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );

        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $rc = new \restore_controller(
            $backupid,
            $targetcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );

        $rc->execute_precheck();
        $rc->execute_plan();

        // Find newly restored module.
        $tasks = $rc->get_plan()->get_tasks();
        $newcmid = 0;
        foreach ($tasks as $task) {
            if ($task instanceof \restore_activity_task) {
                if ($task->get_old_moduleid() == $cm->id) {
                    $newcmid = $task->get_moduleid();
                    break;
                }
            }
        }

        $rc->destroy();

        // Move to requested section if specified.
        if ($newcmid && $sectionnum !== null) {
            $targetmodinfo = get_fast_modinfo($targetcourse);
            $targetsection = $targetmodinfo->get_section_info($sectionnum, MUST_EXIST);
            if (method_exists(cmactions::class, 'move_end_section')) {
                formatactions::cm($targetcourse)->move_end_section((int)$newcmid, (int)$targetsection->id);
            } else {
                moveto_module($targetmodinfo->cms[$newcmid], $targetsection);
            }
        }

        rebuild_course_cache($targetcourse->id, true);

        return [
            'success' => true,
            'message' => "Activity '{$cm->name}' successfully copied to course ID {$targetcourse->id}.",
            'source_cmid' => $cmid,
            'new_cmid' => (int)$newcmid,
            'source_course_id' => (int)$sourcecourse->id,
            'target_course_id' => (int)$targetcourse->id,
        ];
    }
}
