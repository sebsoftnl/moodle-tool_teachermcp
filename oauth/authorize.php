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
 * OAuth 2.0 / 2.1 Authorization Endpoint for Teacher MCP.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();

// H1: Verify that user has connectagent capability in system or at least one course.
if (!\tool_teachermcp\auth::user_can_connect($USER->id)) {
    throw new moodle_exception('error_no_capability', 'tool_teachermcp');
}

$clientid = optional_param('client_id', '', PARAM_ALPHANUMEXT);
$redirecturi = optional_param('redirect_uri', '', PARAM_RAW);
$responsetype = optional_param('response_type', 'code', PARAM_ALPHA);
$state = optional_param('state', '', PARAM_RAW);
$scope = optional_param('scope', 'teachermcp', PARAM_TEXT);
$codechallenge = optional_param('code_challenge', '', PARAM_RAW);
$codechallengemethod = optional_param('code_challenge_method', '', PARAM_RAW);

// K3-R1, R2, R3: Strict validation BEFORE any consent screen or redirect.
// Throws moodle_exception on invalid client or invalid redirect URI (never redirects).
$client = \tool_teachermcp\oauth_server::validate_authorization_params(
    $clientid,
    $redirecturi,
    $responsetype,
    $codechallenge,
    $codechallengemethod
);
$scopeconfig = \tool_teachermcp\oauth_server::normalise_scope($scope);

$systemcontext = context_system::instance();
$pageurl = new moodle_url('/admin/tool/teachermcp/oauth/authorize.php', [
    'client_id' => $clientid,
    'redirect_uri' => $redirecturi,
    'response_type' => $responsetype,
    'state' => $state,
    'scope' => $scope,
    'code_challenge' => $codechallenge,
    'code_challenge_method' => $codechallengemethod,
]);

$PAGE->set_url($pageurl);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('oauth_authorize_title', 'tool_teachermcp'));
$PAGE->set_heading(get_string('oauth_authorize_title', 'tool_teachermcp'));

// Process authorization decision on form submit (POST with sesskey only, K3-R8).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $decision = optional_param('decision', 'deny', PARAM_ALPHA);

    if ($decision === 'allow') {
        $code = \tool_teachermcp\oauth_server::create_authorization_code(
            $USER->id,
            $clientid,
            $redirecturi,
            $codechallenge,
            $codechallengemethod,
            $scopeconfig['scope']
        );

        $params = ['code' => $code];
        if (!empty($state)) {
            $params['state'] = $state;
        }

        $targeturl = new moodle_url($redirecturi, $params);
        redirect($targeturl);
    } else {
        $params = [
            'error' => 'access_denied',
            'error_description' => 'User cancelled authorization request.',
        ];
        if (!empty($state)) {
            $params['state'] = $state;
        }

        $targeturl = new moodle_url($redirecturi, $params);
        redirect($targeturl);
    }
}

echo $OUTPUT->header();

$redirectparts = parse_url($redirecturi);
$redirecthost = $redirectparts['host'] ?? $redirecturi;
if (!empty($redirectparts['port'])) {
    $redirecthost .= ':' . $redirectparts['port'];
}

$server = new \tool_teachermcp\server();
$toolscount = count($server->get_tools());

$templatedata = [
    'client_name' => $client->clientname, // K3-R8: registered name from DB.
    'redirect_host' => $redirecthost,     // K3-R8: host of redirect URI (B6).
    'user_fullname' => fullname($USER),
    'user_email' => $USER->email,
    'tools_count' => $toolscount,
    'permissions' => \tool_teachermcp\oauth_server::get_consent_permissions($scopeconfig['permissions']),
    'sesskey' => sesskey(),
    'client_id' => $clientid,
    'redirect_uri' => $redirecturi,
    'state' => $state,
    'code_challenge' => $codechallenge,
    'code_challenge_method' => $codechallengemethod,
    'scope' => $scope,
    'site_name' => format_string($SITE->fullname),
];

echo $OUTPUT->render_from_template('tool_teachermcp/oauth_authorize', $templatedata);

echo $OUTPUT->footer();
