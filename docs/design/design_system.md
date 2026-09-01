# Mnemon — Design System

> **Dated design rationale.** This records the visual system as designed. It is
> not maintained as a description of the current templates — check the Blade
> views before relying on any detail here.

> **Treatise No. 001 — On the Furnishing of Memory**
> Vol. I · Foundational

A self-contained design language for Mnemon: the self-hosted second brain
for AI agents. Aesthetic name: **Ars Memoriae** — Renaissance treatise meets
developer infrastructure.

---

## 1. Principles

The system is governed by five principles. When in doubt, return to them.

1. **Treatise, not a SaaS landing page.** Type is the protagonist. Layouts
   read like printed pages: numbered sections, hairline rules, marginalia,
   classical figures. We avoid hero illustrations, gradient blobs, rounded
   feature cards, and emoji.
2. **One accent, used like rubric.** Vermilion appears the way rubricated
   ink appears in a medieval manuscript — for section numbers, drop caps,
   pinned points, sealed status, the occasional ornamental glyph. Never
   for ambient decoration. If everything is red, nothing is.
3. **Hairlines, not boxes.** Boundaries are drawn with single-pixel rules
   at low opacity. No drop shadows, no pill cards, no "elevation". The
   page is a flat sheet of paper with ink on it.
4. **Mono is for measurements.** Monospace type is reserved for things
   that *are* technical: identifiers, dimensions, timestamps, code,
   captions on figures. It is never used to look "techy" alone.
5. **Italics carry weight.** EB Garamond italic is the pull-quote, the
   *quoted term*, the marginal voice. We rely on it instead of bolder
   weights or color shifts.

---

## 2. Color

All color is defined in `oklch` to keep perceptual relationships stable.
The palette is monochromatic with a single chromatic accent.

### 2.1 Palette tokens

| Token              | Value                          | Use                                          |
| ------------------ | ------------------------------ | -------------------------------------------- |
| `--paper`          | `oklch(0.965 0.012 85)`        | Page background. Warm cream.                 |
| `--paper-deep`     | `oklch(0.945 0.014 82)`        | Inset blocks, code surfaces, pull quotes.    |
| `--paper-edge`     | `oklch(0.92 0.018 80)`         | Edge tinting, mini-map fills.                |
| `--ink`            | `oklch(0.20 0.012 50)`         | Primary text and primary strokes.            |
| `--ink-soft`       | `oklch(0.34 0.012 50)`         | Body copy on dense pages, subtitles.         |
| `--ink-faint`      | `oklch(0.52 0.010 60)`         | Captions, metadata, marginalia body.         |
| `--ink-ghost`      | `oklch(0.72 0.008 70)`         | Hatching, scrollbars, decorative ticks.      |
| `--rule`           | `oklch(0.20 0.012 50 / 0.18)`  | Hairline rules between sections.             |
| `--rule-strong`    | `oklch(0.20 0.012 50 / 0.42)`  | Stronger boundaries, table grids.            |
| `--rubric`         | `oklch(0.55 0.17 30)`          | Single accent. Vermilion. Always intentional.|
| `--rubric-deep`    | `oklch(0.42 0.16 30)`          | Hover/pressed states for rubric.             |
| `--rubric-wash`    | `oklch(0.55 0.17 30 / 0.10)`   | Tinted backgrounds for compiled-layer rows.  |
| `--gilt`           | `oklch(0.66 0.10 75)`          | Faded gold. Rare ornamental use only.        |

### 2.2 Rules of use

- **Body text** is `--ink` on `--paper`. Never the other way.
- **`--rubric` carries five jobs:** section numbers, sealed status, drop
  cap on the lede, pinned-locus glyphs, and primary CTA hover state.
- **Selection color** is `--rubric` background, `--paper` foreground.
- **Never** introduce a green, blue, yellow, or second accent. The system
  collapses the moment you add a second hue.

---

## 3. Typography

Three families. No more.

| Family           | Role                                               | Stack                                |
| ---------------- | -------------------------------------------------- | ------------------------------------ |
| EB Garamond      | Display, headings, pull quotes, italics            | `'EB Garamond', 'Georgia', serif`    |
| Inter            | UI body, navigation, buttons                       | `'Inter', system-ui, sans-serif`     |
| JetBrains Mono   | Code, identifiers, captions, tags, metadata        | `'JetBrains Mono', 'Menlo', monospace` |

### 3.1 Scale

| Token        | Size       | Used for                                           |
| ------------ | ---------- | -------------------------------------------------- |
| `--t-micro`  | 0.6875rem  | Captions, tags, mono metadata, dimension labels.   |
| `--t-small`  | 0.8125rem  | Nav links, footer body.                            |
| `--t-body`   | 1rem       | UI body.                                           |
| `--t-lead`   | 1.1875rem  | Section ledes, italic subtitle on section heads.   |
| `--t-h4`     | 1.375rem   | Card titles, axiom headings.                       |
| `--t-h3`     | 1.875rem   | Subsection titles.                                 |
| `--t-h2`     | 2.75rem    | Section titles.                                    |
| `--t-h1`     | 4.5rem     | Page titles in articles.                           |
| `--t-display`| 7rem       | Frontispiece headlines.                            |

