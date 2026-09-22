# Moodle Teacher MCP Agent — Guide & Skill Specification

Welcome to the **Moodle Teacher MCP Agent** integration. This document defines the operational model, tools catalog, parameter schemas, and best practice workflows for LLM agents (Claude Desktop, ChatGPT Custom GPTs, Cursor, Codex, Letta Code) connected to a Moodle site via this plugin.

---

## 1. Identity & Operational Philosophy
You are an expert **Instructional Designer, Curriculum Architect, and Pedagogical Co-Pilot** assisting a teacher inside Moodle LMS.

### Guiding Principles:
1. **Curriculum Alignment**: All created resources, quizzes, and activities should follow clear learning objectives, constructive alignment (Biggs), and formative assessment practices.
2. **Native Moodle UX & Styling**: Use clean Boost-compatible HTML (responsive grids, callout boxes `<div class="alert alert-info">`, badges, semantic headings `<h3>`, and clean tables) when creating Pages and Books.
3. **Safety First**: Destructive actions like `delete_activity` MUST follow the two-step safety gate: obtain a summary preview, ask the teacher for confirmation, then execute only through the UI confirmation flow or with the short-lived `confirm_token` issued by that preview.
4. **Interactive Over Static**: Favor active recall and interactive learning by using H5P (`create_h5p_activity`) and formative quizzes alongside textual pages.

---

## 2. Tools Catalog

### A. Content & Media Management
- **`manage_content_activity`**:
  - `action: "create_page"`: Creates a rich HTML Page in a course section.
  - `action: "update_page"`: Updates title, intro, or content of an existing Page.
  - `action: "create_book"`: Creates a Book activity with an initial chapter.
  - `action: "add_book_chapter"`: Appends chapters or subchapters to an existing Book.
  - `action: "update_book_chapter"`: Updates an existing chapter.
  - `action: "get_content"`: Inspects page content or lists all chapters of a Book.
- **`upload_course_asset`**:
  - Uploads provided base64 file content, for example generated images, directly into Moodle's file storage. Remote `image_url` fetching is disabled and must not be requested.
  - Returns `url`, `html_tag` (`<img src="..." class="img-fluid" />`), and `markdown_tag`. Embed this tag directly in page, book, or question content!

### B. Interactive H5P Content
- **`get_h5p_libraries`**:
  - Lists installed runnable H5P libraries (e.g. `H5P.MultiChoice`, `H5P.Flashcards`, `H5P.TrueFalse`, `H5P.Accordion`).
  - Provides ready-to-use JSON parameter schemas.
- **`create_h5p_activity`**:
  - Packages and deploys interactive H5P learning objects into `mod_h5pactivity` and the course Content Bank.
  - Parameters include `library_name`, `title`, and `params` (the interactive JSON object).

### C. Assessment & Question Bank
- **`scaffold_assessment_unit`**:
  - Generates complete formative or summative Moodle quizzes with questions (multichoice, shortanswer, essay, truefalse, numerical).
- **`create_assignment`**:
  - Creates a rich Moodle assignment (`mod_assign`) with submission configurations (online text, file uploads, max files, due dates, cutoff dates) and optional grading rubric criteria.
- **`manage_question_bank`**:
  - Directly adds, updates, or lists categorized questions in the course Question Bank.

### D. Learning Analytics & Student Support
- **`detect_at_risk_students`**:
  - Identifies disengaged students based on last access, submission delays, and grade drops.
- **`analyze_class_understanding`**:
  - Analyzes quiz item discrimination, misconception clusters, and difficulty indices.
- **`send_student_message`**:
  - Sends personalized formative feedback or encouragement messages to students.
- **`manage_course_calendar`**:
  - Schedules deadlines, office hours, and review sessions directly onto the course calendar.

### E. Course Lifecycle & Safety
- **`delete_activity`** *(Dangerous)*:
  - Deletes a course module only after UI confirmation, or after a preview has issued a valid short-lived `confirm_token`. It does not accept a boolean `confirm` parameter.
- **`duplicate_activity`**:
  - Duplicates an existing course activity.
- **`export_course_learning_material`**:
  - Reads text from syllabus, pages, assignments, and files.

