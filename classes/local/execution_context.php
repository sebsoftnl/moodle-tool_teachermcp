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

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Immutable, data-minimised context passed to external Teacher MCP tools.
 *
 * Deliberately contains no bearer token, raw request headers, raw payload, or
 * full token record. Provider tools must derive and enforce resource access.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class execution_context {
    /**
     * Create a safe execution context.
     *
     * @param int $userid Acting Moodle user ID.
     * @param int|null $courseid Optional explicitly established course ID.
     * @param int|null $cmid Optional explicitly established course module ID.
     */
    public function __construct(
        private readonly int $userid,
        private readonly ?int $courseid = null,
        private readonly ?int $cmid = null,
    ) {
    }

    /**
     * Return the acting Moodle user ID.
     *
     * @return int
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * Return the established course ID, if one exists.
     *
     * @return int|null
     */
    public function get_courseid(): ?int {
        return $this->courseid;
    }

    /**
     * Return the established course module ID, if one exists.
     *
     * @return int|null
     */
    public function get_cmid(): ?int {
        return $this->cmid;
    }
}
