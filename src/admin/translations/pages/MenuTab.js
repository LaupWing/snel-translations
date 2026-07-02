import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Save, ExternalLink, Search, Languages, Loader2 } from 'lucide-react';
import Highlight from '../components/Highlight';
import EditableCell from '../components/EditableCell';

export default function MenuTab( { initialSearch = '' } ) {
    const languages = window.snelTranslations?.languages || [];
    const defaultLang = window.snelTranslations?.defaultLang || 'nl';
    const nonDefaultLangs = languages.filter( ( l ) => ! l.default );
    const menuEditUrl = window.snelTranslations?.menuEditUrl || '';

    const [ items, setItems ] = useState( () => window.snelTranslations?.menuItems || [] );
    const [ saving, setSaving ] = useState( false );
    const [ aiBusy, setAiBusy ] = useState( false );
    const [ notice, setNotice ] = useState( null );
    const [ searchQuery, setSearchQuery ] = useState( initialSearch );

    // Fill missing menu labels for every language via the AI translate endpoint.
    const aiTranslate = async () => {
        setAiBusy( true );
        setNotice( null );
        try {
            for ( const l of nonDefaultLangs ) {
                // Unique source titles still missing a translation in this language.
                const titles = [ ...new Set(
                    items
                        .filter( ( it ) => ! ( ( it.translations || {} )[ l.code ] || '' ).trim() )
                        .map( ( it ) => it.title )
                ) ];
                if ( ! titles.length ) continue;

                const form = new URLSearchParams();
                form.append( 'action', 'snel_translate' );
                form.append( 'nonce', window.snelTranslate?.nonce || '' );
                form.append( 'source', defaultLang );
                form.append( 'target', l.code );
                titles.forEach( ( t ) => form.append( 'texts[]', t ) );

                const res = await fetch( window.snelTranslate?.ajaxUrl || window.ajaxurl, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                } );
                const json = await res.json();
                const out = json?.data?.translations;
                if ( json.success && Array.isArray( out ) ) {
                    const map = {};
                    titles.forEach( ( t, i ) => { if ( out[ i ] ) map[ t ] = out[ i ]; } );
                    setItems( ( prev ) => prev.map( ( it ) => (
                        map[ it.title ]
                            ? { ...it, translations: { ...it.translations, [ l.code ]: map[ it.title ] } }
                            : it
                    ) ) );
                }
            }
            setNotice( { type: 'success', message: __( 'Translated with AI. Review, then Save.', 'snel' ) } );
        } catch ( e ) {
            setNotice( { type: 'error', message: __( 'AI translate failed.', 'snel' ) } );
        }
        setAiBusy( false );
    };

    const updateTranslation = ( title, lang, value ) => {
        setItems( ( prev ) => prev.map( ( item ) => {
            if ( item.title !== title ) return item;
            return {
                ...item,
                translations: { ...item.translations, [ lang ]: value },
            };
        } ) );
    };

    const handleSave = async () => {
        setSaving( true );
        setNotice( null );

        // Flatten to { dutchKey: { lang: text } } format (same as theme strings).
        const flat = {};
        items.forEach( ( item ) => {
            flat[ item.title ] = item.translations;
        } );

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
                ? { type: 'success', message: __( 'Menu translations saved.', 'snel' ) }
                : { type: 'error', message: __( 'Failed to save.', 'snel' ) }
            );
        } catch {
            setNotice( { type: 'error', message: __( 'Network error.', 'snel' ) } );
        }

        setSaving( false );
    };

    // Filter by search query.
    const query = searchQuery.toLowerCase().trim();
    const filteredItems = query ? items.filter( ( item ) => {
        return item.title.toLowerCase().includes( query )
            || Object.values( item.translations ).some( ( v ) => v && v.toLowerCase().includes( query ) );
    } ) : items;

    const missingCount = filteredItems.reduce( ( sum, item ) => {
        return sum + nonDefaultLangs.filter( ( l ) => ! item.translations[ l.code ] ).length;
    }, 0 );

    // Group by menu name, then order with parent/child hierarchy and deduplicate.
    const menus = {};
    filteredItems.forEach( ( item ) => {
        const name = item.menuName || 'Menu';
        if ( ! menus[ name ] ) menus[ name ] = [];
        menus[ name ].push( item );
    } );

    const groupedMenus = {};
    Object.keys( menus ).forEach( ( menuName ) => {
        const menuItems = menus[ menuName ];
        const topLevel = menuItems.filter( ( i ) => ! i.parent );
        const children = menuItems.filter( ( i ) => i.parent );
        const ordered = [];
        topLevel.forEach( ( item ) => {
            ordered.push( item );
            children.filter( ( c ) => c.parent === item.id ).forEach( ( child ) => {
                ordered.push( child );
            } );
        } );

        const seen = new Set();
        groupedMenus[ menuName ] = [];
        ordered.forEach( ( item ) => {
            if ( ! seen.has( item.title ) ) {
                seen.add( item.title );
                groupedMenus[ menuName ].push( item );
            }
        } );
    } );

    const totalItems = Object.values( groupedMenus ).reduce( ( sum, items ) => sum + items.length, 0 );

    return (
        <div>
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                    <span className="text-sm text-gray-500">
                        { totalItems } { __( 'menu items', 'snel' ) }
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
                            placeholder={ __( 'Search menu items...', 'snel' ) }
                            className="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-blue-500 focus:shadow-[0_0_0_1px_#3b82f6] w-56"
                        />
                    </div>
                    { menuEditUrl && (
                        <a
                            href={ menuEditUrl }
                            className="flex items-center gap-1.5 px-3 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                        >
                            <ExternalLink size={ 14 } />
                            { __( 'Edit Menu', 'snel' ) }
                        </a>
                    ) }
                    <button
                        onClick={ aiTranslate }
                        disabled={ aiBusy || saving }
                        className="flex items-center gap-2 px-3 py-2 text-sm font-medium text-indigo-700 border border-indigo-200 rounded-lg hover:bg-indigo-50 transition-colors disabled:opacity-50"
                    >
                        { aiBusy ? <Loader2 size={ 14 } className="animate-spin" /> : <Languages size={ 14 } /> }
                        { aiBusy ? __( 'Translating...', 'snel' ) : __( 'Translate with AI', 'snel' ) }
                    </button>
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

            { totalItems === 0 ? (
                <div className="bg-white border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-400 py-12">
                    { __( 'No menu items found. Create a menu in Appearance > Menus first.', 'snel' ) }
                </div>
            ) : (
                <div className="space-y-4">
                    { Object.keys( groupedMenus ).map( ( menuName ) => {
                        const menuItems = groupedMenus[ menuName ];
                        return (
                            <div key={ menuName } className="bg-white border border-gray-200 rounded-lg overflow-hidden">
                                {/* Menu name header */ }
                                <div className="px-4 py-2.5 bg-gray-50 border-b border-gray-200">
                                    <span className="text-sm font-semibold text-gray-700">{ menuName }</span>
                                    <span className="text-xs text-gray-400 ml-2">({ menuItems.length })</span>
                                </div>

                                {/* Column headers */ }
                                <div className="grid px-4 py-2 bg-gray-50/50 text-xs font-medium text-gray-400 uppercase tracking-wider" style={ { gridTemplateColumns: `1fr ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) }` } }>
                                    <div>{ defaultLang.toUpperCase() } ({ __( 'source', 'snel' ) })</div>
                                    { nonDefaultLangs.map( ( l ) => (
                                        <div key={ l.code }>{ l.label }</div>
                                    ) ) }
                                </div>

                                <div className="divide-y divide-gray-100">
                                    { menuItems.map( ( item ) => {
                                        const isChild = items.find( ( i ) => i.title === item.title && i.parent );
                                        return (
                                            <div
                                                key={ item.title }
                                                className="grid px-4 py-2.5 gap-3 items-center"
                                                style={ { gridTemplateColumns: `1fr ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) }` } }
                                            >
                                                <div className={ `text-sm font-medium text-gray-700 ${ isChild ? 'pl-4' : '' }` }>
                                                    { isChild && <span className="text-gray-300 mr-1">└</span> }
                                                    <Highlight text={ item.title } query={ query } />
                                                </div>
                                                { nonDefaultLangs.map( ( l ) => (
                                                    <EditableCell
                                                        key={ l.code }
                                                        value={ item.translations[ l.code ] || '' }
                                                        onChange={ ( v ) => updateTranslation( item.title, l.code, v ) }
                                                        placeholder={ item.title }
                                                        query={ query }
                                                        missing={ ! item.translations[ l.code ] }
                                                    />
                                                ) ) }
                                            </div>
                                        );
                                    } ) }
                                </div>
                            </div>
                        );
                    } ) }
                </div>
            ) }
        </div>
    );
}