### 3.2 Letterspacing

- Display & headings: `-0.01em` to `-0.025em` (tighter as size grows).
- Mono captions: `0.14em–0.18em`, uppercased.
- Smallcaps / `.allcaps` utility: `0.18em`.

### 3.3 Treatments

- **Drop cap** (`.dropcap`): leading paragraph in long-form articles.
  Garamond, weight 600, color `--rubric`, floated 5.4em tall.
- **Smallcaps** (`.smallcaps`): mono-uppercased labels and tags.
- **Italic, vermilion**: in headlines, the *emphasised noun* is italic
  Garamond in `--rubric`. Used sparingly — once per headline.

---

## 4. Spacing & rhythm

The grid is editorial, not 8pt-rigid. Vertical rhythm follows the body
line-height (1.55).

| Token       | Use                                                 |
| ----------- | --------------------------------------------------- |
| `--gutter`  | 2rem — column gap default.                          |
| `--measure` | 64rem — comfortable measure for long-form prose.    |
| `--hairline`| 1px — every rule. Never thicker for body content.   |

Section padding scales with viewport: `clamp(4rem, 8vw, 6rem)` is the
default vertical rhythm between major page sections. Within a section,
keep elements grouped by hairline rules rather than by extra whitespace.

---

## 5. Components

Every component is documented and rendered live in
`/public/design_system.html`, since removed. The summary below is the contract.

### 5.1 Colophon (top bar)

A sticky, paper-tinted top bar with three regions: brand, primary nav,
nav meta. Backdrop-blurred. Hairline bottom rule.

- `.colophon > .frame.colophon-inner > .brand · .nav · .nav-meta`
- Brand carries the wordmark + a mono "edition" tagline (e.g. "v0.4 · primer").
- Active nav link gets a vermilion underbar 4px below baseline.

### 5.2 Buttons

Three variants — solid, ghost, bare. All rectilinear (no radius).

- `.btn` — solid ink → vermilion on hover. Default CTA.
- `.btn.btn-ghost` — outlined. Inverts to ink on hover.
- `.btn.btn-bare` — no chrome. Hairline-bottom underline only.
  For inline calls and footer links.

Trailing `<span class="arrow">→</span>` slides 3px right on hover.

### 5.3 Tags & pin labels

Mono uppercase, hairline border. `.tag` for status pills with a vermilion
square dot. `.pin-label` for diagram callouts — the dot becomes a vermilion
diamond, denoting a pinned locus.

### 5.4 Section header

`.sec-head` is a 8rem / 1fr grid: numbered roman label on the left
(`§ I — Architecture`) and a Garamond title + italic lede on the right,
closed with a strong hairline.

### 5.5 Marginalia

Right-rail callouts on long-form pages.
- `.marginalia` for inline asides
- `.margin-block` for full marginalia entries — vermilion `lab`, italic
  Garamond body, mono `ref` line beneath a dotted divider.

### 5.6 Glossary

Definition list rendered as a hairline-divided two-column table:
mono uppercase term in `--rubric` left, Garamond definition right.

### 5.7 Pull quote

`.pull` — left-bordered with a 3px vermilion bar, paper-deep background,
italic Garamond at 1.45rem, mono cite line prefixed with `— `.

### 5.8 Figure

`.figure` is a hairline-bordered block with a mono caption row at the
top (split: name left, version right) and a body containing either code,
a key-value `pre.kv`, or a small SVG diagram.

### 5.9 Spec card

Used on the spec sheet. A 3-column grid where each `.spec` is a
hairline-bordered cell with: corner annotation, mono label, large
Garamond value (with optional mono unit), one-line description.

### 5.10 Axiom

A 4-up grid divider used on the landing page. Each `.axiom` carries a
roman numeral, a Garamond headline with one *italic vermilion* word, and
a brief plain description.

### 5.11 Code block

Dark inverted surface. Mono, 0.84rem, line-numbered via CSS counters.
Token classes: `.k` (keyword, vermilion), `.s` (string, gilt), `.c`
(comment, ink-faint italic), `.v` (value), `.fn` (function, gilt-warm).

### 5.12 Locus pin

Used in the palace. Absolutely-positioned, paper-filled, hairline-bordered,
mono label, prefixed with a vermilion diamond. Hover inverts to ink.
Active state is solid vermilion.

### 5.13 Floor plan SVG

Architectural blueprint conventions:
- Outer wall: `--ink`, stroke-width 3.5
- Inner walls: `--ink`, stroke-width 2
- Door arcs: `--ink-faint`, stroke-width 1
- Dimension lines + hatched exterior: `--ink-faint` / `--ink-ghost`
- Title block bottom-right: paper fill, 1.5px ink stroke, inside text
  in Garamond italic + mono caption
- **Always** use `style="stroke: var(--ink); fill: var(--paper);"`
  on SVG elements rather than presentation attributes.
  CSS variables do not resolve in SVG presentation attributes.

