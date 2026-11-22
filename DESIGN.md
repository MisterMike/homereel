---
name: HomeReel
description: A quiet private screening room for family videos.
colors:
  canvas: "#101418"
  panel: "#191e23"
  cover: "#20282f"
  text: "#edf2f3"
  muted: "#acb6bf"
  accent: "#a5dfcd"
  accent-hover: "#bfeedd"
  accent-ink: "#10261f"
  border: "#30383e"
  input-border: "#59646e"
  control-border: "#76858e"
  error: "#ffb4ad"
typography:
  headline:
    fontFamily: "HomeReel Inter, sans-serif"
    fontSize: "clamp(28px, 3vw, 42px)"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.035em"
  title:
    fontFamily: "HomeReel Inter, sans-serif"
    fontSize: "17px"
    fontWeight: 550
  metadata:
    fontFamily: "HomeReel Inter, sans-serif"
    fontSize: "13px"
  label:
    fontFamily: "HomeReel Inter, sans-serif"
    fontSize: "14px"
rounded:
  badge: "4px"
  input: "6px"
  button: "7px"
  player: "8px"
  cover: "9px"
  panel: "10px"
spacing:
  small: "12px"
  medium: "24px"
  panel: "32px"
  large: "36px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.accent-ink}"
    rounded: "{rounded.button}"
    padding: "12px 20px"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.text}"
    rounded: "{rounded.button}"
    padding: "12px 20px"
  input:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.text}"
    rounded: "{rounded.input}"
    padding: "12px 14px"
  cover:
    backgroundColor: "{colors.cover}"
    rounded: "{rounded.cover}"
  upload-panel:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.text}"
    rounded: "{rounded.panel}"
    padding: "32px"
---

# Design System: HomeReel

## Overview

**Creative North Star: "A quiet private screening room"**

Dark slate surfaces, generous video imagery and mint controls keep the family collection central. The implementation is deliberately modest: a restrained wordmark, small metadata and direct, familiar controls.

The source of these tokens is `resources/css/components/library.css`, used by the Blade viewing interface and the custom upload panel. The administration shell retains Filament's existing components and configured Teal primary palette; extend that shell with its native conventions rather than restyling it to imitate the viewing interface.

**Key Characteristics:**
- Dark surfaces with clear, quiet separation.
- Image-led browsing and compact metadata.
- Visible keyboard focus and honest operational feedback.

## Colors

### Primary
Mint accent identifies primary actions, the brand mark, progress, selection and keyboard focus. Its lighter hover tone keeps interaction understated; dark accent ink makes button labels legible.

### Neutral
Canvas, panel and cover provide progressively lighter slate surfaces. Text and muted text distinguish content from metadata; border, input-border and control-border distinguish separators from interactive outlines. Error is reserved for failure feedback. Upload explanatory copy uses the local lighter slate value in its stylesheet.

**The Focus Rule.** Interactive controls in the custom interface retain a visible mint outline with an offset.

## Typography

Self-hosted HomeReel Inter is the custom interface's single variable family with a sans-serif fallback. Page headlines use the recorded headline role; card titles and metadata use the smaller roles. Body text inherits the Tailwind base size; descriptive paragraphs use relaxed line-height. Do not introduce a separate display face.

Login introduces the service with a larger headline (`clamp(36px, 4.6vw, 64px)`, line-height `1.1`), reduced to `39px` on phones. This is a login-specific composition, not a new global type scale. Filament's shell keeps its own typography.

## Layout

The viewing shell is centered at `min(1440px, 92vw)`, with a `90vw` phone width. Desktop vertical padding is `56px` above and `72px` below; phone padding is `32px` and `48px`. The image grid has three columns, two at `950px` and below, and one at `600px` and below. Desktop gaps are `36px` vertically and `24px` horizontally; phones use `28px`.

At the phone breakpoint the heading and login columns stack, navigation wraps and the footer wraps. Upload content stays within `760px`, with panel padding reduced from `32px` to `22px` on phones. Long titles and filenames wrap. Description text is limited to `75ch`.

## Elevation & Depth

The custom interface uses tonal layering and thin separators without box shadows. Video imagery supplies depth. Card image hover scales gently (`1.025` over `0.2s`); buttons transition background over `0.18s`. Reduced-motion preference disables these transitions. Filament retains its native elevation behavior.

## Shapes

Small rounded controls, modestly rounded video frames and a slightly softer upload panel follow the recorded radius roles. Video covers retain a `16:9` ratio; the player uses the same ratio and a maximum height of `75vh`. The duration badge overlays the lower right of a cover with a translucent canvas background.

## Components

- **Buttons:** mint primary, outlined secondary and unboxed text actions. Disabled controls reduce opacity and lose the pointer cursor. Secondary buttons currently inherit the shared mint hover background; preserve this observed behavior unless deliberately revising the component.
- **Inputs:** slate fill, visible neutral outline and mint keyboard focus. Labels remain above fields; errors use text with alert semantics.
- **Video cards:** the linked image, title and date form one unit. Use real authorized thumbnails or the plain status placeholder already provided by the application.
- **Navigation:** compact text links with underlined hover, a small outlined play mark and a simple wordmark. Preserve the wrapping phone layout.
- **Upload panel:** a dark contained workspace with a dashed file chooser, native progress element, tabular numeric progress and live status feedback. Confirmed progress comes from the server.
- **Player:** native video controls on a black frame; descriptive content follows below.

## Do's and Don'ts

### Do:
- **Do** reuse the custom controls and spacing roles on new viewing surfaces.
- **Do** use Filament components and its configured Teal palette for administration shell extensions.
- **Do** preserve visible focus, reduced motion, live feedback and native playback controls.

### Don't:
- **Don't** invent family imagery or replace the honest empty state with decorative footage.
- **Don't** add decorative effects or elaborate branding that exceeds the modest polish brief.
