# Testing and Qualification

## Plugin checks

From the plugin checkout, with PHP, Bash, Python 3 and Node.js available:

```sh
bash verify.sh
node --test bridge/*.test.mjs
```

`verify.sh` checks PHP syntax, XMLDB XML, language-key parity, retained regression-file discovery, existing static policies, bridge syntax/tests and a local bearer smoke exchange. It discovers additional test files instead of requiring an exact file count. These checks do not replace Moodle integration tests or a browser workflow.

Use a disposable, configured Moodle PHPUnit installation with a dedicated test prefix and data directory. From its checkout root, run the entire plugin suite:

```sh
php vendor/bin/phpunit --configuration phpunit.xml \
  --testsuite tool_teachermcp_testsuite \
  --log-junit /tmp/tool-teachermcp-junit.xml
```

Prepare PHPUnit according to the selected Moodle version before this command. Never point test configuration at production data. Run shared-database test targets serially. Check the process exit, raw JUnit errors/failures/skips and executed method IDs, not just a console summary. Keep provider declaration, grants, enum and opaque-argument regressions intact.

## Locally exercised boundary

The stabilization matrix uses these exact development checkouts:

| Target | Core commit | PHP |
|---|---|---|
| Moodle 4.5.0 minimum compatibility boundary | `52c0da7c647bd6ba8c5f61882d88959821a1fb41` | 8.1.33 |
| Moodle 5.3 development snapshot | `42622298fe06f9626d988d60b2bf589bd8f850e8` | 8.4.11 |

Both use MariaDB 11.4.13. The local matrix runs isolated fixtures and native teacher/student browser, HTTP and stdio workflows. Its fixed catalog contains 24 built-ins, 24 advertised actions, 15 prompts, one static resource and three resource templates. Actions require positive and relevant negative assertions. The full PHPUnit suite contains 280 methods, including eight numerical-authoring regressions. The pinned 5.3 runner reports 10 inherited PHPUnit deprecations and one notice. Preserve those diagnostics rather than suppressing them.

The plugin checkout's standalone verifier does not contain the maintainer's complete Docker/browser orchestration. A successful verifier or doctor command alone is not that matrix. Qualification applies only to the tested source and environment; changes require fresh relevant evidence.

## Workflow checks

- Connect and discover as a real teacher, create hidden content, inspect teacher/student views, explicitly publish, reconnect, revoke and verify denial without affecting another connection.
- Exercise question creation/attachment in the native question-bank context for each version, rollback and student attempt/teacher review.
- For numerical questions, check exact/default tolerance, interval boundaries, zero/negative/scientific answers and partial credit. Verify native versioning, preservation of omitted answers and existing units/hints/files, explicit answer replacement, and rejection before mutation. Run a real student attempt to confirm the stored marks, not only direct grading calls.
- Execute the [teacher guide](../../AGENTS.md) Book, image/Page, installed local H5P and preview/confirmation workflows. Check actual rendering and stored outcomes, not only HTTP200.
- Preserve grade/group/course boundaries, existing native rubric reading and the stored Moodle prediction contribution. Never use external delivery, prediction training or an AI service as a substitute for assertions.
- Test malformed input, permission denials, limits, cancellation and cleanup failure. A failed or incomplete cleanup is a failed run. Do not weaken a test, hide a skipped method or reduce the catalog to obtain success.

H5P browser qualification uses the core's local `filltheblanks.h5p` fixture with H5P.Blanks 1.12. Popular-library parameter examples are not proof that every library/version is installed or qualified. File extraction is bounded and includes explicit unsupported/limited outcomes, not OCR or general PDF interpretation.

## Limits

No commercial AI client, private service or paid account is certified by these local checks. Native Windows execution, other Moodle/PHP combinations, full accessibility conformance, production upgrades and predictive accuracy are not inferred. PHP8.1 is an intentional minimum-boundary test here, not a production recommendation; follow the selected Moodle release's supported runtime requirements.

Numerical authoring is limited to numeric answers with absolute tolerance, native credit fractions and feedback; unit authoring and calculated questions are not included. Server-side discrimination/misconception clustering remains unsupported. See the [compatibility policy](compatibility-policy.md) for the unchanged provider API v1 boundary.
