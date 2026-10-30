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
 * Dutch language strings for tool_teachermcp.
 *
 * @package    tool_teachermcp
 * @copyright  2026 Sebsoft BV
 * @author     Jeremy van Lier <jeremy@sebsoft.nl> and Sebastian Berm <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Docent MCP Agent Koppeling';
$string['teachermcp:connectagent'] = 'Persoonlijke AI-agent koppelen en beheren via Model Context Protocol (MCP)';
$string['teachermcp:manageall'] = 'Alle docent MCP-koppelingen site-breed beheren';

$string['dashboard_title'] = 'Docent AI Agent Assistent (MCP)';
$string['dashboard_desc'] = 'Verbind jouw lokale of cloud AI-assistent (zoals Claude Desktop, Codex, ChatGPT of Cursor) direct met jouw Moodle docentenaccount via het Model Context Protocol.';

$string['tokens_header'] = 'Actieve Agent Koppelingen';
$string['create_token'] = 'Nieuwe Agent Koppeling Genereren';
$string['agent_name'] = 'Agent Naam / Apparaat';
$string['agent_name_help'] = 'Bijv. "Werk Laptop Claude", "Thuis Desktop Codex"';
$string['token_created'] = 'Agent token succesvol gegenereerd. Kopieer het nu direct, want het wordt hierna nooit meer getoond!';
$string['token_revoked'] = 'Agent koppeling succesvol ingetrokken.';
$string['token_revoke_failed'] = 'De agentkoppeling kon niet worden ingetrokken. De koppeling bestaat mogelijk niet of je hebt geen toestemming.';
$string['validuntil'] = 'Geldig tot';
$string['lastaccess'] = 'Laatst actief';
$string['never'] = 'Nooit';
$string['status'] = 'Status';
$string['status_active'] = 'Actief';
$string['status_revoked'] = 'Ingetrokken';
$string['actions'] = 'Acties';
$string['revoke'] = 'Toegang Intrekken';
$string['copy_token'] = 'Kopieer Token';
$string['token_warning'] = 'Houd dit token geheim! Hiermee kan jouw agent docenthandelingen namens jou uitvoeren conform jouw Moodle-rechten.';

$string['tab_claude'] = 'Claude';
$string['tab_codex'] = 'HTTP-clients';
$string['tab_chatgpt'] = 'ChatGPT';
$string['tab_resources'] = 'Bronnen';
$string['tab_bridge'] = 'Stdio Bridge (Node.js)';

$string['config_instructions'] = 'Plak onderstaande configuratie in het configuratiebestand van je agent:';
$string['endpoint_url'] = 'Moodle MCP Endpoint URL';

// Setup & Tokens
$string['active_tools_count'] = '{$a} Actieve MCP Tools';
$string['copied'] = 'Gekopieerd!';
$string['copy_failed'] = 'Kopiëren mislukt. Selecteer de tekst en kopieer deze handmatig.';
$string['copy'] = 'Kopieer';
$string['copy_config'] = 'Kopieer Configuratie';
$string['copy_claude_config'] = 'Kopieer Claude Config';
$string['copy_codex_config'] = 'Kopieer Codex/Cursor Config';
$string['validity_period'] = 'Geldigheidsduur';
$string['validity_days'] = '{$a} dagen';
$string['token_validity_error'] = 'Kies een geldigheidsduur van 1 tot {$a} dagen. Er is geen token aangemaakt.';
$string['validity_30'] = '30 dagen';
$string['validity_90'] = '90 dagen (aanbevolen)';
$string['validity_180'] = '180 dagen (één semester)';
$string['validity_365'] = '1 jaar';
$string['validity_unlimited'] = 'Onbeperkt geldig';
$string['no_tokens_found'] = 'Je hebt nog geen actieve agent-koppelingen aangemaakt.';
$string['confirm_revoke'] = 'Weet je zeker dat je deze koppeling wilt intrekken? De gekoppelde agent verliest direct toegang.';
$string['back_to_course'] = 'Terug naar cursus';
$string['course_filter_label'] = 'Personaliseer voorbeeldprompts voor cursus:';
$string['course_filter_all'] = 'Selecteer een cursus om voorbeeldprompts te personaliseren';

