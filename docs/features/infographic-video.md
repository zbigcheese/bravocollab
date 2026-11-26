# Feature spec: AI-generated animated infographic videos

Status: **MVP built 2026-11-26** (rendering option A, in-browser). This file is the reference for the feature.
Implementation notes live in `.claude/CLAUDE.md` → "Per-user features & infographic video".

Decisions taken while building the MVP:
- Access is per user via the admin "Feature access" matrix (`infographic_video`), not by role.
- Steps 2 and 3 of the pipeline run as **one** Claude call (footage tags come back inside the storyboard response;
  thumbnails ≤300 px are sent, and later regenerations reuse the stored tags instead of the images).
- Storyboard JSON uses `value_ref` / `a_ref` / `b_ref` / `segments_ref` plus `{{key}}` placeholders inside text;
  `format` per value is driven by the dataset unit (`seconds` | `number` | `percent`), not chosen by the model.
- Accent colour is BRAVO orange `#F16820`; font is Roboto Condensed (vendored, OFL).
- Dataset keys are generated as `<column>.<value>.<measure>.<sum|share|count>`, e.g. `club.sns.seconds.sum`.
- Acceptance check passed locally on the speeches CSV (title → area comparison → big numbers + ratio → spotlight
  with segmented bar); every number matched the hand calculation and the MP4 was H.264 1080×1920, 30 fps.

Not in the MVP yet: video-clip footage (images only), audio/TTS, captions, other aspect ratios, brand presets.

## Goal

Let a BravoCollab user turn **a topic + supplied information (data / notes / knowledge base) + footage or images**
into a short vertical **animated infographic video (MP4, 9:16, ~20–60 s)** ready for Instagram Reels / TikTok / Shorts.
The Claude API plans and writes the video. Deterministic code calculates the numbers and renders the frames.

## Style reference

Instagram reel by Mašina (masina.rs): "Ko koliko priča u Skupštini?" (reel `Df4od6UBt9L`),
https://www.instagram.com/reels/Df4od6UBt9L/. It covers how long each poslanički klub spoke on day 2 of the
constitutive session, 18 Nov 2026.

Use it for the **format and motion language only**. BRAVO output must use BRAVO's own branding, not Mašina's
logo, colors or ticker text.

What the reel is made of (scene inventory, observed frame by frame):

| # | Scene type | What it shows | Animation |
|---|---|---|---|
| 1 | **Title card** | Big 3-line headline, the last line in accent red ("Ko je koliko govorio / u Skupštini?"), small subtitle | Lines slide/fade in one by one |
| 2 | **Area comparison** | Two circles whose *area* is proportional to the value (SNS vs Studentska lista); legend "površina kruga = vreme govora" | Circles grow from 0; labels pop in |
| 3 | **Big-number stat** | Two labelled durations ("2 h 37 min 45 s" vs "23 min 49 s"), second one in accent color | Numbers count up |
| 4 | **Ratio callout** | Red pill: "SNS: 6,6 puta duže" | Slides in after the numbers settle |
| 5 | **Person spotlight** | Name headline + subtitle ("10 javljanja, šef kluba SNS"), "UKUPNO 1 h 40 min 3 s" | Count-up |
| 6 | **Segmented timeline bar** | One horizontal bar split into N blocks, where each block is one speech and its width is proportional to that speech's duration | Blocks fill left to right |

Persistent chrome on every scene:
- Brand logo top-left, red section tag top-right ("SKUPŠTINA")
- Date chip (red fill) + context chip (outlined): "18. novembar 2026." / "Konstitutivna sednica"
- Domain bottom-right, three red dots bottom-left (progress/brand mark)
- **Red news ticker** scrolling at the bottom
- **Background**: photo or footage, darkened (~70% black overlay), slowly zooming (Ken Burns), and swapped per scene

Typography: condensed bold sans (Roboto Condensed / Barlow Condensed style), white on dark, red accent.

## Core principle: Claude plans, code calculates and renders

