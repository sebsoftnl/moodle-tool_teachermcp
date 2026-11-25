Version 1.0.3.1 (build 2026112501)
* CI: skip the 10 MiB upload boundary test when Moodle's effective upload limit is 10 MiB or lower. Plugin upload behavior and CI configuration are unchanged.

Version 1.0.3 (build 2026112500)
* Security: enforce exact OAuth redirect URI matching and authorization-code token binding; allow only the port to vary for registered HTTP loopback callbacks, including IPv6 [::1].
* Security / migration: manual stdio bridge connections now require --token-file with a protected token file instead of a raw positional token. Update existing client configurations and revoke/replace tokens previously exposed in process arguments or client JSON. OAuth connections are unchanged.
* Localisation: add English and Dutch cache names and human-readable MCP/OAuth messages, preserving protocol identifiers and RFC-required ASCII error descriptions; add language and protocol regression tests.
* Licensing: include a canonical root LICENSE in source packages and complete GPL/copyright/author headers for bridge scripts, styles and templates.
* CI: add GitHub Actions for Moodle plugin checks and bridge tests, and accept numeric as well as v-prefixed tags in the CAMP release workflow.
* Maintenance: resolve selected Moodle codechecker/PHPDoc warnings, including language ordering, test coverage metadata and redundant internal guards; add OAuth redirect and bridge credential regression tests.

Version 1.0.2.1 (build 2026112401)
* Enable correct publication to Marketplace

Version 1.0.2 (build 2026112400)
* Fixed connecting to Claude in Nginx
* Fixing of generated H5P file names to no longer include wrong characters

Version 1.0.1 (build 2026111700)
* Fixed support for CAMP.
* Added missing license file.