// Guides
$string['how_to_connect'] = 'Verbind je AI-assistent';
$string['claude_desktop_title'] = 'Lokale bridge: handmatig bearer-token';
$string['claude_desktop_desc'] = 'Maak een token met het gewenste rechtenprofiel en gebruik daarna deze configuratie. Vervang YOUR_BEARER_TOKEN_HERE als er geen nieuw token wordt getoond. Houd het token geheim; deze methode gebruikt geen OAuth.';
$string['codex_cursor_title'] = 'Configuratie voor HTTP-clients';
$string['codex_cursor_desc'] = 'Voor clients die dit HTTP MCP-endpoint en een handmatig ingestelde bearer-header ondersteunen:';
$string['remote_app_intro'] = 'Gebruik een externe MCP-verbinding met aanmelden via de browser. Een lokale installatie, bridge-script of handmatig aangemaakt token is niet nodig.';
$string['remote_app_requirements'] = 'Deze Moodle-site moet voor de app bereikbaar zijn via openbaar HTTPS. Vraag de beheerder zo nodig OAuth-clientregistratie in te schakelen. Voer je Moodle-wachtwoord alleen in op de Moodle-aanmeldpagina, nooit in de chat of een client-secretveld.';
$string['remote_app_cached'] = 'Bij een bestaande autorisatie kan aanmelden of toestemming worden overgeslagen. Controleer de toegang met een echte get_my_profile-aanroep; alleen een lijst met tools bewijst niet dat de verbinding werkt.';
$string['copy_endpoint'] = 'Kopieer server-URL';
$string['open_claude_connectors'] = 'Open Claude-connectors';
$string['open_chatgpt_plugins'] = 'Open ChatGPT-plugins';
$string['open_claude_app'] = 'Open Claude-app';
$string['open_chatgpt_app'] = 'Open ChatGPT-app';
$string['claude_app_link_help'] = 'De desktopknop opent een nieuwe chat in de geïnstalleerde app, niet de connectorinstellingen. Ga daarna naar Customize > Connectors. Gebruik de webknop als de app niet opent.';
$string['chatgpt_app_link_help'] = 'De desktopknop opent een nieuwe lokale chat in de geïnstalleerde app, niet de plugincatalogus. Kies daarna Work of Codex > Plugins. Gebruik de webknop als de app niet opent.';
$string['cli_setup_title'] = 'Claude Code en Codex CLI';
$string['cli_setup_desc'] = 'Beide CLI\'s kunnen lokale stdio MCP-servers starten. Registreer de gedownloade bridge als servercommando met het hierboven getoonde Node-commando en de argumenten. Deze JSON-voorbeelden zijn voor desktopclients, niet voor CLI-syntaxis; volg de CLI-documentatie voor registratie. De volledige CLI-configuratie is hier nog niet geverifieerd.';
$string['claude_cli_docs'] = 'Claude Code CLI instellen';
$string['codex_cli_docs'] = 'Codex CLI instellen';
$string['http_config_cli_note'] = 'Dit JSON-voorbeeld is voor compatibele HTTP-clients zoals Cursor. Codex CLI gebruikt config.toml of codex mcp add. Zie Lokale bridge voor CLI-documentatie.';
$string['claude_app_title'] = 'Verbind de Claude-app';
$string['claude_app_step1'] = 'Open Customize > Connectors. Kies Add > Custom > Web of Add custom connector.';
$string['claude_app_step2'] = 'Geef de connector een naam en plak de hierboven getoonde server-URL.';
$string['claude_app_step3'] = 'Kies OAuth / Sign in now en Register automatically. Laat request headers leeg. Kies voor deze server niet de gepubliceerde identiteit van Claude.';
$string['claude_app_step4'] = 'Kies Add en daarna Connect als die knop verschijnt. Meld je zo nodig met je docentenaccount aan bij Moodle en controleer de gevraagde rechten.';
$string['claude_app_step5'] = 'Start een nieuwe chat. Schakel deze Moodle-connector in via + > Connectors en vraag om get_my_profile en get_teacher_courses aan te roepen.';
$string['claude_app_workspace'] = 'In een beheerde werkruimte moet een beheerder de connector mogelijk eerst toevoegen voordat docenten hun eigen Moodle-account kunnen koppelen.';
$string['chatgpt_title'] = 'Verbind ChatGPT met een externe MCP-server';
$string['chatgpt_step1'] = 'Open Plugins in ChatGPT op het web. Kies + > Add custom MCP server.';
$string['chatgpt_step2'] = 'Voer een naam in en plak de hierboven getoonde server-URL onder Connection.';
$string['chatgpt_step3'] = 'Kies OAuth en dynamische clientregistratie (DCR) als die optie beschikbaar is. Kies geen CIMD, gebruik geen Moodle-token als client secret en schakel authenticatie niet uit.';
$string['chatgpt_step4'] = 'Lees de waarschuwing en kies Create as a plugin. Meld je zo nodig aan bij Moodle en geef toestemming. Controleer daarna de gevonden tools en installeer de plugin.';
$string['chatgpt_step5'] = 'Start een nieuwe chat, typ @ en selecteer de plugin. Selecteer deze opnieuw wanneer een vervolgbericht een nieuwe toolaanroep nodig heeft.';
$string['chatgpt_remote_access'] = 'Beschikbaarheid hangt af van je abonnement en werkruimterechten. Volledige externe lees- en schrijftoegang is momenteel gedocumenteerd voor Business en Enterprise/Edu; Pro kan beperkt zijn tot lezen. Stel de verbinding in op het web. Gebruik deze alleen in de desktopapp als de verbinding daar beschikbaar is.';

// MCP Tool omschrijvingen.
$string['tool_get_my_profile_desc'] = 'Eigen docentenprofiel, systeemrechten en voorkeuren opvragen.';
$string['tool_get_teacher_courses_desc'] = 'Lijst met alle cursussen waarin de ingelogde docent doceert of bewerkrechten heeft.';
$string['tool_export_course_learning_material_desc'] = 'De volledige inhoudelijke lesstof (teksten, hoofdstukken, H5P interactieve modules, opdrachten, begrippenlijst en bijgevoegde documenten) ophalen als Markdown voor LLM-context.';
$string['tool_get_course_contents_desc'] = 'Secties, activiteiten, toetsen, opdrachten en leermiddelen van een cursus ophalen.';
$string['tool_scaffold_assessment_unit_desc'] = 'Volledige formatieve/summatieve toets aanmaken inclusief vragenbank, quiz-instellingen en sectieplaatsing.';
$string['tool_detect_at_risk_students_desc'] = 'Vroegtijdige signalering van uitvalrisico op basis van inactiviteit, deadlines, onvoldoendes en ontbrekende afrondingen.';
$string['tool_analyze_class_understanding_desc'] = 'Klassikale begrips- en toetsanalyse uitvoeren op basis van moeilijkheidsgraad, foute antwoorden en leergaten.';
$string['tool_prepare_grading_session_desc'] = 'Een beoordelingssessie voorbereiden met ingeleverde teksten, bestandsmetadata, bestaande cijfers en ingestelde rubric-criteria.';
$string['tool_manage_activity_desc'] = 'Basisinstellingen bekijken; paginanamen en beschrijvingen wijzigen of de zichtbaarheid van pagina, opdracht, toets, H5P en boek aanpassen. Gebruik Moodle voor andere instellingen.';
$string['tool_grade_submission_desc'] = 'Inzendingen van studenten beoordelen, cijfers invoeren en feedbackcommentaar leveren.';
$string['tool_manage_grades_desc'] = 'Cijferlijst en beoordelingsitems inzien en cijfers van individuele studenten bewerken.';
$string['tool_get_group_students_desc'] = 'Studenten in specifieke groepen of groeperingen opvragen waar de docent toegang toe heeft.';
$string['tool_manage_course_completion_desc'] = 'Voltooiingscriteria en voortgang van studenten inzien en controleren.';
$string['tool_send_student_message_desc'] = 'Direct een bericht sturen naar een student via het Moodle berichtensysteem.';
$string['tool_manage_question_bank_desc'] = 'Vragenbank en categorieën inzien, filteren en vragen beheren.';
$string['tool_duplicate_activity_desc'] = 'Een activiteit of leermiddel dupliceren binnen dezelfde cursus of naar een andere cursus.';
$string['tool_read_activity_file_desc'] = 'Bijgevoegde bronbestanden in een activiteit inspecteren, downloaden of inhoudelijk uitlezen (PDF, DOCX, PPTX, ODT, TXT, CSV) voor LLM-context.';

