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
 * Exact OAuth redirect matching regressions.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\oauth_server
 */
final class oauth_redirect_test extends \advanced_testcase {
    /**
     * Only the port may change on a registered loopback URI.
     *
     * @dataProvider loopback_redirect_provider
     * @param string $registered Registered URI.
     * @param string $provided Request URI.
     * @param bool $expected Whether it matches.
     */
    public function test_loopback_matching(string $registered, string $provided, bool $expected): void {
        $this->assertSame($expected, oauth_server::validate_loopback_uri($registered, $provided));
    }

    /**
     * Authorization must preserve the registered URI, except its loopback port.
     *
     * @dataProvider authorization_redirect_provider
     * @param string $registered Registered URI.
     * @param string $provided Request URI.
     * @param bool $expected Whether it matches.
     */
    public function test_authorization_matching(string $registered, string $provided, bool $expected): void {
        $this->resetAfterTest();
        set_config('oauth_registration_enabled', 1, 'tool_teachermcp');
        $registration = oauth_server::register_client('Redirect test', [$registered], '192.0.2.5');
        if (!$expected) {
            $this->expectException(\moodle_exception::class);
            $this->expectExceptionMessage(get_string('error_invalid_request', 'tool_teachermcp'));
        }
        $client = oauth_server::validate_authorization_params(
            $registration['client_id'],
            $provided,
            'code',
            str_repeat('a', 43),
            'S256'
        );
        $this->assertSame($registration['client_id'], $client->clientid);
    }

    /**
     * Registered URI matching across registration and authorization.
     *
     * @return array
     */
    public static function authorization_redirect_provider(): array {
        return array_merge(self::loopback_redirect_provider_for_registration(), [
            'https exact' => ['https://example.com/cb?a=1', 'https://example.com/cb?a=1', true],
            'https slash' => ['https://example.com/cb', 'https://example.com/cb/', false],
            'https query slash' => ['https://example.com/cb?a=1', 'https://example.com/cb?a=1/', false],
            'https port' => ['https://example.com/cb', 'https://example.com:443/cb', false],
            'https host case' => ['https://example.com/cb', 'https://EXAMPLE.com/cb', false],
        ]);
    }

    /**
     * Valid registered loopback URIs with positive and negative request variants.
     *
     * @return array
     */
    private static function loopback_redirect_provider_for_registration(): array {
        $cases = self::loopback_redirect_provider();
        unset($cases['registered fragment'], $cases['not registered loopback']);
        return $cases;
    }

    /**
     * Token exchange must use the exact authorization request URI, including port.
     *
     * @dataProvider token_redirect_provider
     * @param string $authorized Authorization request URI.
     * @param string $provided Token request URI.
     */
    public function test_token_redirect_binding(string $authorized, string $provided): void {
        global $DB;
        $this->resetAfterTest();
        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $verifier = str_repeat('a', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = oauth_server::create_authorization_code($teacher->id, 'redirect-test', $authorized, $challenge);
        $record = $DB->get_record('tool_teachermcp_oauth_codes', ['codehash' => hash('sha256', $code)], '*', MUST_EXIST);
        $this->assertSame($authorized, $record->redirecturi);
        $tokencount = $DB->count_records('tool_teachermcp_tokens');
        try {
            oauth_server::exchange_code_for_token($code, 'redirect-test', $provided, $verifier);
            $this->fail('A changed redirect URI must not redeem the code.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalid_grant', $e->errorcode);
        }
        $this->assertEquals($tokencount, $DB->count_records('tool_teachermcp_tokens'));
        $this->assertEquals(0, $DB->get_field('tool_teachermcp_oauth_codes', 'status', ['id' => $record->id]));
        $response = oauth_server::exchange_code_for_token($code, 'redirect-test', $authorized, $verifier);
        $this->assertNotEmpty($response['access_token']);
    }

    /**
     * Token redirect variations must all fail, even loopback port changes.
     *
     * @return array
     */
    public static function token_redirect_provider(): array {
        return [
            'https slash' => ['https://example.com/cb', 'https://example.com/cb/'],
            'https query slash' => ['https://example.com/cb?x=1', 'https://example.com/cb?x=1/'],
            'ipv4 port' => ['http://127.0.0.1:49152/cb', 'http://127.0.0.1:49153/cb'],
            'ipv6 port' => ['http://[::1]:49152/cb', 'http://[::1]:49153/cb'],
            'loopback slash' => ['http://localhost:49152/cb', 'http://localhost:49152/cb/'],
            'loopback query' => ['http://localhost:49152/cb?x=1', 'http://localhost:49152/cb?x=2'],
            'loopback host' => ['http://127.0.0.1:49152/cb', 'http://localhost:49152/cb'],
        ];
    }

    /**
     * Loopback matching boundaries, including URI spelling and empty components.
     *
     * @return array
     */
    public static function loopback_redirect_provider(): array {
        return [
            'ipv4 port' => ['http://127.0.0.1/cb?a=1', 'http://127.0.0.1:49152/cb?a=1', true],
            'localhost port' => ['http://localhost:3000/cb', 'http://localhost:49152/cb', true],
            'ipv6 port' => ['http://[::1]/cb', 'http://[::1]:49152/cb', true],
            'different host' => ['http://127.0.0.1/cb', 'http://localhost:49152/cb', false],
            'different ipv6 host' => ['http://[::1]/cb', 'http://127.0.0.1:49152/cb', false],
            'different query' => ['http://localhost/cb?a=1', 'http://localhost:49152/cb?a=2', false],
            'missing query' => ['http://localhost/cb?a=1', 'http://localhost:49152/cb', false],
            'extra query' => ['http://localhost/cb', 'http://localhost:49152/cb?', false],
            'query order' => ['http://localhost/cb?a=1&b=2', 'http://localhost/cb?b=2&a=1', false],
            'trailing slash' => ['http://localhost/cb', 'http://localhost:49152/cb/', false],
            'empty path' => ['http://localhost', 'http://localhost:49152/', false],
            'path case' => ['http://localhost/cb', 'http://localhost:49152/CB', false],
            'host case' => ['http://localhost/cb', 'http://LOCALHOST:49152/cb', false],
            'scheme case' => ['http://localhost/cb', 'HTTP://localhost:49152/cb', false],
            'encoded path' => ['http://localhost/cb', 'http://localhost:49152/%63b', false],
            'fragment' => ['http://localhost/cb', 'http://localhost:49152/cb#', false],
            'registered fragment' => ['http://localhost/cb#', 'http://localhost:49152/cb', false],
            'added userinfo' => ['http://localhost/cb', 'http://user@localhost:49152/cb', false],
            'not registered loopback' => ['https://example.com/cb', 'http://localhost:49152/cb', false],
        ];
    }
}
