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

use tool_teachermcp\local\execution_context;
use tool_teachermcp\local\external_tool;

/**
 * Valid implementation used to prove the public provider contract.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_discovery_fake_tool implements external_tool {
    /**
     * Return the fixture description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'A fake provider tool.';
    }

    /**
     * Return the fixture schema.
     *
     * @return array
     */
    public function get_input_schema(): array {
        return ['type' => 'object'];
    }

    /**
     * Execute the fixture tool.
     *
     * @return array
     */
    public function execute(array $arguments, execution_context $context): array {
        return ['userid' => $context->get_userid(), 'arguments' => $arguments];
    }
}
