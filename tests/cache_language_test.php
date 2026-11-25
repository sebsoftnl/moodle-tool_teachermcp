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

/**
 * Required cache definition language strings.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class cache_language_test extends \advanced_testcase {
    /**
     * Every declared cache has a nonempty name in each shipped language.
     */
    public function test_cache_definitions_have_language_strings(): void {
        $definitions = [];
        require(__DIR__ . '/../db/caches.php');
        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/tool_teachermcp.php');
            foreach (array_keys($definitions) as $name) {
                $key = 'cachedef_' . $name;
                $this->assertArrayHasKey($key, $string, $language . ': ' . $key);
                $this->assertNotSame('', trim($string[$key]), $language . ': ' . $key);
            }
        }
    }
}
