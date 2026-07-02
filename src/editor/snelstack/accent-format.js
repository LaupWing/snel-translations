/**
 * "Accent" inline format (snel/accent).
 *
 * A toolbar toggle that wraps selected text in <span class="snel-accent">.
 * Colour is defined in theme.css (.snel-accent = pink-400) and works on both
 * light and dark backgrounds.
 */
import { registerFormatType, toggleFormat } from '@wordpress/rich-text';
import { RichTextToolbarButton } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

const NAME = 'snel/accent';

registerFormatType(NAME, {
	title: __('Accent', 'snel'),
	tagName: 'span',
	className: 'snel-accent',
	edit({ isActive, value, onChange }) {
		return (
			<RichTextToolbarButton
				icon="star-filled"
				title={__('Accent', 'snel')}
				isActive={isActive}
				onClick={() => onChange(toggleFormat(value, { type: NAME }))}
			/>
		);
	},
});
