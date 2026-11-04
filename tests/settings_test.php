<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

/**
 * Administrator settings boundaries and placement.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_test extends \advanced_testcase {
    public function test_limits_reject_invalid_values_without_saving(): void {
        $settings = $this->settings();
        foreach ([
            'max_token_validity' => [90, ['0', '-1', '', '1.5']],
            'max_oauth_tokens_per_client' => [5, ['0', '-1', 'abc']],
            'message_ratelimit_per_hour' => [50, ['0', '-1']],
            'max_mcp_request_bytes' => [1048576, ['0', '1023', '10485761']],
            'max_mcp_batch_size' => [10, ['0', '-1', '51', '999999']],
            'max_mcp_response_bytes' => [262144, ['0', '1023', '5242881']],
            'mcp_requests_per_minute' => [60, ['0', '-1', '601']],
        ] as $name => [$default, $invalid]) {
            set_config($name, $default, 'tool_teachermcp');
            $this->assertEquals($default, $settings[$name]->get_defaultsetting());
            foreach ($invalid as $value) {
                $this->assertNotSame('', $settings[$name]->write_setting($value), "$name must reject '$value'");
                $this->assertEquals($default, get_config('tool_teachermcp', $name));
            }
        }
    }

    public function test_supported_boundaries_are_saved(): void {
        $settings = $this->settings();
        foreach ([
            'max_token_validity' => [1, 365],
            'max_oauth_tokens_per_client' => [1, 20],
            'message_ratelimit_per_hour' => [1, 100],
            'max_mcp_request_bytes' => [1024, 10485760],
            'max_mcp_batch_size' => [1, 50],
            'max_mcp_response_bytes' => [1024, 5242880],
            'mcp_requests_per_minute' => [1, 600],
        ] as $name => $values) {
            foreach ($values as $value) {
                $this->assertSame('', $settings[$name]->write_setting((string)$value));
                $this->assertEquals($value, get_config('tool_teachermcp', $name));
            }
        }
    }

    public function test_technical_settings_are_only_on_the_advanced_page(): void {
        $this->settings();
        $root = admin_get_root();
        $main = $root->locate('tool_teachermcp');
        $advanced = $root->locate('tool_teachermcp_advanced');
        $this->assertInstanceOf(\admin_settingpage::class, $advanced, 'Technical controls need a separate Advanced page');
        $mainnames = array_map(static fn($setting) => $setting->name, (array)$main->settings);
        $advancednames = array_map(static fn($setting) => $setting->name, (array)$advanced->settings);
        foreach (['message_ratelimit_per_hour', 'max_mcp_request_bytes', 'max_mcp_batch_size',
                'max_mcp_response_bytes', 'mcp_requests_per_minute', 'allowed_cors_origins', 'enable_external_tools'] as $name) {
            $this->assertNotContains($name, $mainnames);
            $this->assertContains($name, $advancednames);
        }
        foreach (['enable_write_tools', 'enable_grade_tools', 'enable_messaging_tool', 'enable_delete_tool',
                'allow_delete_questions', 'delete_requires_ui_confirmation', 'oauth_registration_enabled',
                'max_token_validity', 'max_oauth_tokens_per_client'] as $name) {
            $this->assertContains($name, $mainnames);
        }
    }

    private function settings(): array {
        global $CFG;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');
        $root = admin_get_root(true, true);
        $settings = [];
        foreach (['tool_teachermcp', 'tool_teachermcp_advanced'] as $pageid) {
            $page = $root->locate($pageid);
            if ($page) {
                foreach ($page->settings as $setting) {
                    $settings[$setting->name] = $setting;
                }
            }
        }
        return $settings;
    }
}
