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

namespace tool_teachermcp\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when an MCP tool is executed by an agent.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_executed extends \core\event\base {

    /**
     * Init method.
     */
    protected function init(): void {
        $toolname = $this->data['other']['tool'] ?? '';
        $scope = \tool_teachermcp\server::get_tool_scope($toolname);
        if ($scope === 'delete') {
            $this->data['crud'] = 'd';
        } else if ($scope === 'read') {
            $this->data['crud'] = 'r';
        } else {
            $this->data['crud'] = 'u';
        }
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_tool_executed', 'tool_teachermcp');
    }

    /**
     * Description of event.
     *
     * @return string
     */
    public function get_description(): string {
        $toolname = $this->other['tool'] ?? 'unknown';
        $tokenid = $this->other['tokenid'] ?? 'none';
        $status = !empty($this->other['success']) ? 'successful' : 'failed';
        return "The user with id '{$this->userid}' executed MCP tool '{$toolname}' ({$status}) using token id '{$tokenid}'.";
    }
}
