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

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers for tool_teachermcp.
 *
 * Handles cache invalidation for user capabilities and permissions (B12).
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Invalidate user_connect_capability cache when a role is assigned.
     *
     * @param \core\event\role_assigned $event
     */
    public static function role_assigned(\core\event\role_assigned $event): void {
        $userid = !empty($event->relateduserid) ? (int)$event->relateduserid : null;
        auth::invalidate_user_connect_cache($userid);
    }

    /**
     * Invalidate user_connect_capability cache when a role is unassigned.
     *
     * @param \core\event\role_unassigned $event
     */
    public static function role_unassigned(\core\event\role_unassigned $event): void {
        $userid = !empty($event->relateduserid) ? (int)$event->relateduserid : null;
        auth::invalidate_user_connect_cache($userid);
    }

    /**
     * Invalidate user_connect_capability cache when a capability is assigned or changed.
     *
     * @param \core\event\capability_assigned $event
     */
    public static function capability_assigned(\core\event\capability_assigned $event): void {
        auth::invalidate_user_connect_cache(null);
    }
}
