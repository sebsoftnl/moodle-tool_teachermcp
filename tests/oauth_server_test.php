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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for OAuth client registration, PKCE authorization-code exchange, and scope grants.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      tool_teachermcp
 * @covers     \tool_teachermcp\oauth_server
 */
final class oauth_server_test extends \advanced_testcase {
    public function test_metadata_advertises_the_same_currently_supported_scopes(): void {
        $this->resetAfterTest(true);

        $expected = \tool_teachermcp\oauth_server::get_supported_scopes();
        $metadata = \tool_teachermcp\oauth_server::get_metadata();

        $this->assertContains('read', $expected);
        $this->assertContains('write', $expected);
        $this->assertContains('all', $expected);
        $this->assertSame($expected, $metadata['scopes_supported']);
    }

    public function test_oauth_rejects_unknown_qualified_external_tool_scope(): void {
        $this->resetAfterTest(true);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_invalid_scope', 'tool_teachermcp'));
        \tool_teachermcp\oauth_server::normalise_scope('local_unknown.example');
    }

    public function test_oauth_registration_disabled(): void {
        $this->resetAfterTest(true);
        set_config('oauth_registration_enabled', 0, 'tool_teachermcp');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_registration_disabled', 'tool_teachermcp'));
        \tool_teachermcp\oauth_server::register_client('Test App', ['https://example.com/cb'], '127.0.0.1');
    }

    public function test_oauth_registration_validation_and_mixed_uris(): void {
        global $DB;
        $this->resetAfterTest(true);
        set_config('oauth_registration_enabled', 1, 'tool_teachermcp');

        // Invalid: empty client name.
        try {
            \tool_teachermcp\oauth_server::register_client('', ['https://example.com/cb'], '127.0.0.1');
            $this->fail('Expected exception for empty client name');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_request', $e->errorcode);
        }

        // Invalid: URI with fragment.
        try {
            \tool_teachermcp\oauth_server::register_client('App', ['https://example.com/cb#frag'], '127.0.0.1');
            $this->fail('Expected exception for URI with fragment');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_request', $e->errorcode);
        }

        // Invalid: URI with wildcard.
        try {
            \tool_teachermcp\oauth_server::register_client('App', ['https://*.example.com/cb'], '127.0.0.1');
            $this->fail('Expected exception for URI with wildcard');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_request', $e->errorcode);
        }

        // Valid registration with mixed loopback and HTTPS URIs (B8).
        $reg = \tool_teachermcp\oauth_server::register_client(
            'Mixed Agent Client',
            ['http://127.0.0.1/callback', 'https://agent.example.com/oauth/callback'],
            '127.0.0.1'
        );

        $this->assertNotEmpty($reg['client_id']);
        $this->assertEquals('Mixed Agent Client', $reg['client_name']);
        $this->assertEquals('none', $reg['token_endpoint_auth_method']);
        $this->assertArrayNotHasKey('client_secret', $reg);
        $this->assertCount(2, $reg['redirect_uris']);

        // Inspect DB record.
        $dbclient = $DB->get_record('tool_teachermcp_oauth_clients', ['clientid' => $reg['client_id']]);
        $this->assertNotEmpty($dbclient);
        $this->assertEquals(0, (int)$dbclient->isverified); // B6: Unverified by default.
        $this->assertEquals(1, (int)$dbclient->isloopback);

        $storeduris = json_decode($dbclient->redirecturis, true);
        $this->assertIsArray($storeduris);
        $this->assertTrue($storeduris[0]['loopback']);
        $this->assertFalse($storeduris[1]['loopback']);
    }

    public function test_oauth_registration_rate_limiting(): void {
        $this->resetAfterTest(true);
        set_config('oauth_registration_enabled', 1, 'tool_teachermcp');

        $testip = '198.51.100.42';
        for ($i = 0; $i < 10; $i++) {
            $reg = \tool_teachermcp\oauth_server::register_client("Client $i", ['https://example.com/cb'], $testip);
            $this->assertNotEmpty($reg['client_id']);
        }

        // 11th registration from same IP must be rate limited.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_rate_limited', 'tool_teachermcp'));
        \tool_teachermcp\oauth_server::register_client("Client 11", ['https://example.com/cb'], $testip);
    }