1. **Claude never produces final numbers by itself.** The input data is parsed into a structured dataset in code.
   Claude's storyboard references data **by key** (e.g. `"value_ref": "clubs.SNS.total_seconds"`), and code resolves,
   sums, formats ("2 h 37 min 45 s") and computes ratios ("6,6 puta"). This keeps political content factually safe.
2. **Claude never writes free-form animation code at runtime.** It picks from a fixed library of scene templates and
   fills their parameters. That gives consistent quality, and every output looks on-brand.
3. **A human approves the storyboard before rendering.** Required for political/public content.

## Pipeline

```
[Input]  topic + data (CSV / JSON / pasted text / transcript) + notes + images/footage
   │
   ▼
[1. Normalize data]          code: parse into a dataset JSON (entities, values, units, sources)
   │
   ▼
[2. Tag footage]             Claude API (vision): short description + tags per image/clip → pick per scene
   │
   ▼
[3. Storyboard]              Claude API: topic + dataset summary + footage tags + style rules
   │                         → storyboard JSON (validated against schema)
   ▼
[4. Resolve + check]         code: resolve value_refs, compute ratios/formatting, validate lengths
   │
   ▼
[5. Human review]            UI: list of scenes, editable copy, swap footage, preview → Approve
   │
   ▼
[6. Render]                  renderer → MP4 (1080×1920, 30 fps) → saved as a card attachment
   │
   ▼
[Optional] TTS voiceover, music bed, burned-in captions
```

## Storyboard JSON (draft schema)

```json
{
  "meta": {
    "title": "Ko je koliko govorio u Skupštini?",
    "date_chip": "18. novembar 2026.",
    "context_chip": "Konstitutivna sednica",
    "section_tag": "SKUPŠTINA",
    "ticker": "Konstitutivna sednica Narodne skupštine · 15. saziv – u brojevima",
    "language": "sr-Latn",
    "fps": 30, "width": 1080, "height": 1920
  },
  "scenes": [
    { "type": "title", "duration_s": 3,
      "headline": ["Ko je koliko", "govorio"], "headline_accent": "u Skupštini?",
      "subtitle": "Drugi dan konstitutivne sednice", "background": "img_03" },

    { "type": "area_compare", "duration_s": 6,
      "headline": "Ukupno su govorili", "subtitle": "Zbir svih javljanja po poslaničkom klubu",
      "items": [
        { "label": "SNS", "value_ref": "clubs.SNS.total_seconds" },
        { "label": "SL",  "value_ref": "clubs.SL.total_seconds", "accent": true }
      ],
      "legend": "površina kruga = vreme govora", "background": "img_01" },

    { "type": "big_numbers", "duration_s": 4,
      "rows": [
        { "label": "SRBIJA POBEĐUJE (SNS)", "value_ref": "clubs.SNS.total_seconds", "format": "duration" },
        { "label": "STUDENTSKA LISTA", "value_ref": "clubs.SL.total_seconds", "format": "duration", "accent": true }
      ],
      "callout": { "kind": "ratio", "a_ref": "clubs.SNS.total_seconds", "b_ref": "clubs.SL.total_seconds",
                   "template": "SNS: {ratio} puta duže" } },

    { "type": "spotlight", "duration_s": 5,
      "headline": "Samo Aleksandar Vučić", "subtitle": "10 javljanja, šef kluba SNS",
      "value_label": "UKUPNO", "value_ref": "people.vucic.total_seconds", "format": "duration",
      "segments_ref": "people.vucic.speeches[].seconds", "background": "img_02" }
  ]
}
```

Scene template library for the MVP: `title`, `area_compare`, `big_numbers` (with optional `callout`),
`spotlight` (with optional segmented bar), `bar_ranking` (horizontal bars, top N), `quote` (pull quote + attribution),
`outro` (logo + CTA). Add more only when a real video needs them.

## Claude API usage

- Called **server-side from PHP** with curl (`https://api.anthropic.com/v1/messages`, headers `x-api-key`,
  `anthropic-version`). The key lives in `config/config.php` as `ANTHROPIC_API_KEY` (add the placeholder to
  `config.example.php`). Never expose it to the browser.
