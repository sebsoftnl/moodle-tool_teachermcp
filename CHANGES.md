# Changes Log - tool_teachermcp (Ronde 3 Audit)

## Blok 1: Blokkerende bugs

### B1. `require_course_access` / `require_cm_access` met meerdere capabilities
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/base_tool.php`
- **Wat er is veranderd**:
  - Methodesignaturen uitgebreid naar `string|array|null $capability = null`.
  - Wanneer een array aan capabilities wordt doorgegeven, wordt elke capability sequentieel via `require_capability()` gecontroleerd en afgedwongen in de betreffende context.
- **Afwijkingen**: Geen.

### B2. `quiz_add_quiz_question` aanroep vóór `require_once`
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/manage_question_bank.php`
- **Wat er is veranderd**:
  - `require_once($CFG->dirroot . '/mod/quiz/locallib.php')` direct bovenaan de `execute()` methode geplaatst.
- **Afwijkingen**: Geen.

### H6-rest. `scaffold_assessment_unit` werkend maken
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/scaffold_assessment_unit.php`
- **Wat er is veranderd**:
  - Quiz-modulecreatie voorzien van alle vereiste site-defaults conform Moodle quiz generator en `quiz_process_options()`: `quizpassword = ''`, `subnet = ''`, `browsersecurity = '-'`, `delay1 = 0`, `delay2 = 0`, `completionminattempts = 0`, `completionattemptsexhausted = 0`, `canredoquestions = 0`, `shufflequestions = 0`, review options en boundary feedback structures.
  - Gewaarborgd dat invalid section en invalid question fouten direct falen en dat de delegated transaction geen achtergebleven records achterlaat.
- **Afwijkingen**: Geen.

### T1 (Blok 1 Tests)
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - Echte data-generator unit tests toegevoegd voor B1 (`test_b1_require_course_access_multiple_capabilities`), B2 (`test_b2_manage_question_bank_quiz_slots`), en H6-rest (`test_h6_scaffold_assessment_unit_success`, `test_h6_scaffold_assessment_unit_rollback_on_invalid`).
- **Afwijkingen**: Geen.

## Blok 2: OAuth verbeteringen

### B5. Atomaire code-exchange & token-tracking
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/oauth_server.php`
  - `admin/tool/teachermcp/classes/auth.php`
  - `admin/tool/teachermcp/db/install.xml`
  - `admin/tool/teachermcp/db/upgrade.php`
- **Wat er is veranderd**:
  - Code-inwisseling voorzien van atomaire check-and-set claim via een unieke `claimtoken` en SQL update-guard (`WHERE id = :id AND status = 0`) vóórdat het token wordt gegenereerd. Concurrent requests falen direct met `error_invalid_grant`.
  - `auth::generate_token_record()` toegevoegd om het tokenrecord direct inclusief record-ID terug te geven, zodat `issuedtokenid` betrouwbaar wordt vastgelegd op de verbruikte code.
  - Bij hergebruik van een verbruikte autorisatiecode (`status !== 0`) wordt het via `issuedtokenid` gekoppelde token direct ingetrokken.
- **Afwijkingen**: Geen.

### B6. Consent-scherm bestemming & verificatiestatus
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/oauth/authorize.php`
  - `admin/tool/teachermcp/templates/oauth_authorize.mustache`
  - `admin/tool/teachermcp/db/install.xml`
  - `admin/tool/teachermcp/db/install.php`
  - `admin/tool/teachermcp/db/upgrade.php`
  - `admin/tool/teachermcp/lang/en/tool_teachermcp.php`
  - `admin/tool/teachermcp/lang/nl/tool_teachermcp.php`
- **Wat er is veranderd**:
  - Kolom `isverified` (int 1, default 0) toegevoegd aan `tool_teachermcp_oauth_clients`. Claude Desktop is gemarkeerd als geverifieerd (`isverified = 1`).
  - Template `oauth_authorize.mustache` uitgebreid met een duidelijke waarschuwingsbanner voor niet-geverifieerde clients (`desc_unverified_client`) en weergave van de doelhost van de redirect-URI (`oauth_redirect_destination`).
  - Nieuwe taalsleutels gedefinieerd in zowel `lang/en` als `lang/nl`.
- **Afwijkingen**: Geen.

### B7. `oauth_registration_enabled` setting
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/settings.php`
  - `admin/tool/teachermcp/classes/oauth_server.php`
  - `admin/tool/teachermcp/db/upgrade.php`
