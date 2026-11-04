<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

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
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function get_name(): string {
        return get_string('event_token_history_cleared', 'tool_teachermcp');
    }

    public function get_description(): string {
        $scope = $this->other['allusers'] ? 'site-wide' : 'their own';
        return "The user with id '{$this->userid}' cleared {$this->other['count']} inactive connections from {$scope} history.";
    }
}