// File Handling
$string['download_source_file'] = 'Bronbestand downloaden';
$string['download_forbidden'] = 'Je hebt geen rechten om dit bestand te downloaden.';
$string['error_unauthorized'] = 'Ongeautoriseerde toegang: een geldige sessie of actief docent-token is vereist.';
$string['error_invalid_scope'] = 'De aangevraagde OAuth-scope wordt niet ondersteund.';
$string['error_remote_image_fetch_disabled'] = 'Externe afbeeldings-URL\'s zijn uitgeschakeld. Gebruik file_content_base64.';
$string['setting_max_mcp_request_bytes'] = 'Maximale MCP-aanvraaggrootte';
$string['setting_max_mcp_request_bytes_desc'] = 'Maximale geaccepteerde grootte van een JSON-RPC-aanvraag in bytes. Standaard: 1048576 (1 MiB). Waarden worden begrensd op 10485760 (10 MiB).';
$string['setting_max_mcp_batch_size'] = 'Maximale MCP-batchgrootte';
$string['setting_max_mcp_batch_size_desc'] = 'Maximum aantal JSON-RPC-calls in één batch. Standaard: 10. Waarden worden begrensd op 50.';
$string['setting_max_mcp_response_bytes'] = 'Maximale MCP-responsgrootte';
$string['setting_max_mcp_response_bytes_desc'] = 'Maximale grootte van een toolrespons aan een agent in bytes. Grotere resultaten worden afgekapt met een expliciete melding. Standaard: 262144 (256 KiB), maximum: 5242880 (5 MiB).';
$string['setting_mcp_requests_per_minute'] = 'MCP-aanvragen per minuut';
$string['setting_mcp_requests_per_minute_desc'] = 'Maximum aantal geauthenticeerde MCP-aanvragen per minuut voor elk token en bron-IP-adres. Standaard: 60, maximum: 600.';

// Risk detection flags
$string['flag_inactivity'] = 'Geen cursusactiviteit in {$a} dagen';
$string['flag_overdue_assignments'] = '{$a} te late of gemiste opdrachten';
$string['flag_low_grade'] = 'Laag cijfergemiddelde ({$a})';
$string['flag_low_grade_threshold'] = 'Cursuscijfer {$a->grade} ligt onder de toepasselijke waarschuwingsgrens {$a->threshold}; zie het oorspronkelijke cijferbereik en percentage.';
$string['flag_incomplete_activities'] = '{$a} verplichte onderdelen onvoltooid';
$string['flag_analytics_risk'] = 'Hoog uitvalrisico voorspeld door Moodle Analytics ({$a}%)';

// Prompt Bibliotheek
$string['prompt_library_title'] = 'Docenten Prompt Bibliotheek (Direct te gebruiken)';
$string['prompt_library_desc'] = 'Kopieer deze kant-en-klare opdrachten naar jouw gekoppelde agent (Claude, Cursor of Codex) voor directe ondersteuning in je dagelijkse onderwijspraktijk:';
$string['prompt_cat_context'] = 'Lesstof & Voorbereiding';
$string['prompt_cat_assessment'] = 'Toetsen & Vragen Assembleren';
$string['prompt_cat_analytics'] = 'Studentbegeleiding & Risicosignalering';
$string['prompt_cat_grading'] = 'Beoordelen & Feedback';

$string['prompt_badge_context'] = 'Context & Inhoud';
$string['prompt_badge_assessment'] = 'Toetsassemblage';
$string['prompt_badge_analytics'] = 'Zorgsignalen';
$string['prompt_badge_grading'] = 'Beoordelen';

$string['prompt_ingest_title'] = 'Complete Lesstof Inladen in AI Geheugen';
$string['prompt_ingest_desc'] = 'Laadt alle pagina\'s, hoofdstukken, H5P interactieve modules, opdrachten en bijgevoegde documenten in één keer in het werkgeheugen.';
$string['prompt_ingest_text'] = 'Haal met "export_course_learning_material" de complete lesstof op van cursus ID {$a->courseid} ("{$a->coursename}"). Geef een samenvatting van de 5 belangrijkste kernconcepten, licht per sectie toe wat de student moet beheersen en noem de uitgelezen bronbestanden.';

$string['prompt_h5p_title'] = 'H5P & Interactieve Modules Analyseren';
$string['prompt_h5p_desc'] = 'Onderzoek vragen, theorie en dialogen uit interactieve video\'s, presentaties en boeken.';
$string['prompt_h5p_text'] = 'Gebruik "export_course_learning_material" voor cursus ID {$a->courseid}. Analyseer specifiek de inhoud en vragen uit de aanwezige H5P-activiteiten en vertel me of alle kernbegrippen voldoende getoetst worden.';

$string['prompt_files_title'] = 'Bijgevoegde Bronbestanden Uitlezen & Toetsen';
$string['prompt_files_desc'] = 'Laat de agent geüploade PDF\'s, Word-documenten of presentaties inhoudelijk doorgronden.';
$string['prompt_files_text'] = 'Onderzoek de bijgevoegde documenten in cursus ID {$a->courseid} met "read_activity_file" of "export_course_learning_material". Formuleer 5 diepgaande toetsvragen die strikt gebaseerd zijn op de uitgelezen theorie in het bronbestand.';

$string['prompt_scaffold_title'] = 'Formatieve Toets Volledig Assembleren';
$string['prompt_scaffold_desc'] = 'Maakt in één handeling een toets aan in een specifieke sectie met 5 meerkeuzevragen en 1 open vraag inclusief feedback.';
$string['prompt_scaffold_text'] = 'Gebruik "scaffold_assessment_unit" om een formatieve toets van 5 meerkeuzevragen aan te maken in cursus ID {$a->courseid}, sectie 2. Baseer de vragen op de lesstof, geef bij elk fout antwoord een duidelijke didactische uitleg en houd de toets verborgen voor controle.';

$string['prompt_assignment_title'] = 'Opdracht met Beoordelingsrubriek Assembleren';
$string['prompt_assignment_desc'] = 'Maakt een rijke Moodle-opdracht (mod_assign) aan met inleverregels, deadlines en rubriekcriteria.';
$string['prompt_assignment_text'] = 'Gebruik "create_assignment" om een opdracht aan te maken in cursus ID {$a->courseid}, sectie 3 getiteld "Essay over AI-ethiek". Voeg gedetailleerde instructies toe, stel de inleverdeadline in over 14 dagen en voeg rubriekcriteria toe voor Argumentatie (50%) en Helderheid (50%).';



