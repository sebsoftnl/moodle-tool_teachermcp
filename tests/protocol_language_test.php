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

namespace tool_teachermcp;

/**
 * Regression tests for translated human-readable protocol messages.
 *
 * @package tool_teachermcp
 * @copyright 2026 Sebsoft BV
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group tool_teachermcp
 * @covers \tool_teachermcp\server
 */
final class protocol_language_test extends \advanced_testcase {
    /**
     * Test encoding failures use Moodle's string manager.
     */
    public function test_encoding_failure_is_translatable(): void {
        $this->resetAfterTest();
        force_current_language('nl');
        $this->assertSame(
            get_string('mcp_encode_error', 'tool_teachermcp'),
            server::format_tool_result_for_response(["\xff"])
        );
    }

    /**
     * Test JSON-RPC identifiers remain stable while messages are translated.
     */
    public function test_rpc_errors_are_translatable(): void {
        $this->resetAfterTest();
        $server = new server();
        $response = $server->handle_payload('{');
        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(-32700, $response['error']['code']);
        $this->assertNull($response['id']);
        $this->assertSame(get_string('mcp_parse_error', 'tool_teachermcp'), $response['error']['message']);

        $response = $server->handle_payload('{"id":7,"method":"unknown/method"}');
        $this->assertSame(-32601, $response['error']['code']);
        $this->assertSame(7, $response['id']);
        $this->assertSame(
            get_string('mcp_method_not_found', 'tool_teachermcp', 'unknown/method'),
            $response['error']['message']
        );
    }

    /**
     * Test parameter names are data within translated validation messages.
     */
    public function test_schema_error_is_translatable(): void {
        $this->resetAfterTest();
        $response = server::validate_arguments_against_schema([], ['required' => ['course_id']]);
        $this->assertFalse($response['valid']);
        $this->assertSame(
            get_string('mcp_missing_parameter', 'tool_teachermcp', 'course_id'),
            $response['error']
        );
    }

    /**
     * OAuth descriptions have stricter character rules than JSON-RPC messages.
     *
     * RFC 6749 sections 4.1.2.1 and 5.2 forbid quotes, backslashes, controls and
     * non-ASCII characters even when JSON or URI encoding could represent them.
     * Check the shipped packs directly so an unavailable Dutch pack cannot silently
     * fall back to English and hide a translation regression.
     */
    public function test_shipped_oauth_descriptions_obey_rfc_character_constraints(): void {
        $keys = [
            'oauth_authorization_cancelled',
            'oauth_grant_type_required',
            'oauth_registration_post_only',
            'oauth_registration_server_error',
            'oauth_token_parameters_required',
            'oauth_token_post_only',
            'oauth_token_server_error',
            'error_invalid_client',
            'error_invalid_grant',
            'error_invalid_request',
            'error_unsupported_grant_type',
            'error_invalid_user',
            'error_no_capability',
            'error_registration_disabled',
            'error_rate_limited',
        ];
        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/tool_teachermcp.php');
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $string);
                $this->assertMatchesRegularExpression(
                    '/\\A[\\x20-\\x21\\x23-\\x5B\\x5D-\\x7E]*\\z/',
                    $string[$key],
                    $language . ': ' . $key
                );
            }
        }
    }

    /**
     * JSON-RPC permits Unicode; interpolation must remain JSON string data.
     */
    public function test_rpc_interpolation_survives_json_serialization(): void {
        $this->resetAfterTest();
        force_current_language('nl');
        $method = "onbekend/één\"\\\nregel";
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 'nl-7', 'method' => $method], JSON_THROW_ON_ERROR);
        $response = (new server())->handle_payload($payload);
        $decoded = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame('nl-7', $decoded['id']);
        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertSame(
            get_string('mcp_method_not_found', 'tool_teachermcp', $method),
            $decoded['error']['message']
        );
        $this->assertArrayNotHasKey('result', $decoded);
    }

    /**
     * Endpoint scripts must not reintroduce literal human-readable descriptions.
     *
     * The scripts terminate the request, so check their response declarations without including them.
     */
    public function test_endpoint_descriptions_use_language_strings(): void {
        foreach (['endpoint.php', 'oauth/token.php', 'oauth/register.php', 'oauth/authorize.php'] as $path) {
            $source = file_get_contents(__DIR__ . '/../' . $path);
            $this->assertDoesNotMatchRegularExpression(
                '/\x27(?:error_description|message|server)\x27\\s*=>\\s*\x27[^\x27]*[ A-Z][^\x27]*\x27/',
                $source,
                $path
            );
        }
    }

    /**
     * Test the translated truncation notice retains the response byte budget.
     */
    public function test_truncation_notice_is_translatable(): void {
        $this->resetAfterTest();
        set_config('max_mcp_response_bytes', 1024, 'tool_teachermcp');
        $response = server::format_tool_result_for_response(str_repeat('x', 2048));
        $this->assertStringEndsWith("\n" . get_string('mcp_output_truncated', 'tool_teachermcp', 1024), $response);
        $this->assertLessThanOrEqual(1024, strlen($response));
    }
}
