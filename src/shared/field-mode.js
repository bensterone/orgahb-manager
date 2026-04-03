/**
 * Field/mobile mode detection helpers.
 *
 * "Field mode" means the user is likely on a mobile device in the field,
 * e.g. after scanning a building QR code. Used to adjust UI density
 * and interaction patterns (larger tap targets, bottom sheets, etc.).
 */

/**
 * Returns true when the current viewport or user-agent suggests a mobile
 * field worker context.
 *
 * @returns {boolean}
 */
export function isFieldMode() {
	return (
		window.matchMedia( '(max-width: 768px)' ).matches ||
		/Mobi|Android/i.test( navigator.userAgent )
	);
}

/**
 * Registers a callback that fires whenever the viewport crosses the field-mode
 * breakpoint (768 px). The callback receives the new isFieldMode boolean.
 *
 * @param {function(boolean): void} callback
 * @returns {function(): void}  Cleanup function — call to remove the listener.
 */
export function onFieldModeChange( callback ) {
	const mq = window.matchMedia( '(max-width: 768px)' );
	const handler = () => callback( isFieldMode() );
	mq.addEventListener( 'change', handler );
	return () => mq.removeEventListener( 'change', handler );
}