$string['prompt_calendar_title'] = 'Vragenuur of Herhalingsles Inplannen';
$string['prompt_calendar_desc'] = 'Plant een deadline of live bijeenkomst direct in op de Moodle cursuskalender.';
$string['prompt_calendar_text'] = 'Gebruik "manage_course_calendar" met action="create_event" in cursus ID {$a->courseid}. Plan een bijeenkomst van 60 minuten in voor morgen om 14:00 getiteld "Vragenuur ter voorbereiding op tentamen".';

$string['prompt_atrisk_title'] = 'Studenten met Uitvalrisico Opsporen';
$string['prompt_atrisk_desc'] = 'Berekent risicoscores op basis van inactiviteit, gemiste deadlines en cijfers.';
$string['prompt_atrisk_text'] = 'Voer "detect_at_risk_students" uit voor cursus ID {$a->courseid}. Welke studenten zijn inactief of hebben opdrachten gemist? Formuleer voor de 3 studenten met het hoogste risico een motiverend concept-bericht voor "send_student_message".';

$string['prompt_analytics_title'] = 'Begrips- en Foutenanalyse na een Toets';
$string['prompt_analytics_desc'] = 'Onderzoekt welke vragen klassikaal het slechtst gemaakt zijn en wat de onderliggende misconcepties zijn.';
$string['prompt_analytics_text'] = 'Voer "analyze_class_understanding" uit voor quiz CMID [vul_cmid_in]. Welke vragen hadden een slagingspercentage onder de 50% en welke misvattingen speelden een rol? Geef een voorstel voor 10 minuten herhaling tijdens het komende college.';

$string['prompt_grading_title'] = 'Batch-Beoordelingssessie Voorbereiden';
$string['prompt_grading_desc'] = 'Haalt alle ongecorrigeerde inzendingen en rubric-criteria op en genereert onderbouwde cijfersuggesties.';
$string['prompt_grading_text'] = 'Zet met "prepare_grading_session" de ongecorrigeerde inzendingen klaar voor opdracht CMID [vul_cmid_in]. Evalueer de ingeleverde teksten tegen de rubric-criteria en doe voor elke student een cijfersuggestie met constructieve feedback voor "grade_submission".';

// OAuth 2.0 PKCE Koppeling.
$string['oauth_authorize_title'] = 'AI Agent Verbinden (OAuth 2.0)';
$string['oauth_prompt'] = 'De applicatie <strong>{$a}</strong> vraagt toestemming om te verbinden met jouw Moodle docentenaccount via het Model Context Protocol (MCP).';
$string['oauth_account_scope_notice'] = 'Deze verbinding krijgt uitsluitend toegang tot cursussen en gegevens waar jouw account actieve docentrechten voor bezit.';
$string['oauth_permissions_header'] = 'Gevraagde rechten (momenteel ingeschakelde tools):';
$string['oauth_perm_read'] = 'Cursusinhoud, studentgegevens en onderwijsrapporten inzien';
$string['oauth_perm_write'] = 'Cursusinhoud, activiteiten, vragen en kalenderafspraken aanmaken en bewerken';
$string['oauth_perm_grade'] = 'Inzendingen beoordelen en cijfers en feedback vastleggen';
$string['oauth_perm_message'] = 'Berichten naar studenten sturen';
$string['oauth_perm_delete'] = 'Cursusactiviteiten verwijderen, na bevestiging';
$string['oauth_perm_external'] = 'De externe tool gebruiken: {$a}';
$string['oauth_perm_none'] = 'Geen van de gevraagde tools is momenteel ingeschakeld. De gevraagde rechten blijven gelden als deze tools later worden ingeschakeld.';
$string['oauth_perm_courses'] = 'Eigen cursussen, secties en activiteiten inzien';
$string['oauth_perm_content'] = 'Lesstof, boekteksten, H5P-modules en begrippen exporteren';
$string['oauth_perm_files'] = 'Bijgevoegde PDF\'s, documenten en presentaties uitlezen en analyseren';
$string['oauth_perm_assessment'] = 'Toetsen assembleren, vragenbanken opstellen en activiteiten dupliceren';
$string['oauth_perm_grading'] = 'Beoordelingssessies voorbereiden, inzendingen bekijken en feedback formuleren';
$string['oauth_allow'] = 'Verbinding Toestaan';
$string['oauth_deny'] = 'Weigeren & Annuleren';
$string['oauth_security_footer'] = 'Je kunt deze koppeling op ieder moment intrekken vanuit jouw Moodle Teacher MCP dashboard.';
$string['label_unverified_client'] = 'Niet-geverifieerde applicatie';
$string['desc_unverified_client'] = 'Deze applicatie is niet geverifieerd door de beheerder van deze Moodle site. Autoriseer alleen als je deze software vertrouwt.';
$string['oauth_redirect_destination'] = 'Tokens worden teruggestuurd naar: {$a}';
$string['oauth_tab_title'] = 'Lokale bridge';
$string['oauth_setup_title'] = 'Lokale bridge: aanmelden met OAuth';
$string['oauth_setup_desc'] = 'Voor desktop- of stdio-clients die een lokaal programma starten. Start de gedownloade bridge met --oauth om zo nodig bij Moodle aan te melden en toestemming te geven. De bridge gebruikt de vooraf geregistreerde lokale client; dynamische registratie en een handmatig aangemaakt bearer-token zijn niet nodig.';
$string['oauth_endpoints_title'] = 'OAuth 2.0 Server Endpoints';
$string['oauth_auth_endpoint'] = 'Autorisatie-endpoint';
$string['oauth_token_endpoint'] = 'Token-endpoint';
$string['oauth_metadata_endpoint'] = 'Discovery Metadata';
$string['claude_oauth_bridge_desc'] = 'Voeg deze configuratie toe aan claude_desktop_config.json met de --oauth vlag:';
$string['claude_manual_bridge_desc'] = 'Voeg deze configuratie toe aan claude_desktop_config.json met je handmatige token, niet met de --oauth vlag:';
$string['bridge_path_placeholder'] = '/ABSOLUUT/PAD/NAAR/mcp-bridge.mjs';
$string['download_bridge_title'] = '1. Download het bridge-script naar jouw computer';
$string['download_bridge_desc'] = 'Installeer Node.js 22.11 of nieuwer en sla mcp-bridge.mjs lokaal op. Vervang het voorbeeldpad in de configuratie hieronder door het werkelijke absolute pad op je computer:';
$string['download_bridge_btn'] = 'Download mcp-bridge.mjs';

