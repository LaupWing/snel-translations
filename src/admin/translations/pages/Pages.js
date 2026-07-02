import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ExternalLink, Loader2, ChevronDown, ChevronRight, Check, AlertCircle, Search } from 'lucide-react';
import Highlight from '../components/Highlight';

export default function Pages( { initialSearch = '', initialPageId = null } ) {
    const languages = window.snelTranslations?.languages || [];
    const defaultLang = window.snelTranslations?.defaultLang || 'nl';
    const nonDefaultLangs = languages.filter( ( l ) => ! l.default );

    const [ pages, setPages ] = useState( [] );
    const [ activePage, setActivePage ] = useState( null );
    const [ loading, setLoading ] = useState( true );

    useEffect( () => {
        ( async () => {
            try {
                const res = await fetch( `${ window.snelTranslations.restUrl }/pages`, {
                    headers: { 'X-WP-Nonce': window.snelTranslations.nonce },
                } );
                const data = await res.json();
                setPages( Array.isArray( data ) ? data : [] );
                if ( initialPageId && data.some( ( p ) => p.id === initialPageId ) ) {
                    setActivePage( initialPageId );
                } else if ( data.length > 0 ) {
                    setActivePage( data[0].id );
                }
            } catch {
                // silent
            }
            setLoading( false );
        } )();
    }, [] );

    if ( loading ) {
        return (
            <div className="flex items-center justify-center py-12">
                <Loader2 size={ 24 } className="animate-spin text-blue-500" />
            </div>
        );
    }

    if ( pages.length === 0 ) {
        return (
            <div className="bg-white border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-400 py-12">
                { __( 'No pages found.', 'snel' ) }
            </div>
        );
    }

    const currentPage = pages.find( ( p ) => p.id === activePage );

    return (
        <div>
            {/* Page tabs */ }
            <div className="flex items-center gap-1 mb-4 overflow-x-auto pb-1">
                { pages.map( ( page ) => {
                    const pct = page.total > 0 ? Math.round( ( page.filled / page.total ) * 100 ) : 0;
                    const isComplete = page.total > 0 && page.filled === page.total;
                    const hasBlocks = page.blocks.length > 0;

                    return (
                        <button
                            key={ page.id }
                            onClick={ () => setActivePage( page.id ) }
                            className={ `flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg whitespace-nowrap transition-colors ${ activePage === page.id
                                ? 'bg-blue-600 text-white'
                                : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'
                            }` }
                        >
                            { page.title }
                            { hasBlocks && (
                                <span className={ `w-2 h-2 rounded-full ${ isComplete ? 'bg-emerald-400' : pct > 0 ? 'bg-amber-400' : 'bg-red-400' } ${ activePage === page.id ? 'opacity-80' : '' }` } />
                            ) }
                        </button>
                    );
                } ) }
            </div>

            {/* Page content */ }
            { currentPage && <PageDetail page={ currentPage } nonDefaultLangs={ nonDefaultLangs } defaultLang={ defaultLang } initialSearch={ initialSearch } /> }
        </div>
    );
}

