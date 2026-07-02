import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Save, ChevronDown, ChevronRight, Search, Languages, Loader2 } from 'lucide-react';
import Highlight from './Highlight';
import EditableCell from './EditableCell';

/**
 * Translate an array of { text, dutchKey, langCode } items via the AJAX endpoint.
 * Calls the endpoint per target language (batched).
 */
async function translateTexts( items, sourceLang, onProgress ) {
    const byLang = {};
    items.forEach( ( item ) => {
        if ( ! byLang[ item.langCode ] ) byLang[ item.langCode ] = [];
        byLang[ item.langCode ].push( item );
    } );

    const results = [];
    const langCodes = Object.keys( byLang );

    for ( let i = 0; i < langCodes.length; i++ ) {
        const lang = langCodes[ i ];
        const batch = byLang[ lang ];
        const texts = batch.map( ( b ) => b.text );

        onProgress?.( { lang, done: i, total: langCodes.length } );

        const formData = new FormData();
        formData.append( 'action', 'snel_translate' );
        formData.append( 'nonce', window.snelTranslate?.nonce || '' );
        formData.append( 'source', sourceLang );
        formData.append( 'target', lang );
        texts.forEach( ( t ) => formData.append( 'texts[]', t ) );

        try {
            const res = await fetch( window.snelTranslate?.ajaxUrl || window.ajaxurl, {
                method: 'POST',
                body: formData,
            } );
            const data = await res.json();
            if ( data.success && data.data?.translations ) {
                batch.forEach( ( item, idx ) => {
                    results.push( { ...item, translation: data.data.translations[ idx ] } );
                } );
            }
        } catch { /* ignore failed lang */ }
    }

    onProgress?.( { done: langCodes.length, total: langCodes.length } );
    return results;
}

/**
 * Reusable translation grid — shows grouped strings with per-language inputs.
 * Used by both Theme Strings and Blocks tabs.
 */