// Bridge script (Node.js) strings for AMOS translation.
$string['bridge_missing_endpoint'] = 'Fout: Ontbrekend endpoint argument.';
$string['bridge_usage'] = 'Gebruik: node mcp-bridge.mjs <endpoint_url> <bearer_token|--oauth> [--lang=en|nl]';
$string['bridge_auth_failed'] = 'Authenticatie mislukt';
$string['bridge_invalid_code'] = 'Ongeldige autorisatiecode of state mismatch.';
$string['bridge_auth_success'] = 'Authenticatie geslaagd!';
$string['bridge_connected_desc'] = 'Jouw AI-assistent is succesvol verbonden met jouw Moodle Teacher MCP-account.';
$string['bridge_close_tab'] = 'Je kunt dit browsertabblad nu veilig sluiten en terugkeren naar je AI-app.';
$string['bridge_token_fetch_error'] = 'Fout bij ophalen van token';
$string['bridge_browser_opening'] = '[Moodle MCP OAuth] Browser wordt geopend voor autorisatie:';
$string['bridge_auth_error'] = '[Moodle MCP OAuth] Autorisatie mislukt:';
$string['bridge_comm_error'] = 'Bridge communicatiefout:';

// Content-activiteiten en media prompts.
$string['prompt_cat_content'] = 'Cursuscontent & Media';
$string['prompt_badge_content'] = 'Pagina\'s & Boeken';
$string['prompt_page_title'] = 'Rijke Content-pagina met Illustraties Maken';
$string['prompt_page_desc'] = 'Genereert een responsieve HTML-pagina met tussenkoppen, callouts en diagrammen.';
$string['prompt_page_text'] = 'Gebruik "manage_content_activity" om een Pagina te maken in cursus ID {$a->courseid} in sectie 1 getiteld "Introductie tot Neurale Netwerken". Zorg voor duidelijke kopjes, een samenvatting in een callout-kader en overzichtelijke opmaak.';
$string['prompt_book_title'] = 'Gestructureerd Moodle Boek met Hoofdstukken Bouwen';
$string['prompt_book_desc'] = 'Creëert een Moodle Boek-activiteit met meerdere subhoofdstukken voor progressief studeren.';
$string['prompt_book_text'] = 'Bouw een Boek in cursus ID {$a->courseid} sectie 2 getiteld "Software Architectuur Patronen". Maak Hoofdstuk 1: Introductie, Hoofdstuk 2: Layered Architecture, en Hoofdstuk 3: Microservices.';
$string['prompt_image_title'] = 'Gegenereerde Afbeelding Uploaden en Insluiten';
$string['prompt_image_desc'] = 'Slaat een AI-gegenereerd diagram of schema op in cursusmedia en sluit deze in.';
$string['prompt_image_text'] = 'Upload het bijgevoegde diagram naar cursus ID {$a->courseid} als "cloud_topology.png" via "upload_course_asset". Maak vervolgens een Pagina in sectie 3 waarin deze afbeelding visueel is ingesloten.';

// H5P interactieve activiteiten prompts.
$string['prompt_cat_h5p'] = 'Interactieve H5P Activiteiten';
$string['prompt_badge_h5p'] = 'H5P Builder';
$string['prompt_h5p_inspect_title'] = 'Geïnstalleerde H5P Bibliotheken & Schema\'s Inspecteren';
$string['prompt_h5p_inspect_desc'] = 'Toont alle beschikbare H5P content-types en hun exacte JSON schema.';
$string['prompt_h5p_inspect_text'] = 'Voer "get_h5p_libraries" uit met include_schema_examples=true. Welke interactieve content-types zijn geïnstalleerd op deze Moodle site en wat is het parameterschema voor Flashcards en Multiple Choice?';
$string['prompt_h5p_flashcards_title'] = 'Interactieve H5P Flashcards Oefening Bouwen';
$string['prompt_h5p_flashcards_desc'] = 'Plaatst een volwaardige H5P Flashcard-set direct in de cursus en Content Bank.';
$string['prompt_h5p_flashcards_text'] = 'Gebruik "create_h5p_activity" om interactieve Flashcards te bouwen in cursus ID {$a->courseid}, sectie 1 getiteld "Biologie Begrippentrainer" met bibliotheek "H5P.Flashcards". Voeg 5 definitiekaarten toe over Cel, Mitochondriën, Ribosoom, DNA en Osmose.';
$string['prompt_h5p_quiz_title'] = 'Interactieve H5P Multiple Choice Quiz Bouwen';
$string['prompt_h5p_quiz_desc'] = 'Publiceert een interactieve H5P vraag met didactische feedback per antwoord.';
$string['prompt_h5p_quiz_text'] = 'Gebruik "create_h5p_activity" om een interactieve "H5P.MultiChoice" te plaatsen in cursus ID {$a->courseid}, sectie 2 getiteld "Begripstoetsing". Voorzie de vraag van 4 antwoordopties, feedback per optie en activeer de oplossingsknop.';

// Cursusbeheer en veiligheid prompts.
$string['prompt_cat_maintenance'] = 'Cursusonderhoud & Veiligheid';
$string['prompt_badge_maintenance'] = 'Gevaarlijke Acties';
$string['prompt_delete_title'] = 'Veilig Verwijderen van Verouderde Activiteit (2-Staps Bevestiging)';
$string['prompt_delete_desc'] = 'Gebruikt de gevaarlijke delete_activity tool met veilige inspectie vooraf.';
$string['prompt_delete_text'] = 'Inspecteer activiteit cmid [vul_cmid_in] met "delete_activity". Toon mij eerst de activiteitdetails en eventuele waarschuwingen. Verwijder pas als ik expliciet akkoord geef; gebruik daarna de UI-bevestigingsflow of, wanneer UI-bevestiging is uitgeschakeld, de confirm_token uit de preview.';