- **Wat er is veranderd**:
  - Instellingsnaam geüniformeerd naar `tool_teachermcp/oauth_registration_enabled` met defaultwaarde `0` (uitgeschakeld per default voor hardening).
  - `oauth_server::register_client()` geüpdatet om deze setting te controleren.
  - Upgrade-stap toegevoegd in `db/upgrade.php` om eventuele legacy `enable_oauth_registration` instellingen automatisch te migreren.
- **Afwijkingen**: Geen.

### B8. Loopback URI validatie per URI
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/oauth_server.php`
  - `admin/tool/teachermcp/db/install.php`
- **Wat er is veranderd**:
  - Helper `oauth_server::is_loopback_uri()` toegevoegd en `register_client()` geüpdatet om per URI de loopback-status op te slaan (`['uri' => ..., 'loopback' => bool]`).
  - `validate_authorization_params()` valideert dynamische poorten per individuele loopback URI conform RFC 8252 §7.3, waardoor clients met een mix van loopback- en HTTPS-redirects correct worden afgehandeld.
- **Afwijkingen**: Geen.

### B11. Meerdere apparaten per client (FIFO token-capping)
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/auth.php`
  - `admin/tool/teachermcp/settings.php`
  - `admin/tool/teachermcp/db/upgrade.php`
  - `admin/tool/teachermcp/lang/en/tool_teachermcp.php`
  - `admin/tool/teachermcp/lang/nl/tool_teachermcp.php`
- **Wat er is veranderd**:
  - Oude gedrag waarbij alle actieve tokens van dezelfde client blind werden ingetrokken vervangen door een configureerbare limiet (`max_oauth_tokens_per_client`, default 5).
  - Bij overschrijding van de limiet worden volgens FIFO alleen de oudste actieve tokens voor die specifieke client en gebruiker ingetrokken.
- **Afwijkingen**: Geen.

### M8-rest. RFC 9728 Protected Resource metadata issuer alignment
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/oauth/protected-resource.php`
  - `admin/tool/teachermcp/classes/oauth_server.php`
- **Wat er is veranderd**:
  - `issuer` in `oauth_server::get_metadata()` en `authorization_servers` in `protected-resource.php` gestandaardiseerd op `$CFG->wwwroot . '/admin/tool/teachermcp/oauth'`, zodat resource servers en autorisatieserver identiek naar elkaar verwijzen.
- **Afwijkingen**: Geen.

### K3-R10. Volledige OAuth testsuite
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - Unit tests toegevoegd voor:
    - `test_oauth_registration_disabled`
    - `test_oauth_registration_validation_and_mixed_uris`
    - `test_oauth_registration_rate_limiting`
    - `test_oauth_authorization_params_validation_and_mixed_matching`
    - `test_oauth_token_exchange_and_reuse_revocation`
    - `test_oauth_atomic_claim_race_condition`
    - `test_oauth_max_tokens_per_client_fifo`
- **Afwijkingen**: Geen.

## Blok 3: IDOR's en Schrijfacties

### N1. `manage_content_activity`: Module-type & Context Validatie
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/manage_content_activity.php`
  - `admin/tool/teachermcp/classes/tools/base_tool.php`
- **Wat er is veranderd**:
  - `require_cm_access()` uitgebreid met module-type validatie parameter (`page` / `book`), zodat cross-module type poisoning (bijvoorbeeld een page cmid meegeven aan `add_book_chapter`) onmogelijk is.
  - Specifieke module-capabilities afgedwongen in de modulecontext: `mod/book:edit` voor chapter-toevoegingen en wijzigingen.
  - Boek-events `\mod_book\event\chapter_created` en `chapter_updated` worden correct getriggerd en `book_preload_chapters()` vernieuwt de book cache.
- **Afwijkingen**: Geen.