    public function test_oauth_authorization_params_validation_and_mixed_matching(): void {
        $this->resetAfterTest(true);
        set_config('oauth_registration_enabled', 1, 'tool_teachermcp');

        $reg = \tool_teachermcp\oauth_server::register_client(
            'Dual Client',
            ['http://127.0.0.1/callback', 'https://secure.example.com/callback'],
            '127.0.0.1'
        );
        $clientid = $reg['client_id'];

        $challenge = 'E9Melhoa2OwvFrGMTJguCH50lK6Zw1F3-EV38YZ2_50';

        // 1. Loopback URI with dynamic port (RFC 8252 §7.3).
        $client1 = \tool_teachermcp\oauth_server::validate_authorization_params(
            $clientid,
            'http://127.0.0.1:49152/callback',
            'code',
            $challenge,
            'S256'
        );
        $this->assertEquals($clientid, $client1->clientid);

        // 2. HTTPS URI matching.
        $client2 = \tool_teachermcp\oauth_server::validate_authorization_params(
            $clientid,
            'https://secure.example.com/callback',
            'code',
            $challenge,
            'S256'
        );
        $this->assertEquals($clientid, $client2->clientid);

        // 3. Unregistered URI rejected.
        try {
            \tool_teachermcp\oauth_server::validate_authorization_params(
                $clientid,
                'https://attacker.example.com/callback',
                'code',
                $challenge,
                'S256'
            );
            $this->fail('Expected exception for unregistered redirect URI');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_request', $e->errorcode);
        }

        // 4. Invalid PKCE method (plain rejected, K3-R3).
        try {
            \tool_teachermcp\oauth_server::validate_authorization_params(
                $clientid,
                'https://secure.example.com/callback',
                'code',
                $challenge,
                'plain'
            );
            $this->fail('Expected exception for plain PKCE method');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_request', $e->errorcode);
        }
    }

