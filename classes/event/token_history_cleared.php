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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_teachermcp\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Records history cleanup without removing the original connection audit events.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_history_cleared extends \core\event\base {
    /**
     * Initialize the event metadata.
     *
     */
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return the registered MCP tool name.
     *
     * @return string Result of the operation.
     */
    public static function get_name(): string {
        return get_string('event_token_history_cleared', 'tool_teachermcp');
    }

    /**
     * Describe the recorded event.
     *
     * @return string Result of the operation.
     */
    public function get_description(): string {
        $scope = $this->other['allusers'] ? 'site-wide' : 'their own';
        return "The user with id '{$this->userid}' cleared {$this->other['count']} inactive connections from {$scope} history.";
    }
}
