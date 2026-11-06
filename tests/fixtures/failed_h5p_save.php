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

/**
 * Test-only failed_h5p_save fixture class.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

/**
 * H5P activity subclass that simulates an unsuccessful save.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class failed_h5p_save extends \tool_teachermcp\tools\create_h5p_activity {
    /** @var bool Whether the injected save returns null instead of false. */
    public bool $returnnull = false;
    /** @var array|null Native write state recorded before returning failure. */
    public ?array $nativewrite = null;

    /**
     * Save natively and then return an injected failure result.
     *
     * @param \core_h5p\factory $factory H5P factory.
     * @param \stored_file $file H5P package.
     * @return int|false|null Injected result.
     */
    protected function save_activity_content(\core_h5p\factory $factory, \stored_file $file): int|false|null {
        global $DB;
        $id = parent::save_activity_content($factory, $file);
        if (!$id) {
            throw new \RuntimeException('Native H5P positive save must complete before the injected result');
        }
        $context = \context::instance_by_id($file->get_contextid());
        $cm = $DB->get_record('course_modules', ['id' => $context->instanceid], '*', MUST_EXIST);
        $this->nativewrite = ['h5p_id' => $id, 'cmid' => (int)$cm->id, 'instance_id' => (int)$cm->instance,
            'h5p_exists' => $DB->record_exists('h5p', ['id' => $id]),
            'instance_exists' => $DB->record_exists('h5pactivity', ['id' => $cm->instance]),
            'file_exists' => $DB->record_exists('files', ['id' => $file->get_id()])];
        return $this->returnnull ? null : false;
    }
}
