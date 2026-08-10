import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
    Save, Search, Languages, Loader2, Image as ImageIcon, Sparkles,
    ChevronLeft, ChevronRight,
} from 'lucide-react';
import EditableCell from '../components/EditableCell';
import Btn from '../components/Btn';
import MediaBatchModal from './MediaBatchModal';

/**
 * Alt text per language, on one attachment.
 *
 * Media behaves like terms, not like posts: there is no sibling attachment per
 * language. The source alt stays in `_wp_attachment_image_alt` (also editable
 * in the media library), the translations live in `_snel_alt_{lang}` meta on
 * the same attachment. Nothing is duplicated.
 *
 * The image list is real — read from the core media REST route, paginated.
 * The translation columns are still local state; no write endpoint exists yet.
 */

const PER_PAGE = 20;

export default function MediaTab() {
    const languages = window.snelTranslations?.languages || [
        { code: 'nl', label: 'NL', default: true },
        { code: 'en', label: 'EN' },
        { code: 'de', label: 'DE' },
        { code: 'fr', label: 'FR' },
        { code: 'es', label: 'ES' },
        { code: 'it', label: 'IT' },
    ];
    const defaultLang = window.snelTranslations?.defaultLang || 'nl';
    const nonDefaultLangs = languages.filter( ( l ) => ! l.default && l.enabled !== false );
    const cfg = window.snelTranslations || {};

    const [ scope, setScope ] = useState( 'all' );
    const [ scopes, setScopes ] = useState( [] );
    const [ rows, setRows ] = useState( [] );
    const [ loading, setLoading ] = useState( true );
    const [ page, setPage ] = useState( 1 );
    const [ totalPages, setTotalPages ] = useState( 1 );
    const [ totalImages, setTotalImages ] = useState( 0 );
    const [ searchQuery, setSearchQuery ] = useState( '' );
    const [ search, setSearch ] = useState( '' );
    const [ saving, setSaving ] = useState( false );
    const [ translating, setTranslating ] = useState( null );
    const [ describing, setDescribing ] = useState( null );
    const [ notice, setNotice ] = useState( null );
    const [ batchOpen, setBatchOpen ] = useState( false );

    // Debounce the search box into the query actually sent to the API.
    useEffect( () => {
        const t = window.setTimeout( () => {
            setSearch( searchQuery.trim() );
            setPage( 1 );
        }, 400 );
        return () => window.clearTimeout( t );
    }, [ searchQuery ] );

    // Scope list — products are invisible to /wp/v2/types, so this comes from
    // our own endpoint together with the per-type counts.
    useEffect( () => {
        fetch( `${ cfg.restUrl }/media/scopes`, { headers: { 'X-WP-Nonce': cfg.nonce } } )
            .then( ( r ) => r.json() )
            .then( ( data ) => setScopes( [
                { id: 'all', label: __( 'All images', 'snel' ), count: data.total ?? 0 },
                ...( data.scopes || [] ),
            ] ) )
            .catch( () => {} );
    }, [] );

    useEffect( () => {
        let cancelled = false;
        setLoading( true );

        const params = new URLSearchParams( {
            scope,
            page: String( page ),
            per_page: String( PER_PAGE ),
        } );
        if ( search ) {
            params.set( 'search', search );
        }

        fetch( `${ cfg.restUrl }/media/list?${ params.toString() }`, {
            headers: { 'X-WP-Nonce': cfg.nonce },
        } )
            .then( ( r ) => r.json() )
            .then( ( data ) => {
                if ( cancelled ) return;
                if ( ! data || ! Array.isArray( data.rows ) ) {
                    setNotice( { type: 'error', message: __( 'Could not load media.', 'snel' ) } );
                    setRows( [] );
                    setLoading( false );
                    return;
                }
                setTotalPages( data.totalPages || 1 );
                setTotalImages( data.total || 0 );
                setRows( data.rows.map( ( m ) => ( {
                    id: m.id,
                    source: m.alt || '',
                    file: m.title || '',
                    usedIn: m.parentTitle || '',
                    thumb: m.thumb || '',
                    langs: m.translations || {},
                } ) ) );
                setLoading( false );
            } )
            .catch( () => {
                if ( cancelled ) return;
                setNotice( { type: 'error', message: __( 'Could not load media.', 'snel' ) } );
                setLoading( false );
            } );

        return () => { cancelled = true; };
    }, [ page, search, scope ] );

    // Reset to page 1 whenever the scope changes.
    useEffect( () => setPage( 1 ), [ scope ] );

    const update = ( id, lang, value ) => {
        setRows( ( prev ) => prev.map( ( r ) => (
            r.id === id
                ? ( lang === defaultLang
                    ? { ...r, source: value }
                    : { ...r, langs: { ...r.langs, [ lang ]: value } } )
                : r
        ) ) );
    };

    const handleTranslate = ( scope ) => {
        setTranslating( scope );
        window.setTimeout( () => {
            setTranslating( null );
            setNotice( { type: 'success', message: __( 'Mock — no request was sent yet.', 'snel' ) } );
        }, 700 );
    };

    const handleDescribe = ( id ) => {
        setDescribing( id );
        window.setTimeout( () => {
            setDescribing( null );
            setNotice( { type: 'success', message: __( 'Mock — no image was sent anywhere.', 'snel' ) } );
        }, 800 );
    };

    const handleSave = () => {
        setSaving( true );
        window.setTimeout( () => {
            setSaving( false );
            setNotice( { type: 'success', message: __( 'Mock — nothing saved yet.', 'snel' ) } );
        }, 500 );
    };

    const missingCount = rows.reduce(
        ( n, r ) => n + nonDefaultLangs.filter( ( l ) => ! r.langs[ l.code ] ).length,
        0
    );
    const noSourceCount = rows.filter( ( r ) => ! r.source ).length;

    const cols = `minmax(260px, 1.5fr) ${ nonDefaultLangs.map( () => '1fr' ).join( ' ' ) } 28px`;

    // Row-level icon button — same style as TranslationGrid's per-string one.
    const RowTranslateButton = ( { scope } ) => (
        <button
            onClick={ () => handleTranslate( scope ) }
            disabled={ !! translating }
            className="flex items-center gap-1 px-1.5 py-0.5 text-xs font-medium text-purple-600 hover:text-purple-700 hover:bg-purple-50 rounded transition-colors disabled:opacity-40"
            title={ __( '(Re)translate with AI', 'snel' ) }
        >
            { translating === scope
                ? <Loader2 size={ 12 } className="animate-spin" />
                : <Languages size={ 12 } /> }
        </button>
    );

    return (
        <div>
            { batchOpen && <MediaBatchModal onClose={ () => setBatchOpen( false ) } /> }

            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-3">
                    <span className="text-sm text-gray-500">
                        { totalImages.toLocaleString() } { __( 'images', 'snel' ) }
                    </span>
                    { missingCount > 0 && (
                        <span className="px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 rounded-full">
                            { missingCount } { __( 'missing on this page', 'snel' ) }
                        </span>
                    ) }
                    { noSourceCount > 0 && (
                        <span className="px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-700 rounded-full">
                            { noSourceCount } { __( 'no source alt', 'snel' ) }
                        </span>
                    ) }
                </div>
                <div className="flex items-center gap-3">
                    { scopes.length > 1 && (
                        <select
                            value={ scope }
                            onChange={ ( e ) => setScope( e.target.value ) }
                            className="text-sm border border-gray-300 rounded-md px-2 py-1.5 bg-white"
                        >
                            { scopes.map( ( s ) => (
                                <option key={ s.id } value={ s.id }>
                                    { s.label } ({ Number( s.count ).toLocaleString() })
                                </option>
                            ) ) }
                        </select>
                    ) }
                    <div className="relative">
                        <Search size={ 14 } className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input
                            type="text"
                            value={ searchQuery }
                            onChange={ ( e ) => setSearchQuery( e.target.value ) }
                            placeholder={ __( 'Search images...', 'snel' ) }
                            className="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-blue-500 focus:shadow-[0_0_0_1px_#3b82f6] w-56"
                        />
                    </div>
                </div>
            </div>

            { /* Actions on their own row — filters plus both buttons don't fit
                 on one line once the language columns are wide. */ }
            <div className="flex items-center justify-end gap-3 mb-4">
                <Btn variant="ai" icon={ Languages } onClick={ () => setBatchOpen( true ) }>
                    { __( 'Re-translate All', 'snel' ) }
                </Btn>
                <Btn variant="primary" icon={ Save } busy={ saving } onClick={ handleSave }>
                    { saving ? __( 'Saving...', 'snel' ) : __( 'Save Translations', 'snel' ) }
                </Btn>
            </div>

            { notice && (
                <div className={ `mb-4 px-4 py-3 rounded-lg text-sm ${ notice.type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }` }>
                    { notice.message }
                    <button onClick={ () => setNotice( null ) } className="float-right font-bold">×</button>
                </div>
            ) }

            <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div
                    className="grid px-4 py-2 bg-gray-50/50 border-b border-gray-200 text-xs font-medium text-gray-400 uppercase tracking-wider"
                    style={ { gridTemplateColumns: cols } }
                >
                    <div>{ defaultLang.toUpperCase() } ({ __( 'source alt', 'snel' ) })</div>
                    { nonDefaultLangs.map( ( l ) => (
                        <div key={ l.code }>{ l.label }</div>
                    ) ) }
                    <div />
                </div>

                { loading && (
                    <div className="p-6 text-center text-sm text-gray-400 py-12 flex items-center justify-center gap-2">
                        <Loader2 size={ 14 } className="animate-spin" />
                        { __( 'Loading images…', 'snel' ) }
                    </div>
                ) }

                { ! loading && rows.length === 0 && (
                    <div className="p-6 text-center text-sm text-gray-400 py-12">
                        { search ? __( 'No results found.', 'snel' ) : __( 'No images found.', 'snel' ) }
                    </div>
                ) }

                { ! loading && (
                    <div className="divide-y divide-gray-100">
                        { rows.map( ( row ) => (
                            <div
                                key={ row.id }
                                className="grid px-4 py-2.5 gap-3 items-start hover:bg-gray-50/40"
                                style={ { gridTemplateColumns: cols } }
                            >
                                <div className="flex gap-2.5 min-w-0">
                                    <div className="w-10 h-10 shrink-0 rounded bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center text-gray-300">
                                        { row.thumb
                                            ? <img src={ row.thumb } alt="" className="w-full h-full object-cover" loading="lazy" />
                                            : <ImageIcon size={ 15 } /> }
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        { row.source ? (
                                            <EditableCell
                                                value={ row.source }
                                                onChange={ ( v ) => update( row.id, defaultLang, v ) }
                                                query={ search }
                                            />
                                        ) : (
                                            <button
                                                onClick={ () => handleDescribe( row.id ) }
                                                disabled={ describing === row.id }
                                                className="flex items-center gap-1.5 px-2 py-1.5 text-xs font-medium text-purple-600 border border-purple-200 rounded-md hover:bg-purple-50 disabled:opacity-40 w-full"
                                            >
                                                { describing === row.id
                                                    ? <Loader2 size={ 12 } className="animate-spin" />
                                                    : <Sparkles size={ 12 } /> }
                                                { __( 'Describe image', 'snel' ) }
                                            </button>
                                        ) }
                                        <div className="text-xs text-gray-400 mt-0.5 truncate px-2.5">
                                            { row.file }
                                            { row.usedIn && <span className="text-gray-300"> · { row.usedIn }</span> }
                                        </div>
                                    </div>
                                </div>

                                { nonDefaultLangs.map( ( l ) => (
                                    <EditableCell
                                        key={ l.code }
                                        value={ row.langs[ l.code ] || '' }
                                        onChange={ ( v ) => update( row.id, l.code, v ) }
                                        placeholder={ row.source }
                                        query={ search }
                                        missing={ ! row.langs[ l.code ] }
                                    />
                                ) ) }

                                <div className="pt-1">
                                    <RowTranslateButton scope={ row.id } />
                                </div>
                            </div>
                        ) ) }
                    </div>
                ) }
            </div>

            { /* ── Pagination ──────────────────────────────────── */ }
            { totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                    <span className="text-xs text-gray-400">
                        { sprintf( __( 'Page %1$d of %2$d', 'snel' ), page, totalPages ) }
                    </span>
                    <div className="flex items-center gap-1">
                        <button
                            onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
                            disabled={ page === 1 || loading }
                            className="flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 disabled:pointer-events-none"
                        >
                            <ChevronLeft size={ 14 } />
                            { __( 'Previous', 'snel' ) }
                        </button>
                        <button
                            onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
                            disabled={ page === totalPages || loading }
                            className="flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 disabled:pointer-events-none"
                        >
                            { __( 'Next', 'snel' ) }
                            <ChevronRight size={ 14 } />
                        </button>
                    </div>
                </div>
            ) }
        </div>
    );
}
