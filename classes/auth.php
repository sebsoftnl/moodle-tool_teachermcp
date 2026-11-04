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
use context_system;
use moodle_exception;

/**
 * Authentication and token management for Teacher MCP agent connections.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth {

    /** @var stdClass|null Currently authenticated token record */
    protected static ?stdClass $currenttoken = null;

    /**
     * Get the active token record authenticated for this request.
     *
     * @return stdClass|null
     */
    public static function get_current_token(): ?stdClass {
        return self::$currenttoken;
    }

    /**
     * Check whether a user is allowed to connect an agent (has connectagent capability in system or at least one course).
     * Caches result in MUC (TTL 300s) for performance (B12).
     *
     * @param int $userid User ID to check.
     * @return bool True if user can connect an agent.
     */
    public static function user_can_connect(int $userid): bool {
        $cache = \cache::make('tool_teachermcp', 'user_connect_capability');
        $cached = $cache->get($userid);
        if ($cached !== false) {
            return (bool)$cached;
        }

        // System context check (e.g. managers / admins).
        if (has_capability('tool/teachermcp:connectagent', context_system::instance(), $userid)) {
            $cache->set($userid, 1);
            return true;
        }

        // Check if user has capability in at least one course context.
        $courses = get_user_capability_course('tool/teachermcp:connectagent', $userid, true, '', 'id ASC', 1);
        $canconnect = !empty($courses);
        $cache->set($userid, $canconnect ? 1 : 0);
        return $canconnect;
    }

    /**
     * Invalidate cached connection capability for a user or all users.
     *
     * @param int|null $userid Specific user ID to invalidate, or null to purge all.
     */
    public static function invalidate_user_connect_cache(?int $userid = null): void {
        $cache = \cache::make('tool_teachermcp', 'user_connect_capability');
        if ($userid !== null && $userid > 0) {
            $cache->delete($userid);
        } else {
            $cache->purge();
        }
    }

    /**
     * Authenticate the incoming HTTP request via Bearer token.
     * Sets global $USER and initializes Moodle context.
     *
     * @return stdClass Authenticated Moodle user record.
     * @throws moodle_exception On missing, invalid, or expired token.
     */
    public static function authenticate_request(): stdClass {
        global $DB;

        $authheader = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authheader = trim($_SERVER['HTTP_AUTHORIZATION']);
        } else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authheader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } else if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authheader = trim($headers['Authorization']);
            } else if (isset($headers['authorization'])) {
                $authheader = trim($headers['authorization']);
            }
        }

        if (empty($authheader) || !preg_match('/^Bearer\s+(.+)$/i', $authheader, $matches)) {
            throw new moodle_exception('error_unauthorized', 'tool_teachermcp');
        }

        $rawtoken = trim($matches[1]);
        $tokenhash = hash('sha256', $rawtoken);

        $record = $DB->get_record('tool_teachermcp_tokens', ['tokenhash' => $tokenhash, 'status' => 1]);
        if (!$record) {
            throw new moodle_exception('error_invalid_token', 'tool_teachermcp');
        }

        if ($record->timeexpires > 0 && $record->timeexpires < time()) {
            throw new moodle_exception('error_expired_token', 'tool_teachermcp');
        }

        $user = $DB->get_record('user', ['id' => $record->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user || $user->auth === 'nologin') {
            throw new moodle_exception('error_invalid_user', 'tool_teachermcp');
        }

        // H1: Verify capability (must have connectagent in system or at least one course).
        if (!self::user_can_connect($user->id)) {
            throw new moodle_exception('error_no_capability', 'tool_teachermcp');
        }

        // Update token access telemetry.
        $update = new stdClass();
        $update->id = $record->id;
        $update->lastaccess = time();
        $update->lastip = getremoteaddr();
        $DB->update_record('tool_teachermcp_tokens', $update);

        self::$currenttoken = $record;

        // Establish user session context.
        \core\session\manager::set_user($user);

        return $user;
    }

    /**
     * Enforce a bounded fixed-window MCP request rate for one token and IP address.
     *
     * @param stdClass $token Authenticated token record.
     * @param int|null $now Optional sampled timestamp for deterministic window evaluation.
     * @return void
     * @throws moodle_exception When the limit is exceeded, with retry seconds in the exception's a value.
     */
    public static function enforce_mcp_request_rate_limit(stdClass $token, ?int $now = null): void {
        $limit = (int)get_config('tool_teachermcp', 'mcp_requests_per_minute');
        if ($limit <= 0) {
            $limit = 60;
        }
        $limit = max(1, min(600, $limit));

        $now = $now ?? time();
        $window = (int)floor($now / 60);
        $key = 'mcp_' . hash('sha256', $token->id . '|' . getremoteaddr() . '|' . $window);
        $cache = \cache::make('tool_teachermcp', 'ratelimit');
        $count = (int)$cache->get($key);
        if ($count >= $limit) {
            throw new moodle_exception('error_rate_limited', 'tool_teachermcp', '', 60 - ($now % 60));
        }
        $cache->set($key, $count + 1);
    }

    /**
     * Generate a new agent bearer token for a user.
     *
     * @param int $userid Moodle user ID.
     * @param string $name Label for the agent / device.
     * @param int $validitydays Number of days valid (must be > 0 and <= max_token_validity).
     * @param string|null $clientid Optional OAuth client ID if issued via OAuth flow.
     * @param array|null $permissions Explicit tool permissions, or null for the full teacher tool set.
     * @return string Raw bearer token (shown once to user).
     * @throws moodle_exception If validity is invalid or user lacks capability.
     */
    public static function generate_token(
        int $userid,
        string $name,
        int $validitydays = 90,
        ?string $clientid = null,
        ?array $permissions = null
    ): string {
        $result = self::generate_token_record($userid, $name, $validitydays, $clientid, $permissions);
        return $result['token'];
    }

    /**
     * Generate a new agent bearer token record for a user (B5, B11).
     *
     * @param int $userid Moodle user ID.
     * @param string $name Label for the agent / device.
     * @param int $validitydays Number of days valid (must be > 0 and <= max_token_validity).
     * @param string|null $clientid Optional OAuth client ID if issued via OAuth flow.
     * @param array|null $permissions Explicit tool permissions, or null for the full teacher tool set.
     * @return array{token: string, id: int, record: stdClass}
     * @throws moodle_exception If validity is invalid or user lacks capability.
     */
    public static function generate_token_record(
        int $userid,
        string $name,
        int $validitydays = 90,
        ?string $clientid = null,
        ?array $permissions = null
    ): array {
        global $DB;

        if (!self::user_can_connect($userid)) {
            throw new moodle_exception('error_no_capability', 'tool_teachermcp');
        }

        $maxvalidity = (int)get_config('tool_teachermcp', 'max_token_validity');
        if ($maxvalidity <= 0) {
            $maxvalidity = 90;
        }

        if ($validitydays <= 0 || $validitydays > $maxvalidity) {
            $validitydays = $maxvalidity;
        }

        // B11: Multiple devices per user & client with configurable cap (default 5).
        if (!empty($clientid)) {
            $maxtokens = (int)get_config('tool_teachermcp', 'max_oauth_tokens_per_client');
            if ($maxtokens <= 0) {
                $maxtokens = 5;
            }

            $activetokens = $DB->get_records('tool_teachermcp_tokens', [
                'userid' => $userid,
                'clientid' => $clientid,
                'status' => 1,
            ], 'timecreated ASC, id ASC');

            if (count($activetokens) >= $maxtokens) {
                $numtorevoke = (count($activetokens) - $maxtokens) + 1;
                $torevoke = array_slice($activetokens, 0, $numtorevoke);
                foreach ($torevoke as $oldtok) {
                    self::revoke_token((int)$oldtok->id, $userid);
                }
            }
        }

        // Generate 64-character cryptographically secure token.
        $rawtoken = 'mcp_' . bin2hex(random_bytes(32));
        $tokenhash = hash('sha256', $rawtoken);
        $lastchars = substr($rawtoken, -8);

        $now = time();
        $timeexpires = $now + ($validitydays * 86400);

        $record = new stdClass();
        $record->userid = $userid;
        $record->name = clean_param($name, PARAM_TEXT);
        $record->tokenhash = $tokenhash;
        $record->tokenlastchars = $lastchars;
        $record->clientid = $clientid;
        $record->permissions = json_encode($permissions ?? ['all_teacher_tools' => true]);
        $record->lastaccess = 0;
        $record->lastip = '';
        $record->timeexpires = $timeexpires;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->status = 1;

        $record->id = $DB->insert_record('tool_teachermcp_tokens', $record);

        // M5: Trigger token_created event.
        \tool_teachermcp\event\token_created::create([
            'objectid' => $record->id,
            'relateduserid' => $userid,
            'context' => context_system::instance(),
            'other' => ['name' => $record->name, 'clientid' => $clientid],
        ])->trigger();

        return [
            'token' => $rawtoken,
            'id' => (int)$record->id,
            'record' => $record,
        ];
    }

    /**
     * Revoke an agent token.
     *
     * @param int $tokenid Token record ID.
     * @param int $userid ID of the user owning the token (or manager).
     * @return bool Success.
     */
    public static function revoke_token(int $tokenid, int $userid): bool {
        global $DB;

        $params = ['id' => $tokenid];
        if (!has_capability('tool/teachermcp:manageall', context_system::instance(), $userid)) {
            $params['userid'] = $userid;
        }

        $token = $DB->get_record('tool_teachermcp_tokens', $params);
        if (!$token) {
            return false;
        }

        $token->status = 0;
        $token->timemodified = time();
        $success = $DB->update_record('tool_teachermcp_tokens', $token);

        if ($success) {
            // M5: Trigger token_revoked event.
            \tool_teachermcp\event\token_revoked::create([
                'objectid' => $token->id,
                'relateduserid' => $token->userid,
                'context' => context_system::instance(),
            ])->trigger();
        }

        return $success;
    }

    /**
     * Get all tokens belonging to a teacher.
     *
     * @param int $userid User ID.
     * @return array List of token records.
     */
    public static function get_user_tokens(int $userid): array {
        global $DB;
        return $DB->get_records('tool_teachermcp_tokens', ['userid' => $userid], 'timecreated DESC');
    }
}
