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

namespace tool_teachermcp\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled cleanup task for Teacher MCP.
 *
 * Purges expired OAuth authorization codes and updates expired agent bearer tokens.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_task extends \core\task\scheduled_task {

    /**
     * Get task human-readable name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup', 'tool_teachermcp');
    }

    /**
     * Execute cleanup of transient and expired MCP authentication data.
     */
    public function execute() {
        global $DB;

        $now = time();

        // 1. Delete expired OAuth authorization codes.
        $expiredcodecount = $DB->count_records_select(
            'tool_teachermcp_oauth_codes',
            'timeexpires > 0 AND timeexpires < :now',
            ['now' => $now]
        );
        if ($expiredcodecount > 0) {
            $DB->delete_records_select(
                'tool_teachermcp_oauth_codes',
                'timeexpires > 0 AND timeexpires < :now',
                ['now' => $now]
            );
            mtrace("Teacher MCP: Purged {$expiredcodecount} expired OAuth authorization code(s).");
        }

        // 2. Delete used or revoked authorization codes older than 24 hours.
        $usedcutoff = $now - (24 * 3600);
        $usedcodecount = $DB->count_records_select(
            'tool_teachermcp_oauth_codes',
            'status != 0 AND timecreated < :cutoff',
            ['cutoff' => $usedcutoff]
        );
        if ($usedcodecount > 0) {
            $DB->delete_records_select(
                'tool_teachermcp_oauth_codes',
                'status != 0 AND timecreated < :cutoff',
                ['cutoff' => $usedcutoff]
            );
            mtrace("Teacher MCP: Purged {$usedcodecount} old used/revoked OAuth code(s).");
        }

        // 3. Mark expired active tokens as revoked (status = 0).
        $expiredtokencount = $DB->count_records_select(
            'tool_teachermcp_tokens',
            'status = 1 AND timeexpires > 0 AND timeexpires < :now',
            ['now' => $now]
        );
        if ($expiredtokencount > 0) {
            $DB->execute(
                "UPDATE {tool_teachermcp_tokens}
                    SET status = 0, timemodified = :now
                  WHERE status = 1 AND timeexpires > 0 AND timeexpires < :exp",
                ['now' => $now, 'exp' => $now]
            );
            mtrace("Teacher MCP: Marked {$expiredtokencount} expired agent token(s) as revoked.");
        }

        mtrace("Teacher MCP: Cleanup task completed successfully.");
    }
}
