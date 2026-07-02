import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SelectControl, Button } from '@wordpress/components';

export default function SettingsTab() {
    const data = window.snelTranslations || {};
    const langs = data.languages || []; // [{ code, label, default, enabled }]

    const [ defaultLang, setDefaultLang ] = useState(
        data.defaultLang || ( langs[ 0 ] && langs[ 0 ].code ) || ''
    );
    const [ enabled, setEnabled ] = useState(
        langs.filter( ( l ) => l.enabled ).map( ( l ) => l.code )
    );
    const [ busy, setBusy ] = useState( false );
    const [ status, setStatus ] = useState( '' );

    // Languages JSON editor (collapsible).
    const [ showJson, setShowJson ] = useState( false );
    const [ json, setJson ] = useState( '' );
    const [ jsonLoaded, setJsonLoaded ] = useState( false );
    const [ jsonBusy, setJsonBusy ] = useState( false );
    const [ jsonStatus, setJsonStatus ] = useState( '' );

    const loadJson = async () => {
        try {
            const res = await fetch( `${ data.restUrl }/languages-config`, { headers: { 'X-WP-Nonce': data.nonce } } );
            const j = await res.json();
            setJson( j.json || '' );
            setJsonLoaded( true );
        } catch ( e ) {
            setJsonStatus( __( 'Could not load.', 'snel' ) );
        }
    };

    const textareaRef = useRef( null );
    const cmRef = useRef( null );
    const seededRef = useRef( false );

    // Enhance the textarea with WP's CodeMirror once the editor is open and the
    // JSON has loaded. Falls back to a plain textarea if the code editor is off.
    useEffect( () => {
        if ( ! showJson || ! jsonLoaded || ! textareaRef.current || seededRef.current ) return;
        seededRef.current = true;
        textareaRef.current.value = json;

        const wpCE = window.wp && window.wp.codeEditor;
        if ( wpCE ) {
            try {
                const ed = wpCE.initialize( textareaRef.current, wpCE.defaultSettings || {} );
                cmRef.current = ed;
                ed.codemirror.on( 'change', () => setJson( ed.codemirror.getValue() ) );
            } catch ( e ) { /* fall back to the plain textarea */ }
        }
    }, [ showJson, jsonLoaded ] );

    const toggleJson = () => {
        const next = ! showJson;
        if ( ! next ) {
            // Editor DOM unmounts on collapse — reset so it re-inits on reopen.
            cmRef.current = null;
            seededRef.current = false;
        }
        setShowJson( next );
        if ( next && ! jsonLoaded ) loadJson();
    };

    const downloadJson = () => {
        const blob = new Blob( [ json ], { type: 'application/json' } );
        const url = URL.createObjectURL( blob );
        const a = document.createElement( 'a' );
        a.href = url;
        a.download = 'languages.json';
        a.click();
        URL.revokeObjectURL( url );
    };

    const fileInputRef = useRef( null );

    const importJson = () => {
        if ( fileInputRef.current ) fileInputRef.current.click();
    };

    const onImportFile = ( e ) => {
        const file = e.target.files && e.target.files[ 0 ];
        if ( ! file ) return;
        const reader = new FileReader();
        reader.onload = () => {
            const text = String( reader.result || '' );
            setJson( text );
            if ( cmRef.current ) {
                cmRef.current.codemirror.setValue( text );
            } else if ( textareaRef.current ) {
                textareaRef.current.value = text;
            }
            setJsonStatus( __( 'Imported. Review, then Save languages.', 'snel' ) );
        };
        reader.readAsText( file );
        e.target.value = ''; // allow re-importing the same file
    };

    const saveJson = async () => {
        setJsonBusy( true );
        setJsonStatus( '' );
        try {
            const res = await fetch( `${ data.restUrl }/languages-config`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
                body: JSON.stringify( { json } ),
            } );
            const j = await res.json();
            if ( j && j.success ) {
                setJsonStatus( __( 'Saved. Reload the page to apply.', 'snel' ) );
            } else {
                setJsonStatus( ( j && j.message ) || __( 'Could not save.', 'snel' ) );
            }
        } catch ( e ) {
            setJsonStatus( __( 'Request failed.', 'snel' ) );
        }
        setJsonBusy( false );
    };

    const isOn = ( code ) => code === defaultLang || enabled.includes( code );

    const toggle = ( code ) => {
        if ( code === defaultLang ) return; // default is always on
        setEnabled( ( prev ) =>
            prev.includes( code ) ? prev.filter( ( c ) => c !== code ) : [ ...prev, code ]
        );
    };

    const save = async () => {
        setBusy( true );
        setStatus( '' );
        // Default must always be enabled.
        const enabledLangs = Array.from( new Set( [ ...enabled, defaultLang ] ) );
        try {
            const res = await fetch( `${ data.restUrl }/settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
                body: JSON.stringify( { defaultLang, enabledLangs } ),
            } );
            const json = await res.json();
            setStatus( json && json.success ? __( 'Saved.', 'snel' ) : __( 'Could not save.', 'snel' ) );
        } catch ( e ) {
            setStatus( __( 'Request failed.', 'snel' ) );
        }
        setBusy( false );
    };

    // The default dropdown only offers enabled languages.
    const defaultOptions = langs
        .filter( ( l ) => isOn( l.code ) )
        .map( ( l ) => ( { label: l.label, value: l.code } ) );

    return (
        <div className="max-w-xl">
            {/* Enabled languages */}
            <div className="mb-6">
                <p className="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2">
                    { __( 'Enabled languages', 'snel' ) }
                </p>
                <div className="flex flex-wrap gap-x-5 gap-y-2">
                    { langs.map( ( l ) => (
                        <label
                            key={ l.code }
                            className={ `inline-flex items-center gap-2 text-sm ${
                                l.code === defaultLang ? 'text-gray-400' : 'text-gray-700 cursor-pointer'
                            }` }
                        >
                            <input
                                type="checkbox"
                                checked={ isOn( l.code ) }
                                disabled={ l.code === defaultLang }
                                onChange={ () => toggle( l.code ) }
                            />
                            <strong>{ l.label }{ l.code === defaultLang ? ' · src' : '' }</strong>
                        </label>
                    ) ) }
                </div>
                <p className="text-xs text-gray-400 mt-2">
                    { __( 'Turn languages on/off. The default (source) language is always on. Add or edit the language list in the JSON editor below.', 'snel' ) }
                </p>
            </div>

            {/* Default language */}
            <SelectControl
                label={ __( 'Default (source) language', 'snel' ) }
                value={ defaultLang }
                options={ defaultOptions }
                onChange={ setDefaultLang }
                __nextHasNoMarginBottom
            />
            <p className="text-xs text-gray-400 mt-1 mb-4">
                { __( 'The language content is written in — no URL prefix; all others get one (e.g. /en/).', 'snel' ) }
            </p>

            { data.translationsExist && (
                <p className="text-sm mb-4 p-2.5 rounded" style={ { background: '#fcf3e3', borderLeft: '4px solid #dba617' } }>
                    <strong>⚠ { __( 'Translations already exist.', 'snel' ) }</strong>{ ' ' }
                    { __( 'Changing the default language moves every URL (the prefix shifts) — best done before launch.', 'snel' ) }
                </p>
            ) }

            <Button variant="primary" onClick={ save } isBusy={ busy } disabled={ busy }>
                { __( 'Save', 'snel' ) }
            </Button>
            { status && <span className="ml-3 text-sm text-gray-600">{ status }</span> }

            {/* Languages JSON editor (advanced) */}
            <div className="mt-8 border-t border-gray-200 pt-5">
                <button
                    onClick={ toggleJson }
                    className="flex items-center gap-1.5 text-sm font-semibold text-gray-700"
                >
                    <span className="text-gray-400">{ showJson ? '▾' : '▸' }</span>
                    { __( 'Edit languages (JSON)', 'snel' ) }
                </button>

                { showJson && (
                    <div className="mt-3">
                        <div
                            className="text-sm mb-3 p-3 rounded"
                            style={ { background: '#fdecea', borderLeft: '4px solid #d63638' } }
                        >
                            <strong>⚠ { __( 'Danger — read first.', 'snel' ) }</strong>{ ' ' }
                            { __( 'This rewrites the site’s language list. Removing a language does NOT delete its pages — you must remove those yourself (that cleanup is not automated yet). Renaming a code breaks the existing URLs for that language. Save, then reload the admin.', 'snel' ) }
                        </div>

                        <textarea
                            ref={ textareaRef }
                            defaultValue={ json }
                            onChange={ ( e ) => setJson( e.target.value ) }
                            spellCheck={ false }
                            rows={ 14 }
                            className="w-full text-xs p-3 border border-gray-300 rounded"
                            style={ { fontFamily: 'monospace', whiteSpace: 'pre', tabSize: 2 } }
                        />

                        <div className="mt-2 flex items-center gap-3">
                            <Button variant="primary" onClick={ saveJson } isBusy={ jsonBusy } disabled={ jsonBusy }>
                                { __( 'Save languages', 'snel' ) }
                            </Button>
                            <Button variant="secondary" onClick={ downloadJson }>
                                { __( 'Download .json', 'snel' ) }
                            </Button>
                            <Button variant="secondary" onClick={ importJson }>
                                { __( 'Import .json', 'snel' ) }
                            </Button>
                            <input
                                ref={ fileInputRef }
                                type="file"
                                accept="application/json,.json"
                                onChange={ onImportFile }
                                style={ { display: 'none' } }
                            />
                            { jsonStatus && <span className="text-sm text-gray-600">{ jsonStatus }</span> }
                        </div>

                        <p className="text-xs text-gray-400 mt-2">
                            { __( 'Format: { "nl": { "label": "NL", "locale": "nl_NL", "default": true }, "en": { "label": "EN", "locale": "en_US" } }. Exactly one language must be "default": true. Clear the box and save to revert to the theme default.', 'snel' ) }
                        </p>
                    </div>
                ) }
            </div>
        </div>
    );
}
