import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import MenuTab from './MenuTab';
import TranslationGrid from '../components/TranslationGrid';

// One tab holding both string editors: the Menu labels and the Theme strings.
// Both save to the same theme-strings store and both have AI translate.
export default function StringsTab() {
    const [ sub, setSub ] = useState( 'menu' );

    const pill = ( on ) =>
        `text-sm px-3 py-1.5 rounded-lg font-medium transition-colors ${
            on ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        }`;

    return (
        <div>
            <div className="flex gap-2 mb-5">
                <button className={ pill( sub === 'menu' ) } onClick={ () => setSub( 'menu' ) }>
                    { __( 'Menu', 'snel' ) }
                </button>
                <button className={ pill( sub === 'theme' ) } onClick={ () => setSub( 'theme' ) }>
                    { __( 'Theme strings', 'snel' ) }
                </button>
            </div>

            { sub === 'menu' && <MenuTab /> }
            { sub === 'theme' && <TranslationGrid dataKey="themeStrings" /> }
        </div>
    );
}
