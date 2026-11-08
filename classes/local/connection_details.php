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

namespace tool_teachermcp\local;

use tool_teachermcp\server;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared teacher and administrator presentation of existing token grants.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_details {
    /**
     * Return the connection or dashboard data for the current user.
     *
     * @param \stdClass $token token value.
     * @return array Result of the operation.
     */
    public static function get(\stdClass $token): array {
        $groups = [];
        $external = [];
        foreach (server::get_all_tools() as $name => $tool) {
            $allowed = server::is_tool_allowed_by_token($name, $token);
            if (server::is_external_tool($name)) {
                if ($allowed) {
                    $external[] = ['id' => $name, 'name' => $name, 'description' => $tool->get_description()];
                }
                continue;
            }
            $scope = server::get_tool_scope($name);
            $groups[$scope]['total'] = ($groups[$scope]['total'] ?? 0) + 1;
            if ($allowed) {
                $groups[$scope]['allowed'][] = [
                    'id' => $name, 'name' => $name, 'description' => $tool->get_description(),
                ];
            }
        }

        $permissions = [];
        foreach (['read', 'write', 'grade', 'message', 'delete'] as $scope) {
            $allowed = $groups[$scope]['allowed'] ?? [];
            if (!$allowed) {
                continue;
            }
            if (count($allowed) === $groups[$scope]['total']) {
                $permissions[] = [
                    'id' => $scope, 'name' => '', 'description' => get_string('oauth_perm_' . $scope, 'tool_teachermcp'),
                ];
            } else {
                $permissions = array_merge($permissions, $allowed);
            }
        }

        return [
            'validuntil' => $token->timeexpires ? userdate($token->timeexpires) : get_string('never', 'tool_teachermcp'),
            'permissions' => array_merge($permissions, $external),
        ];
    }
}
