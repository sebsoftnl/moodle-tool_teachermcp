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
 * Event triggered when an agent token is revoked.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_revoked extends \core\event\base {

    /**
     * Init method.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'tool_teachermcp_tokens';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_token_revoked', 'tool_teachermcp');
    }

    /**
     * Description of event.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' revoked the agent token with id '{$this->objectid}' for user '{$this->relateduserid}'.";
    }
}