// Agent Skill UI strings.
$string['agent_skill_title'] = 'AI Agent Systeemprompt & Vaardigheden (Skill)';
$string['agent_skill_subtitle'] = 'Kopieer deze systeemprompt of download de skill-specificatie in Claude Desktop, Custom GPT of Cursor zodat jouw AI-agent exact weet hoe alle tools optimaal gebruikt moeten worden.';
$string['copy_skill_prompt'] = 'Kopieer Agent Instructies';
$string['download_skill_file'] = 'Download Skill (.md)';
$string['tab_agent_instructions'] = 'Agentinstructies';

// Admin settings and security messages.
$string['manage_tokens'] = 'Agent-verbindingen Beheren';
$string['setting_max_token_validity'] = 'Maximale tokengeldigheid (dagen)';
$string['setting_max_token_validity_desc'] = 'Maximaal toegestane geldigheid in dagen bij het aanmaken van agent-tokens. Niet-positieve waarden vallen terug op 90 dagen.';
$string['setting_oauth_registration_enabled'] = 'Dynamische OAuth-clientregistratie Inschakelen';
$string['setting_oauth_registration_enabled_desc'] = 'Sta AI-clients toe zich dynamisch te registreren via het RFC 7591 registratie-endpoint.';
$string['setting_max_oauth_tokens_per_client'] = 'Maximaal aantal actieve OAuth-tokens per client';
$string['setting_max_oauth_tokens_per_client_desc'] = 'Maximaal aantal gelijktijdige actieve OAuth-tokens per gebruiker voor een specifieke client. Oudere actieve tokens worden automatisch ingetrokken wanneer deze limiet wordt overschreden.';
$string['setting_message_ratelimit_per_hour'] = 'Berichten-limiet (per uur)';
$string['setting_message_ratelimit_per_hour_desc'] = 'Maximaal aantal directe berichten dat een agent per uur per token/gebruiker kan versturen.';
$string['setting_allowed_cors_origins'] = 'Toegestane CORS Origins';
$string['setting_allowed_cors_origins_desc'] = 'Lijst van origins die cross-origin verzoeken mogen sturen naar het MCP-endpoint (één per regel, bijv. https://chatgpt.com).';
$string['setting_allow_delete_questions'] = 'Vragen verwijderen toestaan';
$string['setting_allow_delete_questions_desc'] = 'Indien ingeschakeld, kunnen AI-agents en docenten vragen uit de vragenbank verwijderen via MCP-tools (onder voorbehoud van rechten en bevestigingstoken).';
$string['error_delete_questions_disabled'] = 'Het verwijderen van vragen via MCP-tools is uitgeschakeld door de beheerder.';
$string['error_invalid_confirmation_token'] = 'Het bevestigingstoken is ongeldig of gemanipuleerd.';
$string['error_confirmation_token_expired'] = 'Het bevestigingstoken is verlopen. Vraag een nieuw bevestigingstoken aan.';
$string['message_subject_from'] = 'Bericht van {$a}';
$string['error_no_capability'] = 'Je beschikt niet over de vereiste rechten om deze actie uit te voeren.';
$string['error_no_group_access'] = 'Je hebt geen toegang tot deze student of groep.';
$string['error_not_enrolled'] = 'Student is niet actief ingeschreven in deze cursus.';
$string['error_cannot_message_user'] = 'Kan geen bericht sturen naar deze gebruiker vanwege contactinstellingen.';
$string['error_rate_limited'] = 'Rate limit overschreden. Probeer het later opnieuw.';
$string['error_invalid_request'] = 'Ongeldige verzoekparameters.';
$string['error_invalid_user'] = 'Ongeldige doelgebruiker.';
$string['error_invalid_action'] = 'Niet-ondersteunde actie: {$a}';
$string['error_activity_settings_restricted'] = 'Deze instellingen kunnen niet worden toegepast op module {$a}. Pagina\'s ondersteunen wijzigingen van naam, beschrijving en zichtbaarheid. Opdracht, toets, H5P en boek ondersteunen alleen zichtbaarheid: stuur uitsluitend cmid, action en visible. Gebruik Moodle voor andere instellingen.';
$string['h5p_no_matching_libraries'] = 'Geen ingeschakelde, uitvoerbare H5P-bibliotheken komen overeen met dit filter. Verwijder of wijzig library_filter om beschikbare inhoudstypen te bekijken.';
$string['h5p_no_available_libraries'] = 'Er zijn geen ingeschakelde, uitvoerbare H5P-bibliotheken beschikbaar op deze site. Ga naar Sitebeheer > H5P > H5P-inhoudstypen beheren om inhoudstypen te installeren of in te schakelen.';
$string['error_item_not_found'] = 'Item niet gevonden.';
$string['error_invalid_params'] = 'Ongeldige parameters: {$a}';
$string['error_assignment_submission_required'] = 'Er moet minstens één inlevermethode zijn ingeschakeld: online tekst of bestandsuploads. Offline of mondelinge opdrachten worden momenteel niet ondersteund door deze tool.';
$string['error_grade_locked'] = 'Beoordelingsitem is vergrendeld.';
$string['error_feedback_files_disabled'] = 'Feedbackbestanden zijn uitgeschakeld of niet beschikbaar voor deze opdracht. Schakel Feedbackbestanden in Moodle in voordat je bestanden toevoegt. Cijfer, feedback en bestanden zijn niet gewijzigd.';
$string['error_feedback_files_invalid'] = 'Feedbackbestanden moeten een lijst met unieke, veilige bestandsnamen en geldige base64-inhoud zijn. Gebruik geen paden, dubbele namen of ontbrekende velden. Het volledige beoordelingsverzoek is geweigerd.';
$string['error_feedback_file_too_large'] = 'Een feedbackbestand overschrijdt de toepasselijke uploadlimiet van {$a} bytes. Het volledige beoordelingsverzoek is geweigerd.';
$string['error_feedback_files_not_saved'] = 'Moodle heeft niet alle gevraagde feedbackbestanden opgeslagen in de verwachte bestandsruimte van de beoordeling. De beoordeling is teruggedraaid; controleer of deze opdracht gewone feedbackbestanden ondersteunt.';
$string['error_grade_overridden'] = 'Het cijfer in het cijferboek is overschreven. Het opdrachtcijfer en de feedback zijn niet gewijzigd. Controleer de overschrijving in Moodle voordat je opnieuw beoordeelt.';
$string['error_grade_not_saved'] = 'Moodle heeft het gevraagde opdrachtcijfer niet voor alle betrokken studenten opgeslagen. De beoordeling is teruggedraaid. Controleer de beoordelingsworkflow en beoordelaarsrechten.';
$string['warning_gradebook_differs'] = 'Het opdrachtcijfer verschilt van het effectieve cijfer in het cijferboek, of er is een actieve overschrijving. Controleer de teruggegeven cijferboekwaarden; ga er niet van uit dat het voor de student zichtbare cijfer is gewijzigd.';
$string['error_grade_out_of_range'] = 'Cijfer valt buiten het toegestane bereik.';
$string['error_invalid_token'] = 'Ongeldig of ingetrokken agent-authenticatietoken.';
$string['error_expired_token'] = 'Agent-authenticatietoken is verlopen.';
$string['error_invalid_client'] = 'Ongeldige clientauthenticatie of onbekende client.';
$string['error_unsupported_response_type'] = 'Het opgevraagde responstype wordt niet ondersteund.';
$string['error_unsupported_grant_type'] = 'Het autorisatietype wordt niet ondersteund.';
$string['error_invalid_grant'] = 'Het verstrekte autorisatiebewijs is ongeldig, verlopen of reeds gebruikt.';
$string['error_registration_disabled'] = 'Dynamische clientregistratie is uitgeschakeld door de beheerder.';
$string['event_token_created'] = 'Teacher MCP-token aangemaakt';
$string['event_token_revoked'] = 'Teacher MCP-token ingetrokken';
$string['event_tool_executed'] = 'Teacher MCP-tool uitgevoerd';
$string['task_cleanup'] = 'Verlopen Teacher MCP-autorisatiecodes en tokens opschonen';

