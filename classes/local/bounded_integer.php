<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Native administrator input whose saved values match the supported integer range.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bounded_integer extends \admin_setting_configtext {
    public function __construct($name, $title, $description, $default, private int $minimum, private ?int $maximum = null) {
        parent::__construct($name, $title, $description, $default, PARAM_INT);
    }

    public function validate($data) {
        $valid = parent::validate($data);
        if ($valid !== true) {
            return $valid;
        }
        if ((int)$data < $this->minimum || ($this->maximum !== null && (int)$data > $this->maximum)) {
            if ($this->maximum === null) {
                return get_string('setting_integer_minimum', 'tool_teachermcp', $this->minimum);
            }
            return get_string('setting_integer_range', 'tool_teachermcp',
                (object)['minimum' => $this->minimum, 'maximum' => $this->maximum]);
        }
        return true;
    }

    public function output_html($data, $query = '') {
        $attributes = [
            'type' => 'number', 'name' => $this->get_full_name(), 'id' => $this->get_id(), 'value' => $data,
            'min' => $this->minimum, 'step' => 1, 'inputmode' => 'numeric', 'required' => 'required',
            'class' => 'form-control tool_teachermcp_integer',
        ];
        if ($this->maximum !== null) {
            $attributes['max'] = $this->maximum;
        }
        if ($this->is_readonly()) {
            $attributes['disabled'] = 'disabled';
        }
        $element = \html_writer::div(\html_writer::empty_tag('input', $attributes), 'form-text defaultsnext');
        return format_admin_setting($this, $this->visiblename, $element, $this->description,
            true, '', $this->get_defaultsetting(), $query);
    }
}
