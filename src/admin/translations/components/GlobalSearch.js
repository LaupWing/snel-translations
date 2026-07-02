import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Search, X, Loader2 } from 'lucide-react';
import TABS from '../config/tabs';

const TAB_META = {};
TABS.forEach( ( t ) => { TAB_META[ t.id ] = t; } );

function highlight( text, query ) {
    if ( ! query || ! text ) return text || '';
    const str = String( text );
    const idx = str.toLowerCase().indexOf( query.toLowerCase() );
    if ( idx === -1 ) return str;
    return (
        <>
            { str.slice( 0, idx ) }
            <mark className="bg-yellow-200 rounded-sm px-0.5">{ str.slice( idx, idx + query.length ) }</mark>
            { str.slice( idx + query.length ) }
        </>
    );
}

export default function GlobalSearch( { onNavigate } ) {
    const [ open, setOpen ] = useState( false );
    const [ query, setQuery ] = useState( '' );
    const [ results, setResults ] = useState( [] );
    const [ loading, setLoading ] = useState( false );
    const [ allData, setAllData ] = useState( null );
    const inputRef = useRef();

    // Keyboard shortcut: Cmd+K or Ctrl+K.
    useEffect( () => {
        const handler = ( e ) => {
            if ( ( e.metaKey || e.ctrlKey ) && e.key === 'k' ) {
                e.preventDefault();
                setOpen( ( prev ) => ! prev );
            }
            if ( e.key === 'Escape' ) setOpen( false );
        };
        document.addEventListener( 'keydown', handler );
        return () => document.removeEventListener( 'keydown', handler );
    }, [] );

    // Focus input when opening.
    useEffect( () => {
        if ( open && inputRef.current ) {
            inputRef.current.focus();
            if ( ! allData ) loadAllData();
        }
    }, [ open ] );

    const loadAllData = async () => {
        setLoading( true );
        const items = [];

        // Theme strings (already loaded).
        const themeStrings = window.snelTranslations?.themeStrings || {};
        for ( const section in themeStrings ) {
            for ( const key in themeStrings[ section ] ) {
                const vals = themeStrings[ section ][ key ];
                items.push( {
                    tab: 'theme',
                    section,
                    key,
                    searchText: [ key, ...Object.values( vals ) ].join( ' ' ),
                    display: key,
                    detail: section,
                } );
            }
        }

        // Menu items (already loaded — flat array).
        const menuItems = window.snelTranslations?.menuItems || [];
        ( Array.isArray( menuItems ) ? menuItems : [] ).forEach( ( item ) => {
            const vals = Object.values( item.translations || {} );
            items.push( {
                tab: 'menu',
                key: item.title,
                searchText: [ item.title, item.menu || '', ...vals ].join( ' ' ),
                display: item.title,
                detail: item.menu || 'Menu',
            } );
        } );

        // Pages (fetch from REST).
        try {
            const res = await fetch( `${ window.snelTranslations.restUrl }/pages`, {
                headers: { 'X-WP-Nonce': window.snelTranslations.nonce },
            } );
            const pages = await res.json();
            ( Array.isArray( pages ) ? pages : [] ).forEach( ( page ) => {
                ( page.blocks || [] ).forEach( ( block ) => {
                    ( block.attributes || [] ).forEach( ( attr ) => {
                        const vals = Object.values( attr.values || {} );
                        items.push( {
                            tab: 'pages',
                            key: attr.key,
                            pageId: page.id,
                            searchText: [ page.title, block.label, attr.key, ...vals ].join( ' ' ),
                            display: `${ attr.values?.nl || attr.key }`,
                            detail: `${ page.title } — ${ block.label }`,
                        } );
                    } );
                } );
            } );
        } catch { /* ignore */ }

        setAllData( items );
        setLoading( false );
    };

    // Filter results when query changes.
    useEffect( () => {
        if ( ! allData || ! query.trim() ) {
            setResults( [] );
            return;
        }
        const q = query.toLowerCase();
        const matched = allData.filter( ( item ) => item.searchText.toLowerCase().includes( q ) );
        setResults( matched.slice( 0, 50 ) );
    }, [ query, allData ] );

    if ( ! open ) {
        return (
            <button
                onClick={ () => setOpen( true ) }
                className="flex items-center gap-2 px-3 py-1.5 text-sm text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
            >
                <Search size={ 14 } />
                <span>{ __( 'Search all...', 'snel' ) }</span>
                <kbd className="hidden md:inline text-[10px] font-mono text-gray-400 bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 ml-2">⌘K</kbd>
            </button>
        );
    }

    // Group results by tab.
    const grouped = {};
    results.forEach( ( r ) => {
        if ( ! grouped[ r.tab ] ) grouped[ r.tab ] = [];
        grouped[ r.tab ].push( r );
    } );

    return (
        <div className="fixed inset-0 z-[100000] flex items-start justify-center pt-[10vh]" onClick={ () => setOpen( false ) }>
            <div className="fixed inset-0 bg-black/40 backdrop-blur-sm" />
            <div
                className="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[70vh] flex flex-col overflow-hidden"
                onClick={ ( e ) => e.stopPropagation() }
            >
                {/* Search input */}
                <div className="flex items-center gap-3 px-5 py-4 border-b border-gray-200">
                    <Search size={ 20 } className="text-gray-400 shrink-0" />
                    <input
                        ref={ inputRef }
                        type="text"
                        value={ query }
                        onChange={ ( e ) => setQuery( e.target.value ) }
                        placeholder={ __( 'Search translations across all tabs...', 'snel' ) }
                        className="flex-1 text-base bg-transparent placeholder-gray-400"
                        style={ { border: 'none', outline: 'none', boxShadow: 'none', padding: 0, margin: 0, height: 'auto', minHeight: 0 } }
                    />
                    { loading && <Loader2 size={ 16 } className="animate-spin text-gray-400" /> }
                    <button onClick={ () => setOpen( false ) } className="p-1 text-gray-400 hover:text-gray-600 transition-colors">
                        <X size={ 16 } />
                    </button>
                </div>

                {/* Results */}
                <div className="flex-1 overflow-y-auto">
                    { ! query.trim() && (
                        <div className="px-4 py-8 text-center text-sm text-gray-400">
                            { __( 'Start typing to search across all translations...', 'snel' ) }
                        </div>
                    ) }

                    { query.trim() && ! loading && results.length === 0 && (
                        <div className="px-4 py-8 text-center text-sm text-gray-400">
                            { __( 'No results found', 'snel' ) }
                        </div>
                    ) }

                    { Object.entries( grouped ).map( ( [ tab, items ] ) => {
                        const meta = TAB_META[ tab ] || {};
                        const Icon = meta.icon || Search;
                        return (
                            <div key={ tab }>
                                <div className="px-4 py-2 bg-gray-50 border-b border-gray-100 flex items-center gap-2">
                                    <Icon size={ 12 } className={ meta.color?.split( ' ' )[ 0 ] || 'text-gray-500' } />
                                    <span className="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">
                                        { meta.label } ({ items.length })
                                    </span>
                                </div>
                                { items.map( ( item, i ) => (
                                    <button
                                        key={ `${ tab }-${ i }` }
                                        className="w-full text-left px-4 py-2.5 hover:bg-gray-50 transition-colors border-b border-gray-50 flex items-center gap-3"
                                        onClick={ () => {
                                            onNavigate( tab, query, item );
                                            setOpen( false );
                                            setQuery( '' );
                                        } }
                                    >
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm text-gray-800 truncate">
                                                { highlight( item.display, query ) }
                                            </p>
                                            <p className="text-xs text-gray-400 truncate">
                                                { highlight( item.detail, query ) }
                                            </p>
                                        </div>
                                        <span className={ `shrink-0 text-[10px] font-medium px-1.5 py-0.5 rounded ${ meta.color || 'text-gray-500 bg-gray-100' }` }>
                                            { meta.label }
                                        </span>
                                    </button>
                                ) ) }
                            </div>
                        );
                    } ) }
                </div>

                {/* Footer */}
                { results.length > 0 && (
                    <div className="px-4 py-2 border-t border-gray-200 bg-gray-50 flex items-center justify-between">
                        <span className="text-[11px] text-gray-400">
                            { results.length }{ results.length >= 50 ? '+' : '' } { __( 'results', 'snel' ) }
                        </span>
                        <span className="text-[11px] text-gray-400">
                            { __( 'Click to navigate', 'snel' ) } · ESC { __( 'to close', 'snel' ) }
                        </span>
                    </div>
                ) }
            </div>
        </div>
    );
}