### B3. `update_question` Cross-Course Category Guard
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/manage_question_bank.php`
- **Wat er is veranderd**:
  - Bij `action_update_question` wordt een meegegeven `category_id` gevalideerd tegen de context van de huidige cursus (`catcoursecontext->id == $context->id`).
  - Capabilities `moodle/question:moveall` (of `movemine` voor eigen vragen) worden afgedwongen op zowel bron- als doelcontext.
- **Afwijkingen**: Geen.

### B4. Quiz Attempts Guard & Shortanswer Strings
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/manage_question_bank.php`
- **Wat er is veranderd**:
  - `action_add_to_quiz` en `action_create_question` (met `quiz_id`) controleren via `quiz_has_attempts($quiz->id)` of er al studentpogingen bestaan en weigeren wijzigingen met `cannoteditafterattempts`. Tevens wordt `mod/quiz:manage` in de modulecontext gecontroleerd.
  - Bij `update_question` voor `shortanswer` worden antwoorden als plain text strings opgeslagen (`clean_param($opt['answer'], PARAM_TEXT)`) in plaats van geneste editor arrays.
- **Afwijkingen**: Geen.

### Blok 3 Tests
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - Tests toegevoegd:
    - `test_n1_manage_content_activity_module_type_and_events`
    - `test_b3_update_question_cross_course_category_forbidden`
    - `test_b4_quiz_has_attempts_and_shortanswer_update`
- **Afwijkingen**: Geen.

## Blok 4: Toegang voor Docenten

### H2. `moodle/course:view` Vervangen door Actieve Inschrijving
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/base_tool.php`
  - `admin/tool/teachermcp/classes/tools/export_course_learning_material.php`
  - `admin/tool/teachermcp/classes/tools/get_course_contents.php`
  - `admin/tool/teachermcp/classes/tools/get_group_students.php`
  - `admin/tool/teachermcp/classes/tools/read_activity_file.php`
  - `admin/tool/teachermcp/classes/tools/manage_content_activity.php`
- **Wat er is veranderd**:
  - `base_tool::require_course_access()` controleert nu `is_enrolled($context, $USER->id, '', true)` (strikt actieve inschrijvingen) of `moodle/course:view` (voor site managers).
  - De vijf tools vereisen nu de juiste docentrechten:
    - `export_course_learning_material`, `get_course_contents`, `read_activity_file`: `moodle/course:manageactivities`.
    - `get_group_students`: `moodle/course:viewparticipants`.
    - `manage_content_activity::get_content`: `moodle/course:manageactivities`.
- **Afwijkingen**: Geen.

### Blok 4 Tests
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - Test toegevoegd:
    - `test_h2_teacher_course_access_and_suspended_enrolment`
- **Afwijkingen**: Geen.

## Blok 5: Payload Limits & Asset Uploads

### Payload Limits & H5P Hardening
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/export_course_learning_material.php`
  - `admin/tool/teachermcp/classes/tools/create_h5p_activity.php`
- **Wat er is veranderd**:
  - `export_course_learning_material`: exportlimiet verhoogd van 200 KB naar 750 KB (`750 * 1024` bytes) inclusief waarschuwingen en truncatieberichten.
  - `create_h5p_activity`:
    - JSON-parameterlimiet verhoogd van 500 KB naar 2,5 MB (`2621440` bytes).
    - Recursieve dieptecontrole toegevoegd (`check_params_depth()`, max 20 niveaus, gooit `error_params_too_deep`).
    - Exacte machinename matching op `{h5p_libraries}` via `machinename = :machinename` (geen LIKE query).
    - `make_request_directory()` gebruikt voor tijdelijke zip-archieven in plaats van `make_temp_directory()`.
    - Fouten bij het opslaan in de Content Bank worden opgevangen en geretourneerd als waarschuwing (`warning`) in plaats van de uitvoering te laten crashen.
- **Afwijkingen**: Geen.