- **Storyboard call:** force schema-valid JSON by using a single tool whose `input_schema` is the storyboard schema,
  with `tool_choice` forcing that tool (or the API's structured-output option if available). Validate in PHP anyway.
- **Footage tagging:** send images as base64 image blocks. For video clips, extract 1–3 frames first.
- Prompt contents: brand/style rules, the scene library with each template's constraints (max headline chars, max items),
  the dataset **keys and summaries** (not raw transcripts if they're huge), footage tags, the topic, and the target length.
- Check docs.claude.com for current model IDs, pricing and limits before building. Don't hard-code from memory.
- Expected cost: well under $1 per video for the Claude calls.

## BravoCollab constraints that shape the design (from CLAUDE.md)

- **Shared cPanel hosting, PHP only, zero build step, no npm/Composer.** Node/Chromium rendering (Remotion etc.)
  **cannot run on the BravoCollab server.** Decide the rendering option below before coding.
- PHP request time limits on shared hosting (often ~30 s) may be shorter than a Claude call. Use an async job row
  + client polling (or streaming) instead of one blocking request. Note that `cron.php` runs only every 15 min,
  so it's too slow for interactive generation.
- New tables go in **both** `sql/schema.sql` and `core/SchemaUpgrades.php`.
- Font sizes in rem (text-scale accessibility); JS is vanilla, vendored libs go in `public/vendor/`.
- Uploads: reuse `FileUpload` / attachments for source images and for the rendered MP4. Add `video/mp4` to the
  allowed MIME list in `config/constants.php` if it isn't there.

### Rendering options (decide at kickoff)

| Option | How | Pros | Cons |
|---|---|---|---|
| **A. In-browser render** | Scenes drawn on `<canvas>` frame by frame from the storyboard. Encode with WebCodecs `VideoEncoder` + a vendored single-file MP4 muxer, then upload the MP4 back as an attachment | Fits zero-build + shared hosting; no extra server | Scenes must be canvas-drawn (not DOM); best on desktop Chrome/Edge; render speed depends on the user's device |
| **B. External render worker** | Separate small Node service (Remotion) on a VPS or a serverless renderer. BravoCollab posts the approved storyboard + asset URLs; the worker renders and calls back with the MP4 | Best motion quality, React-based templates, deterministic | Extra infrastructure + separate repo; check Remotion license terms for the org |
| **C. Hybrid** | Live DOM/CSS preview in BravoCollab for review, final render via B | Fast review UX | Two implementations of each scene to keep in sync |

Leaning: **A for the MVP** (no new infrastructure), keeping the storyboard schema renderer-agnostic so B can be
added later without changing steps 1–5.

## Suggested integration in BravoCollab

- Entry point: a **"Generate video"** action on a card. The card description and comments act as notes, and the card's
  image attachments are the footage pool. An optional data attachment (CSV/JSON/TXT) is the dataset.
- New table `video_jobs`: `id, card_id, user_id, status ENUM('draft','storyboarding','review','rendering','done','failed'),
  topic, dataset_json, footage_tags_json, storyboard_json, output_attachment_id, error, created_at, updated_at`.
- New controller `VideoController`: `create`, `storyboard` (calls Claude), `get`, `update_storyboard`, `approve`,
  `save_render` (receives the MP4), with board-membership checks like every other board-scoped endpoint.
- New JS `public/js/video_studio.js`: review UI (scene list, editable copy, footage swap, preview player, Render button).
- Publish an SSE event (`video_job_changed`) and a notification when the render finishes, following existing patterns.

## MVP scope

1. Dataset input: CSV or JSON upload (+ pasted text that Claude converts to the dataset, shown for confirmation).
2. Storyboard generation with the 7 scene templates above, in Serbian (Latin) by default.
3. Review/edit screen with preview.
4. Render to MP4 1080×1920 30 fps, no audio, saved to the card.

Later: TTS voiceover, music, captions, Cyrillic toggle, 1:1 and 16:9 formats, brand presets per board.

## Acceptance check for the MVP

Recreate the reference reel's structure from a small CSV of speeches (`speaker, club, start, end`): title →
area comparison → big numbers + ratio callout → spotlight with segmented bar. Every number on screen must match a
hand calculation from the CSV.
