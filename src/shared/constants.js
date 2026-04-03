/**
 * Shared constants used across all React apps and shared utilities.
 */

/** Process execution outcome values (spec §22.5 / §30.3). */
export const EXECUTION_OUTCOMES = [
	{ value: 'completed',      label: 'Completed' },
	{ value: 'issue_noted',    label: 'Issue Noted' },
	{ value: 'blocked',        label: 'Blocked' },
	{ value: 'not_applicable', label: 'Not Applicable' },
	{ value: 'escalated',      label: 'Escalated' },
];

/** Building bundle content type slugs. */
export const CONTENT_TYPES = {
	PAGE:     'page',
	PROCESS:  'process',
	DOCUMENT: 'document',
};

/** Observation status values (spec §25.6 — intentionally only open/resolved). */
export const OBSERVATION_STATUSES = [
	{ value: 'open',     label: 'Open' },
	{ value: 'resolved', label: 'Resolved' },
];

/** Document display modes (spec §27.3). */
export const DISPLAY_MODES = {
	PDF_INLINE: 'pdf_inline',
	LINK_ONLY:  'link_only',
	EMBED:      'embed',
};

/** Hotspot kinds (spec §16.2). */
export const HOTSPOT_KINDS = {
	STEP: 'step',
	LINK: 'link',
};
