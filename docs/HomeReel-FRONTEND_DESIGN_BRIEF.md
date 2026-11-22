# HomeReel — Frontend Design Brief

**Status:** Design direction / implementation reference, November 2026  
**Reference image:** `homereel_familienvideo_app_im_studio.png` (attach separately alongside this file)  
**Product:** HomeReel — a private, invite-only family video library  
**Implementation:** Laravel 13, Blade, Alpine.js, Tailwind CSS 4, Vite; Filament 5 is a separate admin experience.

## 1. The brief

Translate the supplied cinematic multi-device mockup into **actual responsive application screens**. The reference is a *mood and visual hierarchy* reference, not a pixel-perfect layout or an instruction to recreate the staged device scene.

HomeReel should feel like a private family cinema: personal, warm, peaceful, modern and thoughtfully crafted. The videos are the stars; the interface stays quiet. Avoid a corporate dashboard, gaming UI, neon streaming aesthetic or childish cartoons. The audience includes adults, grandparents and children using phones, tablets and desktop browsers.

### Design principles

1. **Video first:** image-led cards, generous viewing area, minimal chrome during playback.
2. **Warm dark palette:** charcoal surfaces, slightly warm text, one restrained coral accent.
3. **Comfortably usable:** legible metadata, obvious actions, simple navigation, reliable keyboard/touch behaviour.
4. **Responsive by design:** intentionally compose phone/tablet/desktop views; don't merely shrink the desktop sidebar.
5. **Private by default:** don't create public-sharing affordances; show admin controls only to admins.
6. **Honest UI:** no broken menu items or features that do not exist in the app.

## 2. Visual tokens (starting values, tune visually)

| Role | Suggested value | Use |
| --- | --- | --- |
| Page background | `#111316` | Main canvas |
| Nav / sidebar | `#17191E` | Stable navigation |
| Raised surface | `#202329` | Dropdowns, panels and input backgrounds |
| Hover surface | `#292D34` | Subtle hover emphasis |
| Divider | `#343941` | Sparse separators/borders |
| Primary text | `#F5F3EF` | Main headings, labels |
| Secondary text | `#B1B3BA` | Dates, supporting info |
| Muted text | `#858A93` | Quiet metadata, only where contrast is adequate |
| Accent | `#ED896D` | Primary CTA, active navigation, selected chips |
| Accent hover | `#F49B82` | Hover/pressed interactive emphasis |
| Accent on-color | `#211914` | Text/icons on filled accent buttons |
| Positive | `#78BE9B` | Successful upload/processing |
| Warning | `#E6B866` | Processing / caution |
| Danger | `#E47A7A` | Upload errors / destructive controls |

Prefer CSS/Tailwind design tokens, not repeated hex literals. Adjust palette to meet WCAG contrast, especially small text and buttons. Avoid pure black, white or oversaturated orange.

- **Typography:** distinctive but neutral sans, e.g. *Manrope* with local/system fallback. Body 15–16px minimum; most titles 18–24px; page titles 28–36px on desktop and 24–28px mobile. Use strong hierarchy rather than heavy weights everywhere.
- **Geometry:** thumbnail ratio **16:9**; content-card corners 10–12px; buttons/inputs 10–12px; larger hero corners 16–18px. Do not wrap every element in a card.
- **Spacing:** 4/8px rhythm; layout gaps 20–28px on desktop, 16px mobile. Align visual edges consistently.
- **Depth:** gentle borders, muted shadow, very restrained hero-image gradient for legible text. No glows or glassmorphism everywhere.
- **Motion:** brief 120–180ms hover/focus transitions with `prefers-reduced-motion` support; no autoplay hero video or distracting animation.
- **Icons:** a consistent thin-line set (such as Lucide) with consistent size/stroke; do not ship an entire icon library unless already available.

Suggested Tailwind v4 token approach:

```css
@import "tailwindcss";
@theme {
  --color-hr-bg: #111316;
  --color-hr-nav: #17191e;
  --color-hr-surface: #202329;
  --color-hr-hover: #292d34;
  --color-hr-line: #343941;
  --color-hr-text: #f5f3ef;
  --color-hr-muted: #b1b3ba;
  --color-hr-accent: #ed896d;
  --color-hr-accent-hover: #f49b82;
  --color-hr-on-accent: #211914;
}
```

## 3. Responsive shell

### Desktop (>= 1280px)
- Full-width app shell with a compact top header (roughly 68–76px).
- Logo left, useful navigation/search in header, user menu on right.
- Persistent left sidebar ~220–240px; main content flexible, max width around 1440px.
- Video grid **4 columns where space allows**, falling to 3 at narrower widths.
- Hero feature across main-content width, approximately 280–340px tall, with warm photographic image and left-side gradient/title.
- Avoid giant empty gutters on 1920px screens.

### Tablet (768–1279px)
- Reduce or collapse persistent sidebar; prefer compact top navigation and category/filter chips.
- Grid **2–3 columns** based on available room.
- Player prioritised; related videos below rather than cramped beside it.
- The header remains usable in landscape and portrait orientations.

### Mobile (320–767px)
- No permanent left sidebar. Compact top bar: logo, optional search, avatar/user action.
- Horizontally scrolling filter chips **only when filters actually work**.
- Grid **one full-width card** for small phones; consider two columns only on sufficiently wide screens with still-readable metadata.
- Hero becomes a shorter feature card or is omitted when no featured item exists; never push the first videos far below the fold.
- Fixed or sticky bottom navigation is optional, but must not include dead actions; accommodate safe-area insets.
- Minimum 44px tap target for primary controls, no horizontal overflow on 360px.

## 4. Screens to implement

### A. Video library (primary screen)