    public function test_oauth_token_exchange_and_reuse_revocation(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Create registered client.
        $clientrec = (object)[
            'clientid' => 'test-agent-client',
            'clientname' => 'Test Agent Client',
            'redirecturis' => json_encode(['http://127.0.0.1/callback']),
            'isloopback' => 1,
            'isverified' => 1,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_teachermcp_oauth_clients', $clientrec);

        // Prepare PKCE pair.
        $codeverifier = 'dBjftJeZ4CVP-mKDnn_rhgURz37UxvuvFQZfr_KbyVU_1234567890';
        $codechallenge = rtrim(strtr(base64_encode(hash('sha256', $codeverifier, true)), '+/', '-_'), '=');

        $rawcode = \tool_teachermcp\oauth_server::create_authorization_code(
            (int)$teacher->id,
            'test-agent-client',
            'http://127.0.0.1:3000/callback',
            $codechallenge,
            'S256',
            'read'
        );

        // 1. Invalid verifier rejected.
        try {
            \tool_teachermcp\oauth_server::exchange_code_for_token(
                $rawcode,
                'test-agent-client',
                'http://127.0.0.1:3000/callback',
                'wrong_verifier_string_that_does_not_match_hash_123456789'
            );
            $this->fail('Expected exception for mismatched PKCE verifier');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_grant', $e->errorcode);
        }

        // 2. Successful exchange.
        $tokenresponse = \tool_teachermcp\oauth_server::exchange_code_for_token(
            $rawcode,
            'test-agent-client',
            'http://127.0.0.1:3000/callback',
            $codeverifier
        );

        $this->assertNotEmpty($tokenresponse['access_token']);
        $this->assertEquals('Bearer', $tokenresponse['token_type']);
        $this->assertEquals('read', $tokenresponse['scope']);

        // Check issued token record in DB.
        $tokenhash = hash('sha256', $tokenresponse['access_token']);
        $tokenrec = $DB->get_record('tool_teachermcp_tokens', ['tokenhash' => $tokenhash]);
        $this->assertNotEmpty($tokenrec);
        $this->assertEquals(1, (int)$tokenrec->status); // Active.
        $this->assertTrue(server::is_tool_allowed_by_token('get_my_profile', $tokenrec));
        $this->assertFalse(server::is_tool_allowed_by_token('manage_activity', $tokenrec));

        // Verify code record status in DB (B5).
        $codehash = hash('sha256', $rawcode);
        $coderec = $DB->get_record('tool_teachermcp_oauth_codes', ['codehash' => $codehash]);
        $this->assertEquals(1, (int)$coderec->status); // Used.
        $this->assertEquals((int)$tokenrec->id, (int)$coderec->issuedtokenid); // Linked!

        // 3. Re-using the same code must fail AND revoke the previously issued token (B5, K3-R5)!
        try {
            \tool_teachermcp\oauth_server::exchange_code_for_token(
                $rawcode,
                'test-agent-client',
                'http://127.0.0.1:3000/callback',
                $codeverifier
            );
            $this->fail('Expected exception for authorization code reuse');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_grant', $e->errorcode);
        }

        // Re-read issued token from DB to ensure revocation occurred.
        $revokedtoken = $DB->get_record('tool_teachermcp_tokens', ['id' => $tokenrec->id]);
        $this->assertEquals(0, (int)$revokedtoken->status); // Ingetrokken / Revoked!
    }

    public function test_oauth_atomic_claim_race_condition(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $codeverifier = 'dBjftJeZ4CVP-mKDnn_rhgURz37UxvuvFQZfr_KbyVU_1234567890';
        $codechallenge = rtrim(strtr(base64_encode(hash('sha256', $codeverifier, true)), '+/', '-_'), '=');

        $rawcode = \tool_teachermcp\oauth_server::create_authorization_code(
            (int)$teacher->id,
            'claude-desktop',
            'http://127.0.0.1:3000/callback',
            $codechallenge,
            'S256'
        );

        // Simulate concurrent process consuming the code right before exchange.
        $codehash = hash('sha256', $rawcode);
        $DB->set_field('tool_teachermcp_oauth_codes', 'status', 1, ['codehash' => $codehash]);

        $tokencountbefore = $DB->count_records('tool_teachermcp_tokens');

        try {
            \tool_teachermcp\oauth_server::exchange_code_for_token(
                $rawcode,
                'claude-desktop',
                'http://127.0.0.1:3000/callback',
                $codeverifier
            );
            $this->fail('Expected exception for raced/consumed code');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_invalid_grant', $e->errorcode);
        }

        // Ensure no phantom token was created.
        $this->assertEquals($tokencountbefore, $DB->count_records('tool_teachermcp_tokens'));
    }

    public function test_oauth_max_tokens_per_client_fifo(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Set maximum tokens per client to 3.
        set_config('max_oauth_tokens_per_client', 3, 'tool_teachermcp');

        $clientid = 'claude-desktop';

        // Issue 3 tokens.
        $tok1 = \tool_teachermcp\auth::generate_token_record($teacher->id, 'Device 1', 90, $clientid);
        sleep(1);
        $tok2 = \tool_teachermcp\auth::generate_token_record($teacher->id, 'Device 2', 90, $clientid);
        sleep(1);
        $tok3 = \tool_teachermcp\auth::generate_token_record($teacher->id, 'Device 3', 90, $clientid);

        // All 3 should be active.
        $activecount = $DB->count_records('tool_teachermcp_tokens', [
            'userid' => $teacher->id,
            'clientid' => $clientid,
            'status' => 1,
        ]);
        $this->assertEquals(3, $activecount);

        // Issue 4th token.
        sleep(1);
        $tok4 = \tool_teachermcp\auth::generate_token_record($teacher->id, 'Device 4', 90, $clientid);

        // Total active count must still be max 3.
        $activecountAfter = $DB->count_records('tool_teachermcp_tokens', [
            'userid' => $teacher->id,
            'clientid' => $clientid,
            'status' => 1,
        ]);
        $this->assertEquals(3, $activecountAfter);

        // The oldest token ($tok1) must now be revoked (status = 0).
        $tok1Db = $DB->get_record('tool_teachermcp_tokens', ['id' => $tok1['id']]);
        $this->assertEquals(0, (int)$tok1Db->status);

        // Newer tokens ($tok2, $tok3, $tok4) must remain active.
        $this->assertEquals(1, (int)$DB->get_field('tool_teachermcp_tokens', 'status', ['id' => $tok2['id']]));
        $this->assertEquals(1, (int)$DB->get_field('tool_teachermcp_tokens', 'status', ['id' => $tok3['id']]));
        $this->assertEquals(1, (int)$DB->get_field('tool_teachermcp_tokens', 'status', ['id' => $tok4['id']]));
    }
}