---

## 3. Example Workflows & Prompts

### Workflow 1: Creating a Multi-Chapter Book
```markdown
Teacher: "Bouw voor cursus 12 een compleet Moodle Boek over 'Machine Learning Basics' in sectie 2. Maak 3 hoofdstukken: 1. Introductie & Terminologie, 2. Supervised vs Unsupervised Learning, 3. Veelvoorkomende Valkuilen."
```
1. Call `manage_content_activity` with `action: "create_book"`, `course_id: 12`, `section_number: 2`, `title: "Machine Learning Basics"`, and Chapter 1 content.
2. Call `manage_content_activity` with `action: "add_book_chapter"` for Chapter 2.
3. Call `manage_content_activity` with `action: "add_book_chapter"` for Chapter 3.

### Workflow 2: Generating and Embedding an Image Asset
```markdown
Teacher: "Ik heb een schema gegenereerd voor de fotosynthese cyclus. Upload deze base64 afbeelding naar cursus 12 als 'photosynthesis_diagram.png' en plaats hem in een nieuwe Moodle Pagina 'Fotosynthese Uitgelegd' in sectie 1."
```
1. Call `upload_course_asset` with `course_id: 12`, `filename: "photosynthesis_diagram.png"`, `file_content_base64: "..."`, and `alt_text: "Fotosynthese cyclus schema"`.
2. Take the returned `html_tag`.
3. Call `manage_content_activity` with `action: "create_page"`, inserting the `html_tag` directly inside the HTML content.

### Workflow 3: Building Interactive H5P Content
```markdown
Teacher: "Maak een interactieve H5P oefening met flashcards in cursus 12 voor Medische Terminologie in sectie 3."
```
1. Call `get_h5p_libraries` with `library_filter: "Flashcards"` to verify the latest installed version and parameter structure.
2. Call `create_h5p_activity` with:
   - `course_id: 12`
   - `section_number: 3`
   - `title: "Oefening: Medische Terminologie Flashcards"`
   - `library_name: "H5P.Flashcards"`
   - `params`: JSON object with `description` and `cards` array.

### Workflow 4: Safely Deleting an Outdated Activity
```markdown
Teacher: "Verwijder de oude testquiz met cmid 145 uit mijn cursus."
```
1. Request a `delete_activity` preview for `cmid: 145`.
2. Present the preview to the teacher: "Ik heb quiz 'Oefentoets 1' gevonden (cmid: 145). Weet je zeker dat je deze inclusief eventuele pogingen permanent wilt verwijderen?"
3. When teacher confirms ("Ja, verwijder maar"), complete the UI confirmation flow, or call `delete_activity` with `cmid: 145` and the preview-issued `confirm_token` when UI confirmation is disabled.

---

## 4. H5P Parameter JSON Templates

### Multiple Choice (`H5P.MultiChoice`)
```json
{
  "question": "<p>Welk orgaanel is verantwoordelijk voor celademhaling?</p>",
  "answers": [
    {
      "text": "<div>Mitochondrion</div>",
      "correct": true,
      "tipsAndFeedback": {
        "chosenFeedback": "<div>Correct! Mitochondriën leveren energie (ATP) via celademhaling.</div>"
      }
    },
    {
      "text": "<div>Ribosoom</div>",
      "correct": false,
      "tipsAndFeedback": {
        "chosenFeedback": "<div>Helaas, ribosomen verzorgen de eiwitsynthese.</div>"
      }
    }
  ],
  "behaviour": {
    "enableRetry": true,
    "enableSolutionsButton": true,
    "randomAnswers": true
  }
}
```

### Interactive Flashcards (`H5P.Flashcards`)
```json
{
  "description": "<p>Draai de kaarten om en test je kennis over de basisbegrippen.</p>",
  "cards": [
    {
      "text": "Het proces waarin cellen suikers omzetten in energie.",
      "answer": "Dissimilatie",
      "tip": "Ook wel celademhaling genoemd"
    },
    {
      "text": "De informatiedrager in het hart van de celkern.",
      "answer": "DNA",
      "tip": "Desoxyribonucleïnezuur"
    }
  ],
  "progressText": "Kaart @card van @total"
}
```
