---
name: HomeReel
description: A warm private family cinema, with the videos at its heart.
colors:
  bg: "#111316"
  nav: "#17191e"
  surface: "#202329"
  hover: "#292d34"
  line: "#343941"
  text: "#f5f3ef"
  secondary: "#b1b3ba"
  muted: "#9b9fa8"
  accent: "#ed896d"
  accent-hover: "#f49b82"
  on-accent: "#211914"
  positive: "#78be9b"
  warning: "#e6b866"
  danger: "#e49a9a"
  input-line: "#707681"
typography:
  headline:
    fontFamily: "HomeReel Manrope, sans-serif"
    fontSize: "clamp(26px, 2.7vw, 34px)"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.035em"
  title:
    fontFamily: "HomeReel Manrope, sans-serif"
    fontSize: "16px"
    fontWeight: 650
    lineHeight: 1.5
    letterSpacing: "-0.02em"
  body:
    fontFamily: "HomeReel Manrope, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.6
  metadata:
    fontFamily: "HomeReel Manrope, sans-serif"
    fontSize: "14px"
    lineHeight: 1.6
  label:
    fontFamily: "HomeReel Manrope, sans-serif"
    fontSize: "15px"
    fontWeight: 600
rounded:
  badge: "5px"
  menu-item: "8px"
  navigation: "10px"
  control: "12px"
  menu: "14px"
  player: "16px"
  feature: "18px"
spacing:
  small: "8px"
  control: "12px"
  medium: "16px"
  inset: "20px"
  gap: "24px"
  section: "28px"
  large: "32px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.control}"
    padding: "11px 20px"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.text}"
    rounded: "{rounded.control}"
    padding: "11px 20px"
  button-secondary-hover:
    backgroundColor: "{colors.hover}"
  button-text:
    backgroundColor: "transparent"
    textColor: "{colors.secondary}"
    padding: "8px 0"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.control}"
    padding: "12px 15px"
  navigation-current:
    backgroundColor: "color-mix(in srgb, #ed896d 15%, transparent)"
    textColor: "{colors.accent}"
    rounded: "{rounded.navigation}"
    padding: "12px 16px"
  cover:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.control}"
  status:
    backgroundColor: "{colors.nav}"
    textColor: "{colors.warning}"
    rounded: "{rounded.badge}"
    padding: "3px 8px"
  upload-panel:
    backgroundColor: "{colors.nav}"
    textColor: "{colors.text}"
    rounded: "{rounded.feature}"
    padding: "32px"
---

# Design System: HomeReel

## Overview

**Creative North Star: "Your private family cinema"**

Warm charcoal, softly warm text and a restrained coral accent make HomeReel personal, peaceful and cinematic. Video imagery provides the atmosphere; the surrounding interface stays quiet, legible and comfortable for family members on phones, tablets and desktops. This system records the implementation of the user’s frontend design brief and supplied mood reference.

The custom Blade viewing and login surfaces share reusable navigation, video and UI components. The custom upload panel shares their tokens and controls inside a separate native Filament administration shell. Source authority is `resources/css/components/tokens.css`, followed by the controls, shell, library, login and upload stylesheets; `resources/js/app.js` is the central Alpine entrypoint.

**Key Characteristics:**
- Warm dark surfaces and restrained coral actions.
- Image-led browsing with clear, compact metadata.
- Responsive composition, visible focus and honest media states.
- Separate native administration shell with a scoped upload surface.

## Colors

### Primary
Coral accent identifies primary actions, active navigation, selection, progress and keyboard focus. Accent hover adds gentle warmth on interaction. On-accent supplies dark, readable labels on filled controls.

### Neutral
Background is the main charcoal canvas; navigation is a slightly raised stable shell. Surface supports inputs, menus and missing previews; hover supplies quiet interaction contrast. Line separates regions sparingly. Warm text carries headings and labels, secondary carries dates and supporting copy, and muted is reserved for quieter footer and privacy copy. Input-line makes interactive outlines visible.

Positive, warning and danger are semantic feedback tokens, not extra brand accents. Warning accompanies processing and playback notices; danger accompanies errors and failed states. Positive is available in the token set without requiring a success decoration on every screen.

**The Focus Rule.** Custom interactive controls retain a two-pixel coral keyboard outline with a five-pixel offset; the file chooser also exposes focus within its container.

## Typography

Locally hosted variable HomeReel Manrope is the single custom-interface family, with a sans-serif fallback and weights 200–800 available. The frontmatter records the reused headline, title, body, metadata and label roles. Card titles increase to 18px on phones; they clamp to two lines and wrap long words.

Feature titles use `clamp(24px, 2.6vw, 34px)` at weight 750 and line-height 1.18. The login introduction uses `clamp(34px, 3.5vw, 48px)`, falling to 32px on phones; its form heading is 28px, falling to 25px. These are surface-specific expressions, not a second typeface. Descriptions use relaxed line-height and are limited to 74ch on the detail page. Duration and progress numbers use tabular numerals.

