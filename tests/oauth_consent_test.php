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

/**
 * OAuth consent display and PKCE validation tests.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_teachermcp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/oauth_consent_provider_fixture.php');

use tool_teachermcp\local\tool_registry;

/**
 * OAuth consent display and exact PKCE validation regressions.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class oauth_consent_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        foreach (['enable_write_tools', 'enable_grade_tools', 'enable_messaging_tool', 'enable_delete_tool'] as $setting) {
            set_config($setting, 1, 'tool_teachermcp');
        }
        tool_registry::set_provider_callback_map_for_testing([]);
    }

    protected function tearDown(): void {
        tool_registry::set_provider_callback_map_for_testing(null);
        parent::tearDown();
    }

    public function test_consent_permissions_follow_requested_native_grants(): void {
        foreach (
            ['read' => ['read'], 'read grade' => ['read', 'grade'],
                'write message' => ['write', 'message']] as $scope => $expected
        ) {
            $config = oauth_server::normalise_scope($scope);
            $before = $config['permissions'];
            $rows = oauth_server::get_consent_permissions($config['permissions']);
            $this->assertSame($expected, array_column($rows, 'id'));
            $this->assertSame($before, $config['permissions']);
            foreach ($rows as $row) {
                $this->assertNotEmpty($row['description']);
                $this->assertStringNotContainsString('[[', $row['description']);
            }
        }
    }

    public function test_consent_respects_admin_gates_without_changing_issued_grants(): void {
        $full = oauth_server::normalise_scope('teachermcp');
        $this->assertSame(
            ['read', 'write', 'grade', 'message', 'delete'],
            array_column(oauth_server::get_consent_permissions($full['permissions']), 'id')
        );
        foreach (['enable_write_tools', 'enable_grade_tools', 'enable_messaging_tool', 'enable_delete_tool'] as $setting) {
            set_config($setting, 0, 'tool_teachermcp');
        }
        $this->assertSame(['read'], array_column(oauth_server::get_consent_permissions($full['permissions']), 'id'));
        $this->assertSame(['all_teacher_tools' => true], $full['permissions']);
        $write = oauth_server::normalise_scope('write');
        $this->assertSame([], oauth_server::get_consent_permissions($write['permissions']));
        $this->assertSame(['scopes' => ['write']], $write['permissions']);
    }

    public function test_external_consent_grants_do_not_imply_a_native_scope(): void {
        set_config('enable_external_tools', 1, 'tool_teachermcp');
        set_config('enable_consent_probe', 1, 'tool_teachermcp');
        tool_registry::set_provider_callback_map_for_testing([
            'tool_teachermcp' => [oauth_consent_provider_fixture::class, 'provider'],
        ]);
        $name = 'tool_teachermcp.consent_probe';
        $external = oauth_server::normalise_scope($name);
        $this->assertSame([$name], array_column(oauth_server::get_consent_permissions($external['permissions']), 'id'));
        $this->assertSame([$name => true], $external['permissions']);
        $mixed = oauth_server::normalise_scope('read ' . $name);
        $this->assertSame(['read', $name], array_column(oauth_server::get_consent_permissions($mixed['permissions']), 'id'));
        $full = oauth_server::normalise_scope('teachermcp');
        $this->assertNotContains($name, array_column(oauth_server::get_consent_permissions($full['permissions']), 'id'));
        set_config('enable_consent_probe', 0, 'tool_teachermcp');
        $this->assertSame([], oauth_server::get_consent_permissions($external['permissions']));
        $this->assertSame([$name => true], $external['permissions']);
    }

    public function test_pkce_method_validation_remains_exact_s256(): void {
        set_config('oauth_registration_enabled', 1, 'tool_teachermcp');
        $registered = oauth_server::register_client('Exact PKCE fixture', ['http://127.0.0.1/callback'], '127.0.0.1');
        $challenge = 'E9Melhoa2OwvFrGMTJguCH50lK6Zw1F3-EV38YZ2_50';
        $client = oauth_server::validate_authorization_params(
            $registered['client_id'],
            'http://127.0.0.1:49152/callback',
            'code',
            $challenge,
            'S256'
        );
        $this->assertSame($registered['client_id'], $client->clientid);
        foreach (['plain', 'S', 's256', 'S256 ', ' S256', 'S_256', 'S2-56', 'S256<script>'] as $method) {
            try {
                oauth_server::validate_authorization_params(
                    $registered['client_id'],
                    'http://127.0.0.1:49152/callback',
                    'code',
                    $challenge,
                    $method
                );
                $this->fail('Only the exact S256 method may pass PKCE validation');
            } catch (\moodle_exception $error) {
                $this->assertSame('error_invalid_request', $error->errorcode);
            }
        }
    }
}
