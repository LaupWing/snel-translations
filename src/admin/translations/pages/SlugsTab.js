import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

// Per-language URL slug for each custom post type's archive base. Global (not
// per-post) — set once, applies to every CPT URL in that language.
// e.g. service base "ai-diensten" → EN "ai-services": /en/ai-services/…
export default function SlugsTab() {
    const cfg = window.snelTranslations || {};
    const [ data, setData ] = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ translating, setTranslating ] = useState( false );
    const [ status, setStatus ] = useState( '' );

    useEffect( () => {
        fetch( `${ cfg.restUrl }/cpt-slugs`, { headers: { 'X-WP-Nonce': cfg.nonce } } )
            .then( ( r ) => r.json() )
            .then( setData )
            .catch( () => setStatus( __( 'Could not load.', 'snel' ) ) );
    }, [] );

    const setSlug = ( idx, lang, value ) => {
        setData( ( prev ) => {
            const items = prev.items.map( ( it, i ) => (
                i === idx ? { ...it, translations: { ...it.translations, [ lang ]: value } } : it
            ) );
            return { ...prev, items };
        } );
    };

    // AI-fill empty slug fields (keeps anything you already typed).
    const translateAll = async () => {
        setTranslating( true );
        setStatus( '' );
        try {
            const res = await fetch( `${ cfg.restUrl }/cpt-slugs/translate`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            } );
            const suggestions = await res.json();
            if ( ! suggestions || suggestions.code ) {
                setStatus( __( 'Translation failed.', 'snel' ) );
            } else {
                setData( ( prev ) => ( {
                    ...prev,
                    items: prev.items.map( ( it ) => {
                        const sug = suggestions[ it.defaultSlug ] || {};
                        const translations = { ...it.translations };
                        prev.langs.forEach( ( l ) => {
                            if ( ! translations[ l ] && sug[ l ] ) {
                                translations[ l ] = sug[ l ];
                            }
                        } );
                        return { ...it, translations };
                    } ),
                } ) );
                setStatus( __( 'Filled — review, then Save.', 'snel' ) );
            }
        } catch ( e ) {
            setStatus( __( 'Request failed.', 'snel' ) );
        }
        setTranslating( false );
    };

    const save = async () => {
        setSaving( true );
        setStatus( '' );
        const payload = {};
        data.items.forEach( ( it ) => {
            payload[ it.defaultSlug ] = it.translations;
        } );
        try {
            const res = await fetch( `${ cfg.restUrl }/cpt-slugs`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify( payload ),
            } );
            const j = await res.json();
            setStatus( j && j.success ? __( 'Saved. URLs updated.', 'snel' ) : __( 'Could not save.', 'snel' ) );
        } catch ( e ) {
            setStatus( __( 'Request failed.', 'snel' ) );
        }
        setSaving( false );
    };

    if ( status && ! data ) {
        return <p className="text-sm text-red-600">{ status }</p>;
    }
    if ( ! data ) {
        return <p className="text-sm text-gray-500">{ __( 'Loading…', 'snel' ) }</p>;
    }
    if ( ! data.langs.length ) {
        return <p className="text-sm text-gray-400">{ __( 'Add a second language first.', 'snel' ) }</p>;
    }
    if ( ! data.items.length ) {
        return <p className="text-sm text-gray-400">{ __( 'No custom post types found.', 'snel' ) }</p>;
    }

    return (
        <div className="max-w-3xl">
            <p className="text-sm text-gray-500 mb-5">
                { __( 'Translate the URL base of each custom post type per language. Leave blank to keep the default slug. Individual post slugs are set on each translated post.', 'snel' ) }
            </p>

            <div className="border border-gray-200 rounded-lg overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th className="px-4 py-2.5 font-semibold">{ __( 'Post type', 'snel' ) }</th>
                            <th className="px-4 py-2.5 font-semibold">{ __( 'Default (source)', 'snel' ) }</th>
                            { data.langs.map( ( l ) => (
                                <th key={ l } className="px-4 py-2.5 font-semibold uppercase">{ l }</th>
                            ) ) }
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        { data.items.map( ( it, idx ) => (
                            <tr key={ it.postType }>
                                <td className="px-4 py-2.5 font-medium text-gray-800">{ it.label }</td>
                                <td className="px-4 py-2.5">
                                    <code className="text-xs text-gray-500">/{ it.defaultSlug }/</code>
                                </td>
                                { data.langs.map( ( l ) => (
                                    <td key={ l } className="px-4 py-2">
                                        <input
                                            type="text"
                                            value={ it.translations[ l ] || '' }
                                            placeholder={ it.defaultSlug }
                                            onChange={ ( e ) => setSlug( idx, l, e.target.value ) }
                                            className="w-full rounded border border-gray-300 px-2 py-1 text-sm"
                                        />
                                    </td>
                                ) ) }
                            </tr>
                        ) ) }
                    </tbody>
                </table>
            </div>

            <div className="mt-4 flex items-center gap-3">
                <Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving || translating }>
                    { __( 'Save', 'snel' ) }
                </Button>
                <Button variant="secondary" onClick={ translateAll } isBusy={ translating } disabled={ saving || translating }>
                    { __( 'Translate all', 'snel' ) }
                </Button>
                { status && <span className="text-sm text-gray-600">{ status }</span> }
            </div>
        </div>
    );
}