**The One Family Rule.** Extend the custom interface with the locally hosted Manrope family; preserve Filament’s native typography in its surrounding administration shell.

## Layout

A sticky header is 76px high, becoming 68px below 768px. At 1280px and above, authenticated pages gain a fixed 224px sidebar and matching content offset. Below that width, the Library link moves into the header; administrator upload actions remain available in the page and account menu. The main area is flexible, centered and capped at 1504px including padding, with 32px horizontal insets. Phone insets become 20px, or 16px at 380px and below; footer spacing accommodates the bottom safe area.

The library grid uses one column by default, two from 768px, three from 1024px and four from 1280px, with 24px horizontal and 32px vertical gaps. A featured authorized ready video with a thumbnail can occupy a 308px minimum-height frame, becoming 240px on phones. Its gradient protects text contrast. It is conditional, not a required empty decoration.

Login has two columns within an 1184px main area and a 420px form, stacking below 768px. The upload panel caps at 840px with 32px padding, reduced to 22px on phones. Progress, filenames, descriptions and account identity wrap without horizontal overflow.

**The Real Navigation Rule.** Compose navigation from working routes and authorized actions only; keep administrator controls out of viewer surfaces.

## Elevation & Depth

Most surfaces use tonal separation and fine borders. Cards have no outer box or shadow; actual imagery supplies depth. The account menu alone uses the shared observed floating shadow (`0 16px 40px #0005`). Feature gradients are local legibility devices, not a general glass or glow treatment. Filament retains native elevation outside the custom upload panel.

**The Quiet Motion Rule.** Use the shared easing `cubic-bezier(.16, 1, .3, 1)` with 160ms control and play-hint transitions and 180ms thumbnail transitions. Thumbnails scale to 1.025 and dim to 0.9 brightness on hover or keyboard focus. Disable animations and transitions for reduced motion; loading pulse is feedback rather than decoration.

## Shapes

**The Frame Rule.** Preserve 16:9 thumbnail and player geometry, with images covering thumbnails and video contained in its stage. Covers, buttons and inputs share the control radius; larger feature frames and upload panels use the feature radius. Phone feature and upload corners reduce to 14px, and player corners to 10px. The player’s desktop radius is 16px and its height caps at 76svh.

Badges use small corners, menus and navigation use modest soft corners, and account avatars are circular. Thin-line inline SVG icons are normally 20px, with smaller metadata icons and larger empty-state symbols. Do not turn every text region into a bordered card.

## Components

- **Buttons:** coral primary, outlined secondary and underlined text variants. Primary and secondary controls have a minimum height of 46px; text actions have 44px targets. Secondary hover uses the hover surface and a lighter outline. Disabled buttons halve opacity and lose the pointer cursor.
- **Inputs:** surface fill, input-line border, shared corners and a minimum height of 50px. Persistent labels remain above fields; invalid borders and error copy use danger. Preserve native file selection.
- **Navigation:** reusable Blade header and sidebar, with coral tinted current links, quiet neutral defaults and hover surfaces. The account disclosure supports outside dismissal and Escape with focus returned to its trigger. Keep the skip link and semantic current-page indication.
- **Video cards:** one linked thumbnail, title and metadata unit. Duration sits at the lower right; non-ready status sits at the upper left with a text label. Missing or failed images retain an honest film-icon placeholder. The hover play hint appears only over a successfully loaded preview, never over the placeholder.
- **Feature:** an authorized ready item, local image gradient, clamped title, available metadata and a working watch action. Supporting description hides on phones.
- **Player:** native HTML5 controls, authenticated stream and authorized poster. Loading, processing and playback failure retain readable feedback and an actual retry action; do not replace native controls with a decorative player.
- **Upload:** scoped panel, dashed select zone, file summary, title, native progress, pause/resume and error feedback. Progress reflects confirmed server bytes. The dedicated `resources/css/upload.css` imports tokens, controls and upload styles without the application’s Tailwind preflight or viewing shell. Extend the surrounding Filament UI with native Filament components; do not load global app CSS there.
- **Empty state:** one quiet outlined film symbol, concise heading and explanation, with upload action only for administrators. No fabricated footage or collection items.

## Do's and Don'ts

### Do:
- **Do** reuse the Blade navigation, video and UI components and shared CSS tokens.
- **Do** preserve visible keyboard focus, reduced motion, readable status text and native playback controls.
- **Do** use authorized real media and honest loading, missing, processing and failure states.
- **Do** keep upload styling scoped and extend the surrounding administration shell with native Filament components.

### Don't:
- **Don't** invent family imagery, public-sharing controls, albums, people, filters or search without working support.
- **Don't** expose private originals or thumbnails through static public URLs.
- **Don't** replace quiet charcoal and coral with the obsolete mint palette or the old Inter typography.
- **Don't** add global app CSS or Tailwind preflight to the native Filament shell.