**Above the fold:** recognizable HomeReel header; search if backend capability exists; optional small hero for the latest/featured *authorized* ready video; heading “Your videos” or “All videos”; sorting only if wired up; video grid.

**Video card anatomy:**
- 16:9 thumbnail, `object-fit: cover`, subtle image hover (very light scale or brightness).
- Lower-right duration badge, high contrast and readable at small sizes.
- Title below thumbnail, 1–2 line clamp.
- Secondary metadata: recording/upload date or duration, only using data actually available.
- Whole card links to authorized video detail. Real hover/focus styles.
- No artificial three-dot menus with no behaviour.
- Missing thumbnail uses tasteful placeholder graphic; no broken-image icon.

**Empty library:** a well-written, uncluttered empty state. If admin, link to existing upload interface; otherwise say that there are no videos available yet. **Loading/failed thumbnail states** should be graceful.

### B. Video detail / playback

- Large, responsive video stage, 16:9 or video-native aspect ratio, prominently placed.
- Preserve and use current authenticated streaming endpoint and byte-range functionality.
- Native HTML5 controls initially; don't replace with a custom player just for appearance.
- Title, date/duration, short description immediately beneath the player.
- Optional related/recent video row populated **only from authorized videos**; omit if unsupported.
- Comfortable viewing on phones with no horizontal scrolling and a clear path back to library.
- Provide appropriate loading, processing, unavailable, unsupported-format and playback-failure messages.

### C. Upload experience (admin only)

- Keep existing resumable upload architecture, progress, pause/resume, error handling and finalisation untouched.
- Restyle existing upload UI, rather than creating a duplicate uploader.
- Prominent drop/select zone (don't imply drag/drop works if it does not), file information, progress bar, states (`uploading`, `processing`, `ready`, `failed`), and clear next action.
- For a multipart upload in progress: report the **confirmed** server progress, not simulated percentages.
- On mobile, make upload UI a full-width page or sheet rather than a tiny modal.
- If upload remains inside Filament, do not globally override Filament's styles. Use a scoped surface in that page or keep its styling consistent with Filament.

### D. Login

- Understated branded login page with HomeReel logo and a short human-facing subheading, e.g. “Your family’s moments, in one place.”
- Clearly labelled email and password, accessible error states, remember-me only if already supported.
- No register/public sign-up link. No marketing landing page necessary.
- Show a useful mobile view.

## 5. Navigation and data honesty

The reference picture contains “Albums”, “People”, “Updates”, categories and favourites. **These are future concepts**, not Milestone 1 requirements.

For this pass only expose links/actions with real routes or functioning handlers. The Milestone 1 data model may not include categories/albums; do not invent backend functionality or mislabel arbitrary metadata just to mimic the image. If you'd like to show those future elements in a *standalone design-only preview*, label the preview clearly and keep it separate from the live application.

Do not show admin upload buttons to ordinary viewers. Do not include sharing/public URL features. Authorize featured thumbnails and any secondary video lists with the same rules as the library.

## 6. Componentisation and project conventions

Use existing Laravel/Vite/Blade/Alpine conventions; inspect the repo before changing structure. Suggested reusable Blade components (adapt paths to app):

- `x-layouts.app` — global shell
- `x-navigation.header`, `x-navigation.sidebar`, `x-navigation.mobile-nav`
- `x-video.card`, `x-video.grid`, `x-video.feature`, `x-video.player`, `x-video.metadata`
- `x-ui.button`, `x-ui.badge`, `x-ui.empty-state`

Use `resources/js/app.js` as entrypoint; put Alpine components/modules under `resources/js/components/` (e.g. uploader or menu if needed). Group repeatable CSS styling in `resources/css/components/` rather than copying huge Tailwind class lists. Tailwind 4 should handle layout and utilities; custom CSS should handle design-system components and more elaborate repeating styles.

Avoid React/Vue/SPA. Do not couple app navigation to Filament internals. Preserve routes, controllers, authorization policies, upload sessions and processing jobs.

## 7. Visual quality and accessibility checklist

- Work in real browser at **360, 390, 768, 1024, 1440 and 1920px**; test landscape tablet and tall/short screens.
- Verify thumbnail crops, line clamping, loading/missing states, 1–100+ video collections and long titles.
- Strong visible keyboard focus, semantic links and buttons, form labels, logical heading order.
- AA text contrast; don't use dim gray for essential metadata.
- A11y announce upload progress and failures; don't rely on colour alone.
- Motion respects reduced-motion.
- Touch controls at least ~44px for key actions and enough spacing to avoid accidental taps.
- Screenshots (where available): library desktop/mobile, player desktop/mobile, admin upload, login.
- Run production asset build and existing tests; don't weaken video-security tests to make UI changes pass.

## 8. Success criteria

1. The actual site visually captures the mockup's warm, cinematic dark look **without reproducing the marketing scene/devices**.
2. It feels deliberate and legible on phone, tablet and desktop.
3. Existing video upload, authenticated playback and authorization work unchanged.
4. The app has reusable components/tokens, not one enormous Blade view.
5. No dead navigation, public-sharing UI or fabricated backend features.
6. The frontend is visually coherent in empty, loading, ready and error states.

## 9. Suggested iterative process for Codex + Impeccable

1. Read this brief and study the attached mockup. Inspect the existing repo.
2. Identify current real screens/routes and dependencies. Preserve security-sensitive logic.
3. Establish tokens and shell/components. Implement library and player first, then login and upload styling.
4. Render and screenshot in browser at representative widths. Critique and refine actual UI, not just code.
5. If Impeccable is installed, use its design critique/polish workflow consistent with this brief (but do not let automated suggestions overwrite real backend behaviour).
6. Report what was changed, show screenshots when possible, list unimplemented concepts clearly.