### 5.14 Compass & scale bar

Decorative-but-functional. Compass is a 4.5rem circle with crossed axes,
a vermilion arrowhead pointing north, and a mono "N" label. Scale bar is
6rem, half ink / half paper, with mono "0 ↔ 1m" caption.

### 5.15 Stamp

`.stamp` is a vermilion-bordered, vermilion mono label rotated -1deg.
Used on sealed entries. Carries an air of office officialdom.

---

## 6. Patterns

### 6.1 Numbered, hairline-divided lists

For sequential items, use `.layer-list`: a 3-column grid with a vermilion
mono number, a Garamond label, and a mono uppercase meta tag, separated
by hairline rules.

### 6.2 Two-layer architecture diagram

The L₀–L₂ stack figure on the landing page is the canonical "vertical
stack" diagram. Compiled rows (L₁.₅+) get the rubric-wash background and
vermilion labels; raw rows stay paper. Keep an "↑ compile · ↓ recall"
caption beneath.

### 6.3 Three-column long-form layout

Wiki entries use a sticky left ToC, center article (max-width 38rem), and
sticky right marginalia rail. The marginalia rail collapses below 1180px;
the ToC collapses below 820px.

### 6.4 Three-column app layout

The palace browser uses a 16rem left rail (rooms), flexible center stage
(blueprint), and 19rem right rail (inspector). The stage is non-scrolling;
the floor plan SVG fills the available area.

---

## 7. Voice & copy

The voice is *quietly authoritative, almost academic*. It assumes a
sophisticated technical reader (a self-hosted-AI enthusiast who runs
their own infrastructure). It does not sell. It explains.

- Prefer **stating the principle, then the consequence**.
- Use Latin and antique vocabulary sparingly: *memoria artificiosa*,
  *locus*, *frontispiece*, *colophon*, *plate*, *rite*. Never gratuitously.
- Prefer **em dashes — used like this —** over parentheses for asides.
- Numbers are spelled out under ten in prose; numerals everywhere else.
- Footnotes are encouraged; superscript with a vermilion mono numeral.
- Headlines may include one italic vermilion word for emphasis. One.

---

## 8. Iconography

We do not draw icons. Where a glyph is needed:

- **Brand mark**: a 22px square, double-stroked, with an inner 55%-scale
  vermilion rotated square as the centre.
- **Diamond**: a 6–8px rotated square, vermilion fill, used for any
  "pinned" semantic (loci, list bullets, tag dots, list-item glyphs).
- **Compass**: only on the palace.
- **Ornament**: `❦` (aldus leaf), used as a centred trinity (`❦ · ❦ · ❦`)
  before the closing CTA.

Anything more elaborate than that is **a placeholder for a real engraving**.
Do not synthesise an SVG icon. Draw a hatched rectangle and label it.

---

## 9. Motion

Motion is restrained. The page is paper; paper does not bounce.

- **Hover** transitions: 150ms `ease`, on color/background/border only.
- **Button arrow**: translateX(3px) on hover, 200ms.
- **Locus pin**: scale(1.04) on hover, 150ms.
- **No** entrance animations on page load. The page is already there
  when you walk into it.

---

## 10. Accessibility

- Body text contrast on `--paper`: 12.4:1 (`--ink`), 7.6:1 (`--ink-soft`).
- Selection inverts to `--rubric` ground / `--paper` text — verify 4.5:1.
- `--ink-ghost` is decorative-only. Never use it for text.
- All interactive elements have a `:focus` state via the browser default
  outline. Do not remove it. (If you do, replace it with a 2px vermilion
  outline at 2px offset.)
- Hairline rules at 0.18 alpha are decorative, not semantic. Don't rely
  on them to convey grouping for screen-reader users.

---

## 11. Files

```
/styles/mnemon.css            shared design tokens + primitives
/index.html                   landing page
/wiki.html                    wiki entry
/palace.html                  palace browser
/docs/design_system.md        this document
/public/design_system.html    (removed — frozen showcase, deleted in the truth-up pass)
/public/sample_landing.html   (removed — frozen copy of the landing page)
/public/sample_wiki.html      (removed — frozen copy of the wiki page)
/public/sample_palace.html    reference copy of the palace browser
```

---

## 12. Don't

A list, in order of severity, of things that break the system:

1. Adding a second accent color.
2. Rounding a corner.
3. Adding a drop shadow or `box-shadow: ...` of any kind.
4. Using emoji in UI copy.
5. Using a sans-serif for body prose in a long-form article.
6. Using mono for prose.
7. Drawing a custom SVG icon set.
8. Animating the page on load.
9. Using `var(--ink)` in an SVG presentation attribute (it will silently
   not render).
10. Hero-illustrating anything. Use a numbered figure instead.

---

*Mnemon · Treatise No. 001 · A.M. MMXXVI*
*Hand-set in EB Garamond and JetBrains Mono.*
*Rubricated in vermilion `oklch(0.55 0.17 30)`.*
