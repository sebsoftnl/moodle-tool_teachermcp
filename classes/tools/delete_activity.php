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
 * MCP tool to permanently delete a course activity/module (dangerous action).
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_activity extends base_tool {

    public function get_name(): string {
        return 'delete_activity';
    }

    public function get_description(): string {
        return '[DANGEROUS ACTION] Permanently deletes a course activity or module (e.g. quiz, assignment, page, book, h5p, forum) along with all associated student data, grades, and submissions. Safety protocol: When delete_requires_ui_confirmation is enabled, returns a UI confirmation link. When disabled, requires a valid 10-minute HMAC confirmation token generated via a preview call.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'The course module ID (cmid) of the activity to delete.',
                ],
                'confirm_token' => [
                    'type' => 'string',
                    'description' => 'Cryptographic confirmation token returned by the preview step. Required to execute deletion when UI confirmation is disabled.',
                ],
            ],
            'required' => ['cmid'],
        ];
    }

    public function execute(array $params) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/lib.php');

        // Check if delete tool is globally enabled.
        $enabledelete = get_config('tool_teachermcp', 'enable_delete_tool');
        if ($enabledelete !== false && $enabledelete !== null && empty($enabledelete)) {
            throw new moodle_exception('error_tool_disabled', 'tool_teachermcp', '', 'delete_activity');
        }

        $cmid = (int)($params['cmid'] ?? 0);
        if ($cmid <= 0) {
            throw new moodle_exception('invalidcmid', 'error');
        }

        // N3: Enforce course module access and moodle/course:manageactivities capability.
        list($cm, $course, $context) = $this->require_cm_access($cmid, 'moodle/course:manageactivities');

        $modulerec = $DB->get_record('modules', ['id' => $cm->module], '*', MUST_EXIST);
        $modname = $modulerec->name;
        $instance = $DB->get_record($modname, ['id' => $cm->instance]);
        $activitytitle = $instance->name ?? ($cm->name ?? "{$modname} #{$cm->instance}");

        // N3: Check recycle bin status.
        $recyclebinenabled = class_exists('\tool_recyclebin\course_bin') && \tool_recyclebin\course_bin::is_enabled();

        // Generate 10-minute HMAC-SHA256 confirmation token bound to user, cm, course, time, and site identifier.
        $timestamp = time();
        $tokendata = "delete_activity:{$USER->id}:{$cm->id}:{$course->id}:{$timestamp}";
        $hash = hash_hmac('sha256', $tokendata, get_site_identifier());
        $confirmtoken = base64_encode("{$timestamp}:{$hash}");

        // Admin setting: delete_requires_ui_confirmation (default 1 / true).
        $requiresuiconfig = get_config('tool_teachermcp', 'delete_requires_ui_confirmation');
        $requiresui = ($requiresuiconfig === false || $requiresuiconfig === null) ? 1 : (int)$requiresuiconfig;

        $confirmurl = (new \moodle_url('/admin/tool/teachermcp/confirm_delete.php', ['cmid' => (int)$cm->id]))->out(false);

        if ($requiresui) {
            // Refuse automated deletion and require teacher manual UI confirmation.
            return [
                'success' => false,
                'status' => 'ui_confirmation_required',
                'requires_ui_confirmation' => true,
                'confirmation_url' => $confirmurl,
                'message' => get_string('delete_requires_ui_confirmation_desc', 'tool_teachermcp'),
                'activity' => [
                    'cmid' => (int)$cm->id,
                    'name' => $activitytitle,
                    'modname' => $modname,
                    'course_id' => (int)$course->id,
                    'course_name' => $course->fullname,
                    'warning' => "Deleting '{$activitytitle}' (type: {$modname}, cmid: {$cm->id}) is IRREVERSIBLE and will permanently remove all associated student submissions, grades, and logs.",
                    'recycle_bin_enabled' => $recyclebinenabled,
                ],
                'confirm_token' => $confirmtoken,
            ];
        }

        // When UI confirmation is not required, enforce 2-step cryptographic token confirmation.
        $tokeninput = trim((string)($params['confirm_token'] ?? ''));

        if (empty($tokeninput)) {
            // Step 1: Return preview challenge with confirmation token.
            return [
                'success' => false,
                'status' => 'confirmation_required',
                'requires_confirmation' => true,
                'dangerous' => true,
                'confirm_token' => $confirmtoken,
                'activity' => [
                    'cmid' => (int)$cm->id,
                    'name' => $activitytitle,
                    'modname' => $modname,
                    'course_id' => (int)$course->id,
                    'course_name' => $course->fullname,
                    'warning' => "Deleting '{$activitytitle}' (type: {$modname}, cmid: {$cm->id}) is IRREVERSIBLE and will permanently remove all associated student submissions, grades, and logs.",
                    'recycle_bin_enabled' => $recyclebinenabled,
                ],
                'instructions' => "To permanently delete this activity, call delete_activity again with cmid: {$cm->id} and confirm_token: '{$confirmtoken}'. Token is valid for 10 minutes.",
            ];
        }

        // Step 2: Validate provided confirm_token.
        $decoded = base64_decode($tokeninput, true);
        if (!$decoded || strpos($decoded, ':') === false) {
            throw new moodle_exception('error_invalid_confirmation_token', 'tool_teachermcp');
        }

        list($tokentime, $tokenhash) = explode(':', $decoded, 2);
        $tokentime = (int)$tokentime;

        // 10 minutes (600s) validity window, allowing 60s future clock drift.
        if (time() - $tokentime > 600 || $tokentime > time() + 60) {
            throw new moodle_exception('error_confirmation_token_expired', 'tool_teachermcp');
        }

        $expecteddata = "delete_activity:{$USER->id}:{$cm->id}:{$course->id}:{$tokentime}";
        $expectedhash = hash_hmac('sha256', $expecteddata, get_site_identifier());
        if (!hash_equals($expectedhash, $tokenhash)) {
            throw new moodle_exception('error_invalid_confirmation_token', 'tool_teachermcp');
        }

        // Replay attack prevention via cache flag.
        if (get_cache_flag('tool_teachermcp_deltoken', $tokenhash, time())) {
            throw new moodle_exception('error_confirmation_token_expired', 'tool_teachermcp');
        }
        set_cache_flag('tool_teachermcp_deltoken', $tokenhash, 1, time() + 1200);

        // Execute irreversible deletion through core Moodle API.
        course_delete_module($cm->id);
        rebuild_course_cache($course->id, true);

        return [
            'success' => true,
            'status' => 'deleted',
            'cmid' => (int)$cm->id,
            'name' => $activitytitle,
            'modname' => $modname,
            'course_id' => (int)$course->id,
            'recycle_bin_enabled' => $recyclebinenabled,
            'message' => "Activity '{$activitytitle}' (cmid: {$cm->id}) has been permanently deleted from course '{$course->fullname}'.",
        ];
    }
}
