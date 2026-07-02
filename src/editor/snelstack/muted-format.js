/**
 * "Muted" inline format (snel/muted).
 *
 * A toolbar toggle — like Bold/Italic — that wraps the selected text in
 * <span class="snel-muted">. It carries NO colour itself; the colour is decided
 * by CSS from the section's active background (.snel-muted vs .is-dark
 * .snel-muted in theme.css), so muted text auto-adapts light ↔ dark.
 *
 * Works in any RichText (core/heading, core/paragraph, …).
 */
import { registerFormatType, toggleFormat } from '@wordpress/rich-text';
import { RichTextToolbarButton } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

const NAME = 'snel/muted';

registerFormatType(NAME, {
	title: __('Muted', 'snel'),
	tagName: 'span',
	className: 'snel-muted',
	edit({ isActive, value, onChange }) {
		return (
			<RichTextToolbarButton
				icon="editor-textcolor"
				title={__('Muted', 'snel')}
				isActive={isActive}
				onClick={() => onChange(toggleFormat(value, { type: NAME }))}
			/>
		);
	},
});