export default function TranslationGrid( { dataKey, initialSearch = '' } ) {
    const languages = window.snelTranslations?.languages || [];
    const defaultLang = window.snelTranslations?.defaultLang || 'nl';
    const nonDefaultLangs = languages.filter( ( l ) => ! l.default );

    const [ grouped, setGrouped ] = useState( () => window.snelTranslations?.[ dataKey ] || {} );
    const [ saving, setSaving ] = useState( false );
    const [ notice, setNotice ] = useState( null );
    const [ collapsed, setCollapsed ] = useState( {} );
    const [ searchQuery, setSearchQuery ] = useState( initialSearch );
    const [ translating, setTranslating ] = useState( null ); // null | 'all' | section name | dutchKey

    const toggleSection = ( section ) => {
        setCollapsed( ( prev ) => ( { ...prev, [ section ]: ! prev[ section ] } ) );
    };

    const updateTranslation = ( dutchKey, lang, value ) => {
        setGrouped( ( prev ) => {
            const next = { ...prev };
            for ( const section in next ) {
                if ( dutchKey in next[ section ] ) {
                    next[ section ] = {
                        ...next[ section ],
                        [ dutchKey ]: {
                            ...next[ section ][ dutchKey ],
                            [ lang ]: value,
                        },
                    };
                    break;
                }
            }
            return next;
        } );
    };

    const applyTranslations = ( results ) => {
        setGrouped( ( prev ) => {
            const next = { ...prev };
            results.forEach( ( r ) => {
                for ( const section in next ) {
                    if ( r.dutchKey in next[ section ] ) {
                        next[ section ] = {
                            ...next[ section ],
                            [ r.dutchKey ]: {
                                ...next[ section ][ r.dutchKey ],
                                [ r.langCode ]: r.translation,
                            },
                        };
                        break;
                    }
                }
            } );
            return next;
        } );
    };

    // Collect ALL translations for a given scope (re-translates everything).
    const getTranslatable = ( scope ) => {
        const items = [];
        const sections = scope === 'all' ? Object.keys( grouped ) : [ scope ];

        sections.forEach( ( section ) => {
            if ( ! grouped[ section ] ) return;
            const keys = scope !== 'all' && scope !== section && grouped[ section ]?.[ scope ]
                ? [ scope ]
                : Object.keys( grouped[ section ] );

            keys.forEach( ( dutchKey ) => {
                const langs = grouped[ section ][ dutchKey ];
                if ( ! langs ) return;
                const sourceText = langs[ defaultLang ] || dutchKey;
                if ( ! sourceText ) return;

                nonDefaultLangs.forEach( ( l ) => {
                    items.push( { text: sourceText, dutchKey, langCode: l.code } );
                } );
            } );
        } );

        return items;
    };

    // Find which section a dutchKey belongs to (for single-row translate).
    const findSection = ( dutchKey ) => {
        for ( const section in grouped ) {
            if ( dutchKey in grouped[ section ] ) return section;
        }
        return null;
    };

    const handleTranslate = async ( scope ) => {
        let items;
        if ( scope === 'all' ) {
            items = getTranslatable( 'all' );
        } else if ( grouped[ scope ] ) {
            items = getTranslatable( scope );
        } else {
            const section = findSection( scope );
            if ( ! section ) return;
            const langs = grouped[ section ][ scope ];
            const sourceText = langs[ defaultLang ] || scope;
            items = nonDefaultLangs
                .map( ( l ) => ( { text: sourceText, dutchKey: scope, langCode: l.code } ) );
        }

        if ( ! items.length ) return;
        setTranslating( scope );

        const results = await translateTexts( items, defaultLang );
        applyTranslations( results );
        setTranslating( null );
    };

    const handleSave = async () => {
        setSaving( true );
        setNotice( null );

        const flat = {};
        for ( const section in grouped ) {
            for ( const dutchKey in grouped[ section ] ) {
                flat[ dutchKey ] = grouped[ section ][ dutchKey ];
            }
        }

        try {
            const res = await fetch( `${ window.snelTranslations.restUrl }/theme-strings`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.snelTranslations.nonce,
                },
                body: JSON.stringify( flat ),
            } );

            setNotice( res.ok
                ? { type: 'success', message: __( 'Translations saved.', 'snel' ) }
                : { type: 'error', message: __( 'Failed to save translations.', 'snel' ) }
            );
        } catch {
            setNotice( { type: 'error', message: __( 'Network error.', 'snel' ) } );
        }

        setSaving( false );
    };

    // Filter grouped data by search query.
    const query = searchQuery.toLowerCase().trim();
    const filteredGrouped = {};
    for ( const section in grouped ) {
        const filtered = {};
        for ( const dutchKey in grouped[ section ] ) {
            if ( ! query ) {
                filtered[ dutchKey ] = grouped[ section ][ dutchKey ];
                continue;
            }
            const langs = grouped[ section ][ dutchKey ];
            const matches = dutchKey.toLowerCase().includes( query )
                || Object.values( langs ).some( ( v ) => v && v.toLowerCase().includes( query ) )
                || section.toLowerCase().includes( query );
            if ( matches ) {
                filtered[ dutchKey ] = langs;
            }
        }
        if ( Object.keys( filtered ).length > 0 ) {
            filteredGrouped[ section ] = filtered;
        }
    }

    const sections = Object.keys( filteredGrouped );
    const totalStrings = sections.reduce( ( sum, s ) => sum + Object.keys( filteredGrouped[ s ] ).length, 0 );
    const missingCount = sections.reduce( ( sum, section ) => {
        return sum + Object.keys( filteredGrouped[ section ] ).reduce( ( sSum, key ) => {
            const langs = filteredGrouped[ section ][ key ];
            return sSum + nonDefaultLangs.filter( ( l ) => ! langs[ l.code ] ).length;
        }, 0 );
    }, 0 );

    const TranslateButton = ( { scope, size = 'sm', label, confirmMsg } ) => {
        const isActive = translating === scope;
        const handleClick = ( e ) => {
            e.stopPropagation();
            if ( confirmMsg && ! confirm( confirmMsg ) ) return;
            handleTranslate( scope );
        };
        return (
            <button
                onClick={ handleClick }
                disabled={ !! translating }
                className={ `flex items-center gap-1 text-xs font-medium transition-colors disabled:opacity-40 ${
                    size === 'sm'
                        ? 'px-1.5 py-0.5 text-purple-600 hover:text-purple-700 hover:bg-purple-50 rounded'
                        : 'px-3 py-1.5 text-purple-600 border border-purple-200 rounded-lg hover:bg-purple-50'
                }` }
                title={ __( '(Re)translate with AI', 'snel' ) }
            >
                { isActive ? <Loader2 size={ 12 } className="animate-spin" /> : <Languages size={ 12 } /> }
                { label && <span>{ label }</span> }
            </button>
        );
    };

    return (
        <div>
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                    <span className="text-sm text-gray-500">
                        { totalStrings } { __( 'strings', 'snel' ) }
                    </span>
                    { missingCount > 0 && (
                        <span className="px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 rounded-full">
                            { missingCount } { __( 'missing', 'snel' ) }
                        </span>
                    ) }
                </div>
                <div className="flex items-center gap-3">
                    <div className="relative">
                        <Search size={ 14 } className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input
                            type="text"
                            value={ searchQuery }
                            onChange={ ( e ) => setSearchQuery( e.target.value ) }
                            placeholder={ __( 'Search translations...', 'snel' ) }
                            className="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-blue-500 focus:shadow-[0_0_0_1px_#3b82f6] w-56"
                        />
                    </div>
                    <TranslateButton scope="all" size="lg" label={ __( 'Re-translate All', 'snel' ) } confirmMsg={ __( 'This will re-translate all strings and overwrite existing translations. Continue?', 'snel' ) } />
                    <button
                        onClick={ handleSave }
                        disabled={ saving }
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50"
                    >
                        <Save size={ 16 } />
                        { saving ? __( 'Saving...', 'snel' ) : __( 'Save Translations', 'snel' ) }
                    </button>
                </div>
            </div>

            { notice && (
                <div className={ `mb-4 px-4 py-3 rounded-lg text-sm ${ notice.type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }` }>
                    { notice.message }
                    <button onClick={ () => setNotice( null ) } className="float-right font-bold">×</button>
                </div>
            ) }

            { totalStrings === 0 && (
                <div className="bg-white border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-400 py-12">
                    { query ? __( 'No results found.', 'snel' ) : __( 'No strings found.', 'snel' ) }
                </div>
            ) }

            <div className="space-y-3">
                { sections.map( ( section ) => {
                    const strings = filteredGrouped[ section ];
                    const keys = Object.keys( strings );
                    const isCollapsed = collapsed[ section ];
                    const sectionMissing = keys.reduce( ( sum, key ) => {
                        return sum + nonDefaultLangs.filter( ( l ) => ! strings[ key ][ l.code ] ).length;
                    }, 0 );

                    return (
                        <div key={ section } className="bg-white border border-gray-200 rounded-lg overflow-hidden">
                            <button
                                onClick={ () => toggleSection( section ) }
                                className="w-full flex items-center justify-between px-4 py-3 bg-white hover:bg-gray-50 cursor-pointer transition-colors text-left"
                            >
                                <div className="flex items-center gap-2">
                                    { isCollapsed
                                        ? <ChevronRight size={ 16 } className="text-indigo-500" />
                                        : <ChevronDown size={ 16 } className="text-indigo-500" />
                                    }
                                    <span className="text-sm font-semibold text-gray-800"><Highlight text={ section } query={ query } /></span>
                                    <span className="text-xs text-gray-400">({ keys.length })</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <TranslateButton scope={ section } label={ __( 'Re-translate', 'snel' ) } confirmMsg={ __( 'Re-translate this entire group? Existing translations will be overwritten.', 'snel' ) } />
                                    { sectionMissing > 0 && (
                                        <span className="px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 rounded-full">
                                            { sectionMissing } { __( 'missing', 'snel' ) }
                                        </span>
                                    ) }
                                </div>
                            </button>

                            { ! isCollapsed && (
                                <div className="divide-y divide-gray-100">
                                    <div className="grid px-4 py-2 bg-gray-50/50 text-xs font-medium text-gray-400 uppercase tracking-wider" style={ { gridTemplateColumns: `1fr ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) } 28px` } }>
                                        <div>{ defaultLang.toUpperCase() } ({ __( 'source', 'snel' ) })</div>
                                        { nonDefaultLangs.map( ( l ) => (
                                            <div key={ l.code }>{ l.label }</div>
                                        ) ) }
                                        <div />
                                    </div>

                                    { keys.map( ( dutchKey ) => {
                                        const langs = strings[ dutchKey ];
                                        return (
                                            <div
                                                key={ dutchKey }
                                                className="grid px-4 py-2.5 gap-3 items-start"
                                                style={ { gridTemplateColumns: `1fr ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) } 28px` } }
                                            >
                                                <div className="text-sm text-gray-700 pt-1.5 font-medium break-words">
                                                    <Highlight text={ dutchKey } query={ query } />
                                                </div>

                                                { nonDefaultLangs.map( ( l ) => (
                                                    <EditableCell
                                                        key={ l.code }
                                                        value={ langs[ l.code ] || '' }
                                                        onChange={ ( v ) => updateTranslation( dutchKey, l.code, v ) }
                                                        placeholder={ dutchKey }
                                                        query={ query }
                                                        missing={ ! langs[ l.code ] }
                                                    />
                                                ) ) }

                                                <div className="pt-1">
                                                    <TranslateButton scope={ dutchKey } />
                                                </div>
                                            </div>
                                        );
                                    } ) }
                                </div>
                            ) }
                        </div>
                    );
                } ) }
            </div>
        </div>
    );
}
