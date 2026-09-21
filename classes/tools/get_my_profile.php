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

/**
 * MCP tool to retrieve teacher's own profile and system information.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_my_profile extends base_tool {

    public function get_name(): string {
        return 'get_my_profile';
    }

    public function get_description(): string {
        return 'Retrieve details of the currently authenticated teacher: name, email, roles, and Moodle environment.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $params) {
        global $USER, $CFG, $DB;

        $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);

        // Get site roles.
        $roles = get_user_roles(\context_system::instance(), $USER->id);
        $rolelabels = [];
        foreach ($roles as $r) {
            $rolelabels[] = $r->shortname;
        }

        return [
            'userid' => (int)$user->id,
            'username' => $user->username,
            'fullname' => fullname($user),
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'lang' => $user->lang ?: $CFG->lang,
            'timezone' => $user->timezone ?: $CFG->timezone,
            'city' => $user->city,
            'country' => $user->country,
            'site_roles' => $rolelabels,
            'moodle_release' => $CFG->release,
            'wwwroot' => $CFG->wwwroot,
        ];
    }
}
