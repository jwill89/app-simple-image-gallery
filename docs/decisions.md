# Decisions

- 2026-11-15 — Targets meet WCAG 2.2's 24 px minimum (2.5.8, AA), not the 44 px of 2.5.5 (AAA): buttons, fields and page links are 34 px tall and small buttons 28 px, and a chip's remove button and a dialog's or toast's close button pass 2.5.8 by their spacing, so the toolbars, tables and tag lists of a dense media library stay on one line. Only the card's heart reaches 44 px.
- 2026-11-15 — The colour scheme is the reader's choice: light, dark or the device's, set as one `data-theme` attribute on `<html>` by `theme.ts` and remembered in the browser, in place of following the device alone.
- 2026-11-15 — The queue worker runs as its own locked account in www-data's group, so what it writes stays group-readable by Apache, at idle CPU and I/O priority, with the shared hardening block and a `MemoryMax=` taken from its measured peak.
- 2026-11-15 — `IMPROVEMENTS.md`, old internal review notes, goes, with its markdownlint ignore.
- 2026-11-15 — No restore drill is run on the host; docs/ops.md keeps the drill's steps for when one is wanted.
- 2026-11-16 — An API error names its failure in its problem `type`, a tag URI ending in the PascalCase name (`tag:gallery.mathdad.me,2026:MediaNotFound`), rather than in a `code` member, so the body is a plain RFC 9457 problem; a tag URI names and is never fetched.
- 2026-11-16 — The collected videos stay without captions or transcripts: they are third-party content, so an Accessibility page, linked from the footer, makes WCAG 2.2's statement of partial conformance for them, in place of captioning media the gallery didn't make.
- 2026-11-16 — The originals (`media/full`, 9.4 GB) are copied to the dev machine at each deploy (`devdata\media`); the thumbnails and the duplicate reports aren't backed up, since a rebuild or a scan makes them again.