### N4. Veilige Asset Upload & File Serving
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/upload_course_asset.php`
  - `admin/tool/teachermcp/lib.php`
  - `admin/tool/teachermcp/lang/en/tool_teachermcp.php`
  - `admin/tool/teachermcp/lang/nl/tool_teachermcp.php`
- **Wat er is veranderd**:
  - `upload_course_asset`:
    - Alleen PNG, JPEG, GIF en WEBP toegestaan via extensie en `getimagesizefromstring()`. SVG, HTML, JS en overige typen worden geweigerd (`error_invalid_image_format`).
    - Bestandsgrootte begrensd door `get_max_upload_file_size()` en absolute harde limiet van 10 MB. Base64-groottecontrole vóór het decoderen (`strlen * 0.75`).
    - Downloaden via HTTPS-only URL's via `\curl` (timeout 15s, 3 redirects, string keys, 10 MB max size). Externe URL wordt niet gelekt in foutmeldingen (`error_fetch_url`).
    - Bestandsnaamconflicten automatisch opgelost met `_1`, `_2` zonder bestanden te overschrijven.
    - Ondersteuning voor `target_cmid` routing naar `mod_page` (`content`, itemid 0) of `mod_book` (`chapter`, chapter ID).
    - Capabilities `moodle/course:manageactivities` en `moodle/course:managefiles` afgedwongen.
  - `tool_teachermcp_pluginfile`:
    - Vereist `contextlevel == CONTEXT_COURSE` en `require_course_login($course)`.
    - Alleen veilige rasterafbeeldingen (PNG, JPEG, GIF, WEBP) worden inline geserveerd; overige met `$forcedownload = true`. Sluit af met `return false`.
  - Taalbestanden: Engelse en Nederlandse vertalingen toegevoegd voor alle Blok 5 foutmeldingen.
- **Afwijkingen**: Geen.

### Blok 5 Tests
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - Tests toegevoegd:
    - `test_blok5_export_learning_material_limit`
    - `test_blok5_h5p_parameter_depth_and_size_limits`
    - `test_blok5_upload_course_asset_and_pluginfile`
- **Afwijkingen**: Geen.

### N3 & N6: Verwijder-veiligheidsmaatregelen, UI-bevestiging en Tool Scopes
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/delete_activity.php`
  - `admin/tool/teachermcp/confirm_delete.php`
  - `admin/tool/teachermcp/settings.php`
  - `admin/tool/teachermcp/classes/server.php`
  - `admin/tool/teachermcp/classes/tools/prepare_grading_session.php`
  - `admin/tool/teachermcp/classes/tools/base_tool.php`
  - `admin/tool/teachermcp/lang/en/tool_teachermcp.php`
  - `admin/tool/teachermcp/lang/nl/tool_teachermcp.php`
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - `delete_activity`:
    - Server-side previewstap en cryptografische 10-minuten HMAC-SHA256 tokens (`confirm_token`) geïmplementeerd met replay-beveiliging via `get_cache_flag` / `set_cache_flag`.
    - Capability `moodle/course:manageactivities` afgedwongen in de modulecontext via `require_cm_access()`.
    - Controle op status van de cursusprullenbak (`\tool_recyclebin\course_bin::is_enabled()`) geretourneerd in preview response.
    - Admin-setting `delete_requires_ui_confirmation` (default 1): weigert geautomatiseerde verwijdering door AI-agents en retourneert een gestructureerd antwoord met de URL naar de UI-bevestigingspagina `confirm_delete.php`.
    - Indien `delete_requires_ui_confirmation` is uitgeschakeld (0), wordt verwijdering uitsluitend uitgevoerd na validatie van het 2-staps bevestigingstoken via `course_delete_module($cm->id)`.
  - `confirm_delete.php`:
    - Nieuwe dedicated webpagina voor handmatige bevestiging van activiteitverwijdering door docenten.
    - Controleert `require_login($course, false, $cm)`, `require_capability('moodle/course:manageactivities', $context)` en CSRF-beveiliging met `confirm_sesskey()`.
    - Toont waarschuwingen, modulenaam, type, cursus en prullenbakstatus in Moodle Boost UI styling.
    - Voert bij bevestiging `course_delete_module()` en cacheheropbouw uit en stuurt met een succesnotificatie door naar de cursuspagina.
  - `settings.php` & `server.php` (N6 Tool Scopes & Permissions):
    - Admin-instellingen toegevoegd: `enable_write_tools` (default 1), `enable_grade_tools` (default 1), `enable_messaging_tool` (default 1), `enable_delete_tool` (default 1), `delete_requires_ui_confirmation` (default 1).
    - Vaste scope-mapping gedefinieerd (`read`, `write`, `grade`, `message`, `delete`) voor alle tools.
    - `get_tools()` filtert uitgeschakelde tools uit de MCP-catalogus (`tools/list`).
    - `execute_tool()` en JSON-RPC `tools/call` weigeren uitvoering van uitgeschakelde tools met `error_tool_disabled`.
    - Token-specifieke permissions en scopes worden gevalideerd via `server::is_tool_allowed_by_token()`. Onbevoegde aanroepen worden geweigerd met `error_tool_not_permitted`.
    - `base_tool` voorzien van `public string $name` en `server::get_tools()` compatibel gemaakt met zowel statische als instantie-aanroepen.
  - `prepare_grading_session.php`:
    - Ruwe studentteksten geïsoleerd in de property `untrusted_student_content` met de expliciete waarschuwingsmelding tegen AI prompt injection. Tevens ingekapseld in `<untrusted_student_content>` tags in `online_text`.
  - Taalbestanden en Tests:
    - Alle Nederlandse en Engelse vertaalsleutels toegevoegd.
    - Uitgebreide PHPUnit-tests toegevoegd voor UI-bevestigingsflow, HMAC tokenvalidatie en replay-preventie, admin scope toggles, token permissies en anti-prompt-injection isolatie.