// Admin dashboard strings (L7).
$string['stat_active_tokens'] = 'Actieve agent-koppelingen';
$string['stat_total_tokens'] = 'Totaal uitgegeven tokens';
$string['btn_teacher_dashboard'] = 'Naar docentendashboard';
$string['col_teacher'] = 'Docent';
$string['col_agent_name'] = 'Agent-label';
$string['col_token'] = 'Token';
$string['col_created'] = 'Aangemaakt';
$string['col_last_active'] = 'Laatst actief';
$string['col_status'] = 'Status';
$string['col_actions'] = 'Acties';
$string['btn_revoke'] = 'Intrekken';
$string['no_tokens_site'] = 'Er zijn nog geen agent-koppelingen aangemaakt op deze Moodle installatie.';

// Blok 5 error strings.
$string['error_params_too_deep'] = 'Parameters overschrijden de maximale geneste diepte van 20 niveaus.';
$string['error_invalid_image_format'] = 'Ongeldig afbeeldingsformaat. Alleen PNG-, JPEG-, GIF- en WEBP-afbeeldingen zijn toegestaan.';
$string['error_file_too_large'] = 'Bestandsgrootte overschrijdt de maximaal toegestane uploadlimiet.';
$string['error_invalid_url_scheme'] = 'Ongeldig URL-schema. Alleen beveiligde HTTPS-URL\'s zijn toegestaan.';
$string['error_fetch_url'] = 'Kon afbeelding niet ophalen van externe URL.';
$string['error_invalid_base64'] = 'Ongeldige base64-gegevens opgegeven.';
$string['error_library_not_found'] = 'Gevraagde H5P-bibliotheek is niet gevonden of is uitgeschakeld op deze site.';
$string['error_create_zip'] = 'Aanmaken van tijdelijk H5P zip-archief is mislukt.';
$string['error_h5p_save_failed'] = 'H5P-inhoud kon niet op de gevraagde bestemming worden opgeslagen. Er is geen activiteit of Content Bank-item aangemaakt.';

// Blok 5: Delete safeguards, UI confirmation and tool scopes.
$string['setting_enable_external_tools'] = 'Extern bijgedragen MCP-tools inschakelen';
$string['setting_enable_external_tools_desc'] = 'Staat onafhankelijk geïnstalleerde Moodle-plugins toe om gevalideerde Teacher MCP-tools te publiceren. Elke providertool blijft verborgen totdat zowel deze instelling als de provider-eigen instelling is ingeschakeld.';
$string['setting_enable_write_tools'] = 'Auteurs- en schrijftools inschakelen';
$string['setting_enable_write_tools_desc'] = 'Biedt AI-agents de mogelijkheid om cursusinhoud, activiteiten, vragenbankitems en voltooiingscriteria aan te maken of te bewerken.';
$string['setting_enable_grade_tools'] = 'Beoordelingstools inschakelen';
$string['setting_enable_grade_tools_desc'] = 'Biedt AI-agents de mogelijkheid om te ondersteunen bij het beoordelen van inzendingen en rubric-evaluaties.';
$string['setting_enable_messaging_tool'] = 'Berichtentool voor studenten inschakelen';
$string['setting_enable_messaging_tool_desc'] = 'Biedt AI-agents de mogelijkheid om namens de docent rechtstreeks berichten te sturen naar studenten.';
$string['setting_enable_delete_tool'] = 'Verwijdertool voor activiteiten inschakelen';
$string['setting_enable_delete_tool_desc'] = 'Biedt AI-agents de mogelijkheid om verwijdering van cursusactiviteiten aan te vragen. Indien uitgeschakeld kan de delete_activity tool niet worden uitgevoerd.';
$string['setting_delete_requires_ui_confirmation'] = 'Browser UI-bevestiging verplicht voor activiteitverwijdering';
$string['setting_delete_requires_ui_confirmation_desc'] = 'Indien ingeschakeld (aanbevolen), kunnen AI-agents activiteiten niet autonoom verwijderen. Ze moeten de docent een link sturen om de verwijdering handmatig in Moodle te bevestigen.';
$string['confirm_delete_title'] = 'Verwijdering Activiteit Bevestigen';
$string['confirm_delete_heading'] = 'Activiteit Definitief Verwijderen';
$string['confirm_delete_warning'] = 'Je staat op het punt deze activiteit definitief te verwijderen. Alle bijbehorende inzendingen, cijfers en voltooiingsgegevens worden gewist. Deze actie kan niet ongedaan worden gemaakt tenzij de prullenbak actief is.';
$string['confirm_delete_button'] = 'Activiteit Definitief Verwijderen';
$string['activity_name'] = 'Activiteit';
$string['activity_type'] = 'Activiteitstype';
$string['recyclebin_status'] = 'Prullenbak';
$string['recyclebin_active_notice'] = 'Cursusprullenbak is ACTIEF. Verwijderde activiteiten kunnen binnen de bewaartermijn worden hersteld.';
$string['recyclebin_inactive_warning'] = 'Cursusprullenbak is NIET actief. Deze activiteit en alle bijbehorende gegevens worden direct definitief gewist.';
$string['activity_deleted_success'] = 'Activiteit "{$a}" is succesvol verwijderd.';
$string['delete_requires_ui_confirmation_desc'] = 'Geautomatiseerde verwijdering door AI-agents is geblokkeerd door het sitebeleid. De docent moet deze verwijdering handmatig bevestigen via de bevestigings-URL.';
$string['error_tool_disabled'] = 'Tool "{$a}" is uitgeschakeld door de sitebeheerder.';
$string['error_tool_not_permitted'] = 'Tool "{$a}" is niet toegestaan voor dit agent-token.';
$string['error_tool_not_found'] = 'Tool "{$a}" niet gevonden.';
$string['error_group_access_denied'] = 'U heeft geen toegang tot deze groep of gescheiden groepen beperkt de weergave van deze groep.';

