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

namespace tool_teachermcp\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_user;

/**
 * Privacy API implementation for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the metadata describing personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata items to.
     * @return collection The populated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'tool_teachermcp_tokens',
            [
                'userid' => 'privacy:metadata:tool_teachermcp_tokens:userid',
                'name' => 'privacy:metadata:tool_teachermcp_tokens:name',
                'clientid' => 'privacy:metadata:tool_teachermcp_tokens:clientid',
                'tokenlastchars' => 'privacy:metadata:tool_teachermcp_tokens:tokenlastchars',
                'lastip' => 'privacy:metadata:tool_teachermcp_tokens:lastip',
                'lastaccess' => 'privacy:metadata:tool_teachermcp_tokens:lastaccess',
                'timeexpires' => 'privacy:metadata:tool_teachermcp_tokens:timeexpires',
                'timecreated' => 'privacy:metadata:tool_teachermcp_tokens:timecreated',
                'timemodified' => 'privacy:metadata:tool_teachermcp_tokens:timemodified',
                'status' => 'privacy:metadata:tool_teachermcp_tokens:status',
            ],
            'privacy:metadata:tool_teachermcp_tokens'
        );

        $collection->add_database_table(
            'tool_teachermcp_oauth_codes',
            [
                'clientid' => 'privacy:metadata:tool_teachermcp_oauth_codes:clientid',
                'userid' => 'privacy:metadata:tool_teachermcp_oauth_codes:userid',
                'redirecturi' => 'privacy:metadata:tool_teachermcp_oauth_codes:redirecturi',
                'scope' => 'privacy:metadata:tool_teachermcp_oauth_codes:scope',
                'timecreated' => 'privacy:metadata:tool_teachermcp_oauth_codes:timecreated',
                'timeexpires' => 'privacy:metadata:tool_teachermcp_oauth_codes:timeexpires',
            ],
            'privacy:metadata:tool_teachermcp_oauth_codes'
        );

        $collection->add_database_table(
            'tool_teachermcp_assets',
            [
                'userid' => 'privacy:metadata:tool_teachermcp_assets:userid',
                'contextid' => 'privacy:metadata:tool_teachermcp_assets:contextid',
                'fileid' => 'privacy:metadata:tool_teachermcp_assets:fileid',
                'timecreated' => 'privacy:metadata:tool_teachermcp_assets:timecreated',
            ],
            'privacy:metadata:tool_teachermcp_assets'
        );
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:course_assets');

        return $collection;
    }

    /**
     * Get contexts that contain this user's data.
     *
     * @param int $userid Moodle user ID.
     * @return contextlist Contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();

        if ($DB->record_exists('tool_teachermcp_tokens', ['userid' => $userid]) ||
            $DB->record_exists('tool_teachermcp_oauth_codes', ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        $contextlist->add_from_sql(
            'SELECT DISTINCT contextid FROM {tool_teachermcp_assets} WHERE userid = :userid',
            ['userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Add users that have plugin data in this context.
     *
     * @param userlist $userlist The user list to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof context_user) {
            $sql = "SELECT userid FROM {tool_teachermcp_tokens} WHERE userid = :uid1
                    UNION
                    SELECT userid FROM {tool_teachermcp_oauth_codes} WHERE userid = :uid2";
            $userlist->add_from_sql('userid', $sql, ['uid1' => $context->instanceid, 'uid2' => $context->instanceid]);
            return;
        }

        $userlist->add_from_sql(
            'userid',
            'SELECT DISTINCT userid FROM {tool_teachermcp_assets} WHERE contextid = :contextid',
            ['contextid' => $context->id]
        );
    }

    /**
     * Export approved user data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        $userid = (int)$user->id;
        foreach ($contextlist as $context) {
            if ($context instanceof context_user && $context->instanceid == $userid) {
                self::export_user_records($context, $userid);
                continue;
            }

            $assets = $DB->get_records('tool_teachermcp_assets', ['userid' => $userid, 'contextid' => $context->id]);
            $fs = get_file_storage();
            foreach ($assets as $asset) {
                $file = $fs->get_file_by_id($asset->fileid);
                if ($file && !$file->is_directory()) {
                    writer::with_context($context)->export_file(
                        [get_string('pluginname', 'tool_teachermcp'), get_string('course_assets', 'tool_teachermcp')],
                        $file
                    );
                }
            }
        }
    }

    /**
     * Delete all plugin data in the context.
     *
     * @param context $context Context to clear.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if ($context instanceof context_user) {
            $userid = (int)$context->instanceid;
            $DB->delete_records('tool_teachermcp_tokens', ['userid' => $userid]);
            $DB->delete_records('tool_teachermcp_oauth_codes', ['userid' => $userid]);
            return;
        }
        self::delete_assets($context->id);
    }

    /**
     * Delete approved user data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context instanceof context_user && $context->instanceid == $userid) {
                $DB->delete_records('tool_teachermcp_tokens', ['userid' => $userid]);
                $DB->delete_records('tool_teachermcp_oauth_codes', ['userid' => $userid]);
            } else {
                self::delete_assets($context->id, $userid);
            }
        }
    }

    /**
     * Delete selected users' plugin data from a context.
     *
     * @param approved_userlist $userlist Approved user list.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            if ($context instanceof context_user && $context->instanceid == $userid) {
                $DB->delete_records('tool_teachermcp_tokens', ['userid' => $userid]);
                $DB->delete_records('tool_teachermcp_oauth_codes', ['userid' => $userid]);
            } else {
                self::delete_assets($context->id, (int)$userid);
            }
        }
    }

    /**
     * Export token and OAuth-code data held in a user's own context.
     *
     * @param context_user $context User context.
     * @param int $userid User ID.
     */
    private static function export_user_records(context_user $context, int $userid): void {
        global $DB;
        $tokens = $DB->get_records('tool_teachermcp_tokens', ['userid' => $userid]);
        if (!empty($tokens)) {
            $tokendata = [];
            foreach ($tokens as $token) {
                $tokendata[] = (object)[
                    'name' => $token->name,
                    'clientid' => $token->clientid,
                    'tokenlastchars' => $token->tokenlastchars,
                    'status' => $token->status ? get_string('active') : get_string('revoked', 'tool_teachermcp'),
                    'lastip' => $token->lastip,
                    'lastaccess' => $token->lastaccess ? transform::datetime($token->lastaccess) : '',
                    'timecreated' => transform::datetime($token->timecreated),
                    'timemodified' => transform::datetime($token->timemodified),
                    'timeexpires' => $token->timeexpires ? transform::datetime($token->timeexpires) : '',
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'tool_teachermcp'), get_string('tokens', 'tool_teachermcp')],
                (object)['tokens' => $tokendata]
            );
        }

        $codes = $DB->get_records('tool_teachermcp_oauth_codes', ['userid' => $userid]);
        if (!empty($codes)) {
            $codedata = [];
            foreach ($codes as $code) {
                $codedata[] = (object)[
                    'clientid' => $code->clientid,
                    'redirecturi' => $code->redirecturi,
                    'scope' => $code->scope,
                    'timecreated' => transform::datetime($code->timecreated),
                    'timeexpires' => transform::datetime($code->timeexpires),
                    'status' => $code->status,
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'tool_teachermcp'), get_string('oauth_codes', 'tool_teachermcp')],
                (object)['oauth_codes' => $codedata]
            );
        }
    }

    /**
     * Delete plugin-owned course assets and forget references to native activity files.
     *
     * Page and Book files belong to their activities once uploaded. Erasing the
     * uploader's account must not remove published teaching material.
     *
     * @param int $contextid Context ID.
     * @param int|null $userid Optional asset owner.
     */
    private static function delete_assets(int $contextid, ?int $userid = null): void {
        global $DB;
        $params = ['contextid' => $contextid];
        if ($userid !== null) {
            $params['userid'] = $userid;
        }
        $assets = $DB->get_records('tool_teachermcp_assets', $params);
        $fs = get_file_storage();
        foreach ($assets as $asset) {
            $file = $fs->get_file_by_id($asset->fileid);
            if ($file && $file->get_component() === 'tool_teachermcp' &&
                    $file->get_filearea() === 'course_assets') {
                $file->delete();
            }
        }
        $DB->delete_records('tool_teachermcp_assets', $params);
    }
}
