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
 * Validated declaration for one externally provided Teacher MCP tool.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_tool_declaration {
    /**
     * Create a validated declaration.
     *
     * @param string $component Frankenstyle component declaring the tool.
     * @param string $id Local tool ID.
     * @param string $classname Provider implementation class name.
     * @param string $scope Tool scope.
     * @param string $capability Moodle capability that the provider must enforce.
     * @param string $enabledsetting Required provider-owned, default-off setting name.
     */
    public function __construct(
        /** @var string Frankenstyle component. */
        private readonly string $component,
        /** @var string Tool ID. */
        private readonly string $id,
        /** @var string Provider class name. */
        private readonly string $classname,
        /** @var string Tool scope. */
        private readonly string $scope,
        /** @var string Required capability. */
        private readonly string $capability,
        /** @var string Provider setting name. */
        private readonly string $enabledsetting,
    ) {
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_name(): string {
        return $this->component . '.' . $this->id;
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_component(): string {
        return $this->component;
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_classname(): string {
        return $this->classname;
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_scope(): string {
        return $this->scope;
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_capability(): string {
        return $this->capability;
    }

    /**
     * Return the validated declaration value.
     *
     * @return string
     */
    public function get_enabled_setting(): string {
        return $this->enabledsetting;
    }
}