$string['external_tool_grants'] = 'Externe MCP-toolrechten';
$string['external_tool_grants_desc'] = 'Selecteer elke onafhankelijk aangeboden tool die dit token mag gebruiken. Deze rechten komen boven op de ingebouwde Teacher MCP-tools.';

// Privacy metadata strings (M5).
$string['privacy:metadata:tool_teachermcp_tokens'] = 'Authenticatietokens uitgegeven voor teacher MCP-agentverbindingen.';
$string['privacy:metadata:tool_teachermcp_tokens:userid'] = 'De ID van de gebruiker die eigenaar is van het token.';
$string['privacy:metadata:tool_teachermcp_tokens:name'] = 'Een beschrijvend label of naam voor het token.';
$string['privacy:metadata:tool_teachermcp_tokens:clientid'] = 'Het OAuth client-ID dat het token heeft aangevraagd of beheert.';
$string['privacy:metadata:tool_teachermcp_tokens:tokenlastchars'] = 'De laatste acht tekens waarmee het token wordt geïdentificeerd.';
$string['privacy:metadata:tool_teachermcp_tokens:lastip'] = 'Het IP-adres van waaruit het token voor het laatst is gebruikt.';
$string['privacy:metadata:tool_teachermcp_tokens:lastaccess'] = 'Het tijdstip van de meest recente API-toegang met dit token.';
$string['privacy:metadata:tool_teachermcp_tokens:timeexpires'] = 'Het tijdstip waarop het token verloopt.';
$string['privacy:metadata:tool_teachermcp_tokens:timecreated'] = 'Het tijdstip waarop het token is gegenereerd.';
$string['privacy:metadata:tool_teachermcp_tokens:timemodified'] = 'Het tijdstip waarop het token voor het laatst is gewijzigd.';
$string['privacy:metadata:tool_teachermcp_tokens:status'] = 'Of het token actief of ingetrokken is.';

$string['privacy:metadata:tool_teachermcp_oauth_codes'] = 'Tijdelijke autorisatiecodes uitgegeven tijdens OAuth 2.0 PKCE-authenticatie.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:clientid'] = 'Het OAuth client-ID dat autorisatie aanvraagt.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:userid'] = 'De gebruiker die autorisatie verleent aan de client.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:redirecturi'] = 'De redirect-URI gebruikt tijdens het autorisatieverzoek.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:scope'] = 'De gevraagde autorisatiescopes van de client.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:timecreated'] = 'Het tijdstip waarop de autorisatiecode is uitgegeven.';
$string['privacy:metadata:tool_teachermcp_oauth_codes:timeexpires'] = 'De verloopdatum van de autorisatiecode.';

$string['privacy:metadata:course_assets'] = 'Cursusbestanden geüpload door de gebruiker via MCP-tools.';
$string['privacy:metadata:tool_teachermcp_assets'] = 'Registreert bestanden die Teacher MCP maakt, zodat deze correct kunnen worden geëxporteerd en verwijderd.';
$string['privacy:metadata:tool_teachermcp_assets:userid'] = 'De gebruiker die het bestand uploadde.';
$string['privacy:metadata:tool_teachermcp_assets:contextid'] = 'De Moodle-context waarin het bestand staat.';
$string['privacy:metadata:tool_teachermcp_assets:fileid'] = 'Het Moodle-bestandsrecord van het bestand.';
$string['privacy:metadata:tool_teachermcp_assets:timecreated'] = 'Het tijdstip waarop het bestand is gemaakt.';

// Privacy export subcontext labels.
$string['tokens'] = 'Toegangstokens';
$string['oauth_codes'] = 'OAuth-autorisatiecodes';
$string['course_assets'] = 'Cursusbestanden';
$string['revoked'] = 'Ingetrokken';

// Token role presets and MCP resources.
$string['scope_preset'] = 'Machtigingsprofiel / Rol';
$string['scope_preset_help'] = 'Profielen beperken MCP-acties; Moodle-rechten en site-instellingen blijven gelden. Alleen-lezen omvat ook voltooiingsrapporten.';
$string['scope_preset_full'] = 'Volledige Co-Pilot (Lezen, Schrijven, Nakijken, Berichten, Verwijderen)';
$string['scope_preset_grading'] = 'Nakijkassistent (Inzendingen inzien, Cijfers & Feedback geven)';
$string['scope_preset_curriculum'] = 'Onderwijsontwerper (Lesstof lezen, Pagina\'s, Toetsen & Opdrachten bouwen)';
$string['scope_preset_readonly'] = 'Alleen Lezen / Tutor (Alleen syllabus en cursusinhoud doorgronden)';
$string['mcp_resources_title'] = 'Dynamische MCP Resources (Alleen Lezen)';
$string['mcp_resources_desc'] = 'Gestandaardiseerde URI\'s waarmee AI-agents actuele cursusstructuur, mededelingen en rubrieken direct kunnen inlezen.';
$string['prompt_cat_communication'] = 'Cursuscommunicatie';
$string['prompt_badge_communication'] = 'Forums & Berichten';
$string['prompt_cat_calendar'] = 'Planning & Agenda';
$string['prompt_badge_calendar'] = 'Kalender & Deadlines';