function PageDetail( { page, nonDefaultLangs, defaultLang, initialSearch = '' } ) {
    const [ collapsed, setCollapsed ] = useState( {} );
    const [ searchQuery, setSearchQuery ] = useState( initialSearch );

    const toggleBlock = ( index ) => {
        setCollapsed( ( prev ) => ( { ...prev, [ index ]: ! prev[ index ] } ) );
    };

    // Filter blocks by search query (matches block name, attribute key, or any value).
    const query = searchQuery.toLowerCase().trim();
    const filteredBlocks = query ? page.blocks.filter( ( block ) => {
        if ( block.label.toLowerCase().includes( query ) ) return true;
        return block.attributes.some( ( attr ) => {
            if ( attr.key.toLowerCase().includes( query ) ) return true;
            return Object.values( attr.values ).some( ( v ) => v && v.toLowerCase().includes( query ) );
        } );
    } ) : page.blocks;

    if ( page.blocks.length === 0 ) {
        return (
            <div className="bg-white border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-400 py-8">
                { __( 'No translatable blocks found on this page.', 'snel' ) }
            </div>
        );
    }

    return (
        <div>
            {/* Page header */ }
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                    <span className="text-sm text-gray-500">
                        { filteredBlocks.length } { __( 'blocks', 'snel' ) }
                    </span>
                    { page.total > 0 && (
                        <span className={ `px-2 py-0.5 text-xs font-medium rounded-full ${ page.filled === page.total
                            ? 'bg-emerald-100 text-emerald-700'
                            : 'bg-amber-100 text-amber-700'
                        }` }>
                            { page.filled }/{ page.total } { __( 'translated', 'snel' ) }
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
                            placeholder={ __( 'Search blocks...', 'snel' ) }
                            className="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-blue-500 focus:shadow-[0_0_0_1px_#3b82f6] w-56"
                        />
                    </div>
                    { page.editUrl && (
                        <a
                            href={ page.editUrl }
                            className="flex items-center gap-1.5 px-3 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                        >
                            <ExternalLink size={ 14 } />
                            { __( 'Edit Page', 'snel' ) }
                        </a>
                    ) }
                </div>
            </div>

            {/* Blocks */ }
            { filteredBlocks.length === 0 && query && (
                <div className="bg-white border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-400 py-8">
                    { __( 'No results found.', 'snel' ) }
                </div>
            ) }

            <div className="space-y-2">
                { filteredBlocks.map( ( block, index ) => {
                    const isCollapsed = collapsed[ index ];

                    // Count filled for this block.
                    let blockTotal = 0;
                    let blockFilled = 0;
                    block.attributes.forEach( ( attr ) => {
                        nonDefaultLangs.forEach( ( l ) => {
                            blockTotal++;
                            if ( attr.values[ l.code ] ) blockFilled++;
                        } );
                    } );
                    const blockComplete = blockTotal > 0 && blockFilled === blockTotal;

                    return (
                        <div key={ index } className="bg-white border border-gray-200 rounded-lg overflow-hidden">
                            {/* Block header */ }
                            <button
                                onClick={ () => toggleBlock( index ) }
                                className="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition-colors text-left"
                            >
                                <div className="flex items-center gap-3">
                                    { isCollapsed
                                        ? <ChevronRight size={ 16 } className="text-gray-400" />
                                        : <ChevronDown size={ 16 } className="text-gray-400" />
                                    }
                                    <span className="px-2 py-0.5 text-xs font-mono bg-gray-100 text-gray-600 rounded">
                                        <Highlight text={ block.label } query={ query } />
                                    </span>
                                    <span className="text-sm text-gray-500">
                                        { block.attributes.length } { __( 'fields', 'snel' ) }
                                    </span>
                                </div>
                                <div className="flex items-center gap-2" onClick={ ( e ) => e.stopPropagation() }>
                                    { blockComplete ? (
                                        <Check size={ 14 } className="text-emerald-500" />
                                    ) : (
                                        <span className="text-xs text-amber-600">{ blockFilled }/{ blockTotal }</span>
                                    ) }
                                    <a
                                        href={ `${ page.editUrl }&awScrollTo=${ encodeURIComponent( block.name ) }` }
                                        className="flex items-center gap-1 px-2 py-1 text-xs text-gray-500 border border-gray-200 rounded hover:bg-gray-50 hover:text-blue-600 hover:border-blue-300 transition-colors"
                                    >
                                        <ExternalLink size={ 10 } />
                                        { __( 'Edit', 'snel' ) }
                                    </a>
                                </div>
                            </button>

                            {/* Block attributes */ }
                            { ! isCollapsed && (
                                <div className="border-t border-gray-100">
                                    {/* Column headers */ }
                                    <div className="grid px-4 py-2 bg-gray-50/50 text-xs font-medium text-gray-400 uppercase tracking-wider" style={ { gridTemplateColumns: `120px 1fr ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) }` } }>
                                        <div>{ __( 'Field', 'snel' ) }</div>
                                        <div>{ defaultLang.toUpperCase() }</div>
                                        { nonDefaultLangs.map( ( l ) => (
                                            <div key={ l.code }>{ l.label }</div>
                                        ) ) }
                                    </div>

                                    <div className="divide-y divide-gray-100">
                                        { block.attributes.map( ( attr ) => (
                                            <div
                                                key={ attr.key }
                                                className="grid px-4 py-2.5 gap-3 items-start"
                                                style={ { gridTemplateColumns: `120px 1fr ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) }` } }
                                            >
                                                <div className="text-xs font-mono text-gray-400 pt-1">
                                                    <Highlight text={ attr.key } query={ query } />
                                                </div>
                                                <div className="text-sm text-gray-700 pt-0.5 break-words">
                                                    { attr.values[ defaultLang ] ? <Highlight text={ attr.values[ defaultLang ] } query={ query } /> : <span className="text-gray-300 italic">{ __( 'empty', 'snel' ) }</span> }
                                                </div>
                                                { nonDefaultLangs.map( ( l ) => (
                                                    <div key={ l.code } className="text-sm pt-0.5 break-words">
                                                        { attr.values[ l.code ] ? (
                                                            <span className="text-gray-700"><Highlight text={ attr.values[ l.code ] } query={ query } /></span>
                                                        ) : (
                                                            <span className="flex items-center gap-1 text-amber-500 text-xs">
                                                                <AlertCircle size={ 12 } />
                                                                { __( 'Not translated', 'snel' ) }
                                                            </span>
                                                        ) }
                                                    </div>
                                                ) ) }
                                            </div>
                                        ) ) }
                                    </div>
                                </div>
                            ) }
                        </div>
                    );
                } ) }
            </div>
        </div>
    );
}
