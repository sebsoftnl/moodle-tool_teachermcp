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

use stdClass;
use moodle_url;
use moodle_exception;

/**
 * OAuth 2.1 Authorization Server with strict PKCE (RFC 7636, RFC 8252, RFC 9700).
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth_server {

    /** OAuth scopes which this authorization server can enforce. */
    private const SUPPORTED_SCOPES = ['teachermcp', 'read', 'write', 'grade', 'message', 'delete', 'all'];

    /**
     * Validate and normalize an OAuth scope string.
     *
     * @param string|null $scope Requested OAuth scope.
     * @return array{scope: string, permissions: array}
     * @throws moodle_exception When no valid scope is requested.
     */
    public static function normalise_scope(?string $scope): array {
        $requested = preg_split('/\s+/', trim((string)$scope), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($requested)) {
            $requested = ['teachermcp'];
        }

        $requested = array_values(array_unique($requested));
        $externaltools = server::get_grantable_external_tool_names();
        $legacyscopes = [];
        $externalgrants = [];
        foreach ($requested as $item) {
            if (in_array($item, $externaltools, true)) {
                $externalgrants[$item] = true;
            } else if (in_array($item, self::SUPPORTED_SCOPES, true)) {
                $legacyscopes[] = $item;
            } else {
                throw new moodle_exception('error_invalid_scope', 'tool_teachermcp');
            }
        }

        if (in_array('teachermcp', $legacyscopes, true) || in_array('all', $legacyscopes, true)) {
            if (count($legacyscopes) !== 1) {
                throw new moodle_exception('error_invalid_scope', 'tool_teachermcp');
            }
            return [
                'scope' => implode(' ', $requested),
                'permissions' => array_merge(['all_teacher_tools' => true], $externalgrants),
            ];
        }

        $permissions = $externalgrants;
        if (!empty($legacyscopes)) {
            $permissions['scopes'] = $legacyscopes;
        }
        return ['scope' => implode(' ', $requested), 'permissions' => $permissions];
    }

    /**
     * Describe currently enabled tools without changing the normalized OAuth grants.
     *
     * @param array $permissions Permissions returned by normalise_scope().
     * @return array[] Consent rows with an id and a translated description.
     */
    public static function get_consent_permissions(array $permissions): array {
        $scopes = [];
        $external = [];
        $token = (object)['permissions' => $permissions];
        foreach (server::get_tools($token) as $name => $tool) {
            if (server::is_external_tool($name)) {
                $external[$name] = [
                    'id' => $name,
                    'description' => get_string('oauth_perm_external', 'tool_teachermcp', $name),
                ];
            } else {
                $scopes[server::get_tool_scope($name)] = true;
            }
        }

        $rows = [];
        foreach (['read', 'write', 'grade', 'message', 'delete'] as $scope) {
            if (isset($scopes[$scope])) {
                $rows[] = [
                    'id' => $scope,
                    'description' => get_string('oauth_perm_' . $scope, 'tool_teachermcp'),
                ];
            }
        }
        ksort($external);
        return array_merge($rows, array_values($external));
    }

    /**
     * Get all OAuth scopes currently supported by this server.
     *
     * @return string[]
     */
    public static function get_supported_scopes(): array {
        return array_values(array_unique(array_merge(
            self::SUPPORTED_SCOPES,
            server::get_grantable_external_tool_names()
        )));
    }

    /**
     * Get OAuth 2.0 / RFC 8414 Authorization Server Metadata.
     *
     * @return array Metadata dictionary
     */
    public static function get_metadata(): array {
        global $CFG;

        $base = $CFG->wwwroot . '/admin/tool/teachermcp/oauth';

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/authorize.php',
            'token_endpoint' => $base . '/token.php',
            'registration_endpoint' => $base . '/register.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'], // K3-R3: S256 only, plain removed.
            'scopes_supported' => self::get_supported_scopes(),
            'token_endpoint_auth_methods_supported' => ['none'], // K3-R6: public clients only.
            'service_documentation' => $CFG->wwwroot . '/admin/tool/teachermcp/my_agent.php',
            'resource_registration' => $base . '/protected-resource.php',
        ];
    }

    /**
     * Check if a given URI is a loopback URI (RFC 8252).
     *
     * @param string $uri
     * @return bool
     */
    public static function is_loopback_uri(string $uri): bool {
        $parts = parse_url($uri);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        return ($scheme === 'http' && ($host === '127.0.0.1' || $host === 'localhost' || $host === '[::1]'));
    }

    /**
     * Validate loopback URI per RFC 8252 §7.3.
     * Port can vary for http://127.0.0.1:<port>/<path> or http://localhost:<port>/<path>.
     *
     * @param string $registereduri Registered URI template
     * @param string $provideduri Actual redirect URI provided in request
     * @return bool
     */
    public static function validate_loopback_uri(string $registereduri, string $provideduri): bool {
        $regparts = parse_url($registereduri);
        $provparts = parse_url($provideduri);

        if (!$regparts || !$provparts) {
            return false;
        }

        // Must be HTTP scheme.
        if (($provparts['scheme'] ?? '') !== 'http') {
            return false;
        }

        // Host must be 127.0.0.1 or localhost.
        $provhost = strtolower($provparts['host'] ?? '');
        if ($provhost !== '127.0.0.1' && $provhost !== 'localhost') {
            return false;
        }

        // Path must match.
        $regpath = rtrim($regparts['path'] ?? '', '/');
        $provpath = rtrim($provparts['path'] ?? '', '/');
        if ($regpath !== $provpath) {
            return false;
        }

        // Reject any fragment in provided URI.
        if (isset($provparts['fragment'])) {
            return false;
        }

        return true;
    }

    /**
     * Validate incoming authorization request parameters (K3-R1, R2, R3, B8).
     *
     * @param string $clientid Client identifier
     * @param string $redirecturi Redirect URI
     * @param string $responsetype Expected 'code'
     * @param string $codechallenge PKCE S256 challenge string
     * @param string $codechallengemethod Expected 'S256'
     * @return stdClass Client database record on success
     * @throws moodle_exception If validation fails
     */
    public static function validate_authorization_params(
        string $clientid,
        string $redirecturi,
        string $responsetype,
        string $codechallenge,
        string $codechallengemethod
    ): stdClass {
        global $DB;

        // K3-R1: Client must be explicitly registered.
        if (empty($clientid)) {
            throw new moodle_exception('error_invalid_client', 'tool_teachermcp');
        }

        $client = $DB->get_record('tool_teachermcp_oauth_clients', ['clientid' => $clientid]);
        if (!$client) {
            throw new moodle_exception('error_invalid_client', 'tool_teachermcp');
        }

        // K3-R2: Redirect URI must strictly match registered URIs.
        if (empty($redirecturi)) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        $alloweduris = json_decode($client->redirecturis ?? '[]', true);
        if (!is_array($alloweduris)) {
            $alloweduris = [];
        }

        // B8: Validate per-URI loopback matching to support clients with mixed loopback and https endpoints.
        $urimatched = false;
        foreach ($alloweduris as $entry) {
            $reguri = is_array($entry) ? ($entry['uri'] ?? '') : (is_object($entry) ? ($entry->uri ?? '') : (string)$entry);
            if (empty($reguri)) {
                continue;
            }

            $isloop = is_array($entry) && isset($entry['loopback'])
                ? !empty($entry['loopback'])
                : (is_object($entry) && isset($entry->loopback) ? !empty($entry->loopback) : self::is_loopback_uri($reguri));

            if ($isloop) {
                if (self::validate_loopback_uri($reguri, $redirecturi)) {
                    $urimatched = true;
                    break;
                }
            } else {
                if (rtrim($reguri, '/') === rtrim($redirecturi, '/')) {
                    $urimatched = true;
                    break;
                }
            }
        }

        if (!$urimatched) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        // Response type must be code.
        if ($responsetype !== 'code') {
            throw new moodle_exception('error_unsupported_response_type', 'tool_teachermcp');
        }

        // K3-R3: PKCE mandatory, S256 only. Challenge must be 43-128 chars base64url.
        if (empty($codechallenge) || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codechallenge)) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        if ($codechallengemethod !== 'S256') {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        return $client;
    }

    /**
     * Create and store a temporary authorization code with PKCE challenge.
     *
     * @param int $userid Authorizing Moodle user ID.
     * @param string $clientid OAuth client ID.
     * @param string $redirecturi Client callback URL.
     * @param string $codechallenge PKCE challenge string.
     * @param string $codechallengemethod Only S256 supported.
     * @param string|null $scope Requested scopes.
     * @return string Raw authorization code.
     */
    public static function create_authorization_code(
        int $userid,
        string $clientid,
        string $redirecturi,
        string $codechallenge,
        string $codechallengemethod = 'S256',
        ?string $scope = 'teachermcp'
    ): string {
        global $DB;

        $rawcode = 'mcpauth_' . bin2hex(random_bytes(32));
        $codehash = hash('sha256', $rawcode);
        $now = time();

        $rec = new stdClass();
        $rec->codehash = $codehash;
        $rec->clientid = clean_param($clientid, PARAM_ALPHANUMEXT);
        $rec->userid = $userid;
        $rec->redirecturi = clean_param($redirecturi, PARAM_URL);
        $scopeconfig = self::normalise_scope($scope);
        $rec->scope = $scopeconfig['scope'];
        $rec->codechallenge = $codechallenge;
        $rec->codechallengemethod = 'S256';
        $rec->timecreated = $now;
        $rec->timeexpires = $now + 600; // 10 minutes.
        $rec->status = 0; // 0 = unused.
        $rec->issuedtokenid = null;

        $DB->insert_record('tool_teachermcp_oauth_codes', $rec);

        return $rawcode;
    }

    /**
     * Verify authorization code, validate PKCE challenge, and issue an access token.
     * Atomic single-use consumption with reuse token revocation (K3-R4, R5, R9).
     *
     * @param string $rawcode Authorization code provided by client.
     * @param string $clientid Client ID.
     * @param string $redirecturi Target redirect URI.
     * @param string|null $codeverifier PKCE verifier secret.
     * @return array Token response structure.
     * @throws moodle_exception On validation failure.
     */
    public static function exchange_code_for_token(
        string $rawcode,
        string $clientid,
        string $redirecturi,
        ?string $codeverifier = null
    ): array {
        global $DB;

        if (empty($rawcode)) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        // K3-R4: client_id and redirect_uri are mandatory.
        if (empty($clientid) || empty($redirecturi)) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        $codehash = hash('sha256', trim($rawcode));
        $rec = $DB->get_record('tool_teachermcp_oauth_codes', ['codehash' => $codehash]);

        if (!$rec) {
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // K3-R5: Code reuse detection. If code was already used, revoke the issued token!
        if ((int)$rec->status !== 0) {
            if (!empty($rec->issuedtokenid)) {
                auth::revoke_token((int)$rec->issuedtokenid, (int)$rec->userid);
            }
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // Check expiration.
        if ((int)$rec->timeexpires < time()) {
            $DB->set_field('tool_teachermcp_oauth_codes', 'status', 2, ['id' => $rec->id]);
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // K3-R4: Client ID must match.
        if ($rec->clientid !== $clientid) {
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // K3-R4: Redirect URI must match exactly.
        if (rtrim($rec->redirecturi, '/') !== rtrim($redirecturi, '/')) {
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // K3-R3: Validate PKCE code_verifier (required, 43-128 chars [A-Za-z0-9-._~]).
        if (empty($codeverifier) || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeverifier)) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        $hash = hash('sha256', $codeverifier, true);
        $calculated = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        if (!hash_equals($rec->codechallenge, $calculated)) {
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // Verify authorizing user account.
        $user = $DB->get_record('user', ['id' => $rec->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user || $user->auth === 'nologin') {
            throw new moodle_exception('error_invalid_user', 'tool_teachermcp');
        }

        // Verify user can connect agent.
        if (!auth::user_can_connect($user->id)) {
            throw new moodle_exception('error_no_capability', 'tool_teachermcp');
        }

        // Issue token.
        $clientrec = $DB->get_record('tool_teachermcp_oauth_clients', ['clientid' => $rec->clientid]);
        $clientlabel = $clientrec ? $clientrec->clientname : ucfirst($rec->clientid);
        $agentname = $clientlabel . ' (OAuth)';

        // Token validity per admin settings.
        $maxvalidity = (int)get_config('tool_teachermcp', 'max_token_validity');
        if ($maxvalidity <= 0) {
            $maxvalidity = 90;
        }

        // B5: Atomic single-use code consumption via unique claim token before issuing access token.
        $claimtoken = bin2hex(random_bytes(32));
        $DB->execute(
            "UPDATE {tool_teachermcp_oauth_codes} SET status = 1, claimtoken = :claim WHERE id = :id AND status = 0",
            ['claim' => $claimtoken, 'id' => $rec->id]
        );

        $reloaded = $DB->get_record('tool_teachermcp_oauth_codes', ['id' => $rec->id]);
        if (!$reloaded || $reloaded->claimtoken !== $claimtoken) {
            throw new moodle_exception('error_invalid_grant', 'tool_teachermcp');
        }

        // Issue token now that the code is safely and uniquely consumed.
        $scopeconfig = self::normalise_scope($rec->scope);
        $tokenres = auth::generate_token_record(
            $rec->userid,
            $agentname,
            $maxvalidity,
            $rec->clientid,
            $scopeconfig['permissions']
        );
        $tokenid = $tokenres['id'];
        $accesstoken = $tokenres['token'];

        // Store issuedtokenid on the consumed code record for reuse revocation tracking.
        $DB->set_field('tool_teachermcp_oauth_codes', 'issuedtokenid', $tokenid, ['id' => $rec->id]);

        return [
            'access_token' => $accesstoken,
            'token_type' => 'Bearer',
            'expires_in' => $maxvalidity * 86400,
            'scope' => $scopeconfig['scope'],
        ];
    }

    /**
     * Register a dynamic client (RFC 7591) per K3-R6, K3-R7, B7, B8.
     *
     * @param string $clientname Human readable client name
     * @param array $redirecturis Array of redirect URIs
     * @param string $clientip Client IP address for rate limiting
     * @return array
     * @throws moodle_exception On validation failure
     */
    public static function register_client(string $clientname, array $redirecturis = [], string $clientip = ''): array {
        global $DB;

        // K3-R7 & B7: Check if dynamic registration is enabled by administrator.
        $registrationenabled = get_config('tool_teachermcp', 'oauth_registration_enabled');
        if (empty($registrationenabled)) {
            throw new moodle_exception('error_registration_disabled', 'tool_teachermcp');
        }

        // K3-R7: IP Rate limiting (max 10 registrations per hour).
        $cache = \cache::make('tool_teachermcp', 'ratelimit');
        $cachekey = 'reg_' . md5($clientip ?: 'unknown');
        $count = (int)$cache->get($cachekey);
        if ($count >= 10) {
            throw new moodle_exception('error_rate_limited', 'tool_teachermcp');
        }
        $cache->set($cachekey, $count + 1);

        // Client name validation (max 100 characters).
        $clientname = clean_param(trim($clientname), PARAM_TEXT);
        if (empty($clientname) || strlen($clientname) > 100) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        // Max 5 redirect URIs.
        if (empty($redirecturis) || count($redirecturis) > 5) {
            throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
        }

        $validateduris = [];
        $hasloopback = 0;

        foreach ($redirecturis as $uri) {
            $uri = clean_param(trim($uri), PARAM_URL);
            $parts = parse_url($uri);
            if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
                throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
            }

            // No fragments allowed.
            if (isset($parts['fragment'])) {
                throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
            }

            // No wildcards allowed.
            if (strpos($uri, '*') !== false) {
                throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
            }

            $scheme = strtolower($parts['scheme']);
            $host = strtolower($parts['host']);

            $isloop = ($scheme === 'http' && ($host === '127.0.0.1' || $host === 'localhost' || $host === '[::1]'));
            if ($isloop) {
                $hasloopback = 1;
            } else if ($scheme === 'https') {
                // HTTPS allowed for public clients.
            } else {
                throw new moodle_exception('error_invalid_request', 'tool_teachermcp');
            }

            // B8: Store individual URI metadata with explicit loopback flag.
            $validateduris[] = [
                'uri' => $uri,
                'loopback' => $isloop,
            ];
        }

        $clientid = 'client_' . bin2hex(random_bytes(16));
        $now = time();

        $rec = new stdClass();
        $rec->clientid = $clientid;
        $rec->clientsecret = null; // K3-R6: Public clients only, no secret.
        $rec->clientname = $clientname;
        $rec->redirecturis = json_encode($validateduris);
        $rec->isloopback = $hasloopback;
        $rec->isverified = 0; // B6: Newly registered public clients are unverified by default.
        $rec->timecreated = $now;

        $DB->insert_record('tool_teachermcp_oauth_clients', $rec);

        // K3-R6: Only return public client credentials (no client_secret).
        return [
            'client_id' => $clientid,
            'client_name' => $clientname,
            'redirect_uris' => array_column($validateduris, 'uri'),
            'token_endpoint_auth_method' => 'none',
        ];
    }
}
