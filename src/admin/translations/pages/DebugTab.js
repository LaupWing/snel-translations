import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ChevronRight, ChevronDown, Copy, Check } from 'lucide-react';

// Read-only JSON view: WP CodeMirror when available, plain textarea otherwise.
// Includes a Copy button for whatever is currently shown.
function JsonBlock( { data, rows = 12 } ) {
    const text = JSON.stringify( data, null, 2 );
    const taRef = useRef( null );
    const cmRef = useRef( null );
    const [ copied, setCopied ] = useState( false );

    useEffect( () => {
        if ( ! taRef.current ) return;

        // Already have a CodeMirror instance → just push the new value.
        if ( cmRef.current ) {
            cmRef.current.codemirror.setValue( text );
            return;
        }

        const wpCE = window.wp && window.wp.codeEditor;
        if ( wpCE ) {
            try {
                taRef.current.value = text;
                const base = wpCE.defaultSettings || {};
                cmRef.current = wpCE.initialize( taRef.current, {
                    ...base,
                    codemirror: { ...( base.codemirror || {} ), readOnly: true, lineNumbers: true },
                } );
                return;
            } catch ( e ) { /* fall through to plain textarea */ }
        }
        taRef.current.value = text;
    }, [ text ] );

    const copy = () => {
        const done = () => { setCopied( true ); setTimeout( () => setCopied( false ), 1500 ); };
        if ( navigator.clipboard ) {
            navigator.clipboard.writeText( text ).then( done ).catch( done );
        } else if ( taRef.current ) {
            taRef.current.select();
            document.execCommand( 'copy' );
            done();
        }
    };

    return (
        <div className="mt-3">
            <div className="flex justify-end mb-1">
                <button
                    onClick={ copy }
                    className="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800"
                >
                    { copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" /> }
                    { copied ? __( 'Copied!', 'snel' ) : __( 'Copy', 'snel' ) }
                </button>
            </div>
            <textarea
                ref={ taRef }
                defaultValue={ text }
                readOnly
                spellCheck={ false }
                rows={ rows }
                className="w-full text-xs p-3 border border-gray-200 rounded"
                style={ { fontFamily: 'monospace', whiteSpace: 'pre' } }
            />
        </div>
    );
}

function Section( { title, count, defaultOpen = false, children } ) {
    const [ open, setOpen ] = useState( defaultOpen );
    return (
        <div className="border border-gray-200 rounded-lg mb-2 overflow-hidden bg-white">
            <button
                onClick={ () => setOpen( ! open ) }
                className="w-full flex items-center gap-2 px-4 py-2.5 text-left text-sm font-semibold text-gray-800 bg-white hover:bg-gray-50 cursor-pointer transition-colors"
            >
                { open
                    ? <ChevronDown className="w-4 h-4 text-indigo-500" />
                    : <ChevronRight className="w-4 h-4 text-indigo-500" /> }
                <span>{ title }</span>
                { count !== undefined && (
                    <span className="ml-1 text-xs font-normal text-gray-400">({ count })</span>
                ) }
            </button>
            { open && <div className="px-4 pb-4 border-t border-gray-100">{ children }</div> }
        </div>
    );
}

// Translation data with Grouped / Flat / DB-rows views of the same links.
function TranslationData( { groups, rows, metaRows } ) {
    const [ mode, setMode ] = useState( 'grouped' );
    const pill = ( on ) =>
        `text-xs px-2.5 py-1 rounded-full font-medium ${
            on ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        }`;

    const dataFor = { grouped: groups, flat: rows, db: metaRows };
    const desc = {
        grouped: __( 'Posts bucketed by their _snel_group meta (a "group" = all posts sharing a group id).', 'snel' ),
        flat: __( 'One object per post — wp_posts joined with its _snel_lang / _snel_group meta. Tidy, not literal.', 'snel' ),
        db: __( 'The literal wp_postmeta rows — exactly how the links are stored in the database.', 'snel' ),
    };

    return (
        <div>
            <div className="flex gap-1.5 mt-3">
                <button onClick={ () => setMode( 'grouped' ) } className={ pill( mode === 'grouped' ) }>
                    { __( 'Grouped', 'snel' ) }
                </button>
                <button onClick={ () => setMode( 'flat' ) } className={ pill( mode === 'flat' ) }>
                    { __( 'Flat', 'snel' ) }
                </button>
                <button onClick={ () => setMode( 'db' ) } className={ pill( mode === 'db' ) }>
                    { __( 'DB rows', 'snel' ) }
                </button>
            </div>
            <p className="text-xs text-gray-400 mt-2">{ desc[ mode ] }</p>
            <JsonBlock data={ dataFor[ mode ] } rows={ 18 } />
        </div>
    );
}

export default function DebugTab() {
    const cfg = window.snelTranslations || {};
    const [ data, setData ] = useState( null );
    const [ error, setError ] = useState( '' );

    useEffect( () => {
        fetch( `${ cfg.restUrl }/debug`, { headers: { 'X-WP-Nonce': cfg.nonce } } )
            .then( ( r ) => r.json() )
            .then( setData )
            .catch( () => setError( __( 'Could not load debug data.', 'snel' ) ) );
    }, [] );

    if ( error ) {
        return <p className="text-sm text-red-600">{ error }</p>;
    }
    if ( ! data ) {
        return <p className="text-sm text-gray-500">{ __( 'Loading…', 'snel' ) }</p>;
    }

    const groups = data.translationGroups || [];
    const rows = data.translationRows || [];

    return (
        <div className="max-w-3xl">
            <p className="text-sm text-gray-500 mb-4">
                { __( 'Read-only view of the current translation data in the database — for debugging, so you can inspect it without opening SQL.', 'snel' ) }
            </p>

            <Section title={ __( 'Translation data', 'snel' ) } count={ rows.length } defaultOpen>
                <TranslationData groups={ groups } rows={ rows } metaRows={ data.metaRows || [] } />
            </Section>

            <Section title={ __( 'Languages config', 'snel' ) }>
                <JsonBlock data={ data.languagesConfig } />
            </Section>

            <Section title={ __( 'Default / enabled', 'snel' ) }>
                <JsonBlock data={ { defaultLang: data.defaultLang, enabledLangs: data.enabledLangs } } rows={ 6 } />
            </Section>

            <Section title={ __( 'Theme strings', 'snel' ) }>
                <JsonBlock data={ data.themeStrings } rows={ 16 } />
            </Section>
        </div>
    );
}
