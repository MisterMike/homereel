# HomeReel
<!-- impeccable:product-schema 1 -->

## Platform
web

## Stack
PHP 8.3+, Laravel 13, Filament 5, Blade, Alpine, Tailwind 4, MariaDB and database queues, as specified by the user.

## Users and purpose
Administrators preserve and share private family videos. Authenticated family members browse and watch permitted videos across phones, tablets and desktops.

## Capabilities and constraints
Milestone 1 covers administrator resumable uploads, private originals, queued inspection, optional thumbnails, and authenticated seeking. No public registration, groups, albums or transcoding. Hostpoint shared hosting requires short upload requests and cron-driven workers. Never expose private media through static URLs. Deployment is documentation only.

## Brand commitments
HomeReel is a warm, premium private family cinema. Follow docs/HomeReel-FRONTEND_DESIGN_BRIEF.md and its supplied mood reference: warm charcoal, restrained coral, Manrope, image-led responsive layouts, and honest navigation. Filament remains a separate administration shell. No supplied family footage; do not invent it.

## Product principles
Protect originals; authorize every media request; allow interrupted uploads to recover; keep storage and playback replaceable.
