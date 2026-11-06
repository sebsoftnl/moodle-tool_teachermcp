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

use tool_teachermcp\tools\base_tool;

/**
 * Adapts a validated external provider tool to the internal MCP tool contract.
 *
 * Provider declaration capability metadata is descriptive and is not an access
 * check. Each provider must derive its target resource context and call
 * require_capability() itself before reading or changing Moodle data.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_tool_adapter extends base_tool {
    /**
     * Create an adapter for one validated provider tool.
     *
     * @param provider_tool_declaration $declaration Validated declaration.
     * @param external_tool $tool Provider implementation.
     * @param string $description Validated, stable tool description.
     * @param array $schema Validated, stable input schema.
     */
    public function __construct(
        /** @var provider_tool_declaration Validated provider declaration. */
        private readonly provider_tool_declaration $declaration,
        /** @var external_tool Provider implementation. */
        private readonly external_tool $tool,
        /** @var string Tool description. */
        private readonly string $description,
        /** @var array Tool input schema. */
        private readonly array $schema,
    ) {
        parent::__construct();
    }

    /**
     * Return the validated tool contract value.
     */
    public function get_name(): string {
        return $this->declaration->get_name();
    }

    /**
     * Return the validated tool contract value.
     */
    public function get_description(): string {
        return $this->description;
    }

    /**
     * Return the validated tool contract value.
     */
    public function get_input_schema(): array {
        return $this->schema;
    }

    /**
     * Return the validated tool contract value.
     */
    public function execute(array $params): mixed {
        global $USER;

        // Do not derive context from untrusted arguments and never expose token,
        // headers, or the raw JSON-RPC payload to the provider.
        return $this->tool->execute($params, new execution_context((int)$USER->id));
    }

    /**
     * Return the validated external declaration for internal registry metadata.
     *
     * @return provider_tool_declaration
     */
    public function get_declaration(): provider_tool_declaration {
        return $this->declaration;
    }
}