- **Afwijkingen**: Geen.

## Blok 6: Afronding, Performance & Isolatie

### B12. MUC Caching & Event-driven Invalidation voor `user_can_connect`
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/auth.php`
  - `admin/tool/teachermcp/db/caches.php`
  - `admin/tool/teachermcp/db/events.php`
  - `admin/tool/teachermcp/classes/observer.php`
- **Wat er is veranderd**:
  - `auth::user_can_connect(int $userid)` gecached via MUC in `user_connect_capability` (mode `cache_store::MODE_APPLICATION`, TTL 300 seconden) om herhaalde dure capability queries per API request te voorkomen.
  - MUC cache definitie toegevoegd aan `db/caches.php`.
  - Event observers geregistreerd in `db/events.php` voor `\core\event\role_assigned`, `\core\event\role_unassigned` en `\core\event\capability_assigned`.
  - Observer klasse `tool_teachermcp\observer` invalideert direct de cache van de desbetreffende gebruiker of het volledige cache-domein bij permissiewijzigingen.
- **Afwijkingen**: Geen.

### M2 / L4. Groepsisolatie in Analyse- en Detectietools
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/tools/detect_at_risk_students.php`
  - `admin/tool/teachermcp/classes/tools/analyze_class_understanding.php`
  - `admin/tool/teachermcp/lang/en/tool_teachermcp.php`
  - `admin/tool/teachermcp/lang/nl/tool_teachermcp.php`
- **Wat er is veranderd**:
  - In cursussen met `SEPARATEGROUPS` wordt gecontroleerd of de docent `moodle/site:accessallgroups` bezit.
  - Zonder deze capability worden uitsluitend de groepen van de docent opgevraagd (`groups_get_user_groups()`).
  - Als de docent een niet-toegestane `group_id` meegeeft, wordt de toegang geweigerd met `error_group_access_denied`.
  - Zowel studentenlijsten als quizpogingen worden strikt gefilterd tot de leden van de toegestane groepen van de docent.
- **Afwijkingen**: Geen.

### M5-rest. Privacy API Implementatie
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/classes/privacy/provider.php`
- **Wat er is veranderd**:
  - Metadata gedefinieerd voor `tool_teachermcp_tokens` (userid, name, clientid, lastip, lastaccess, timecreated, timemodified), `tool_teachermcp_oauth_codes` en de filearea `course_assets`.
  - Export en verwijdering per context en gebruiker geïmplementeerd conform Moodle core Privacy API standaarden.
- **Afwijkingen**: Geen.

### Blok 6 Tests
- **Gewijzigde bestanden**:
  - `admin/tool/teachermcp/tests/mcp_test.php`
- **Wat er is veranderd**:
  - Tests toegevoegd:
    - `test_blok6_user_can_connect_muc_cache_and_invalidation`
    - `test_blok6_group_isolation_separate_groups`
    - `test_blok6_privacy_provider`
- **Afwijkingen**: Geen.
