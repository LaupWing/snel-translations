import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

// Lists posts written in a language that is no longer configured, and lets you
// re-add the language, or trash / delete the orphaned post.
export default function OrphansPanel() {
    const cfg = window.snelTranslations || {};
    const [ data, setData ] = useState( null );
    const [ busy, setBusy ] = useState( '' );

    const load = () => {
        fetch( `${ cfg.restUrl }/orphans`, { headers: { 'X-WP-Nonce': cfg.nonce } } )
            .then( ( r ) => r.json() )
            .then( setData )
            .catch( () => {} );
    };
    useEffect( load, [] );

    if ( ! data || ! data.posts || data.posts.length === 0 ) return null;

    const act = async ( body, key ) => {
        setBusy( key );
        try {
            await fetch( `${ cfg.restUrl }/orphan-action`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify( body ),
            } );
        } catch ( e ) { /* ignore */ }
        setBusy( '' );
        load();
    };

    return (
        <div className="max-w-xl mt-8 border-t border-gray-200 pt-6">
            <div
                className="text-sm mb-4 p-3 rounded flex gap-2"
                style={ { background: '#fdecea', borderLeft: '4px solid #d63638' } }
            >
                <span>⚠</span>
                <div>
                    <strong>{ __( 'Orphaned translations', 'snel' ) }</strong>{ ' ' }
                    { __( 'These posts are written in a language that is no longer configured. They are hidden and unreachable on the site, but NOT deleted. Re-add the language to restore them, or trash / delete them.', 'snel' ) }
                </div>
            </div>

            <p className="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2">
                { __( 'Restore a language', 'snel' ) }
            </p>
            <div className="flex flex-wrap gap-2 mb-5">
                { data.languages.map( ( l ) => (
                    <Button
                        key={ l }
                        variant="secondary"
                        isBusy={ busy === `add-${ l }` }
                        disabled={ !! busy }
                        onClick={ () => act( { action: 'add_language', lang: l }, `add-${ l }` ) }
                    >
                        { __( 'Add', 'snel' ) } “{ l.toUpperCase() }” { __( 'back', 'snel' ) }
                    </Button>
                ) ) }
            </div>

            <p className="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2">
                { __( 'Orphaned posts', 'snel' ) }
            </p>
            <div className="border border-gray-200 rounded divide-y divide-gray-100">
                { data.posts.map( ( p ) => (
                    <div key={ p.id } className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span className="min-w-0">
                            <strong className="uppercase text-amber-700 text-xs mr-2">{ p.lang }</strong>
                            { p.title }
                            <span className="text-gray-400 text-xs ml-2">#{ p.id } · { p.type } · { p.status }</span>
                        </span>
                        <span className="flex gap-3 whitespace-nowrap">
                            { p.editUrl && (
                                <a href={ p.editUrl } className="text-xs">{ __( 'Edit', 'snel' ) }</a>
                            ) }
                            <button
                                className="text-xs text-gray-600 hover:text-gray-900"
                                disabled={ !! busy }
                                onClick={ () => act( { action: 'trash', postId: p.id }, `trash-${ p.id }` ) }
                            >
                                { __( 'Trash', 'snel' ) }
                            </button>
                            <button
                                className="text-xs text-red-600 hover:text-red-800"
                                disabled={ !! busy }
                                onClick={ () => {
                                    if ( window.confirm( __( 'Permanently delete this post? This cannot be undone.', 'snel' ) ) ) {
                                        act( { action: 'delete', postId: p.id }, `del-${ p.id }` );
                                    }
                                } }
                            >
                                { __( 'Delete', 'snel' ) }
                            </button>
                        </span>
                    </div>
                ) ) }
            </div>
        </div>
    );
}
