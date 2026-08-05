import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
    Check, ChevronLeft, ChevronRight, Images, Layers, Languages, Sparkles,
    Zap, Clock, Loader2, AlertTriangle,
} from 'lucide-react';
import Modal from '../components/Modal';
import Btn from '../components/Btn';

/**
 * Batch wizard for media alt text.
 *
 * Stages: scope -> what to do -> how to run it -> confirm -> queued.
 * UI ONLY — nothing is sent anywhere. Counts and prices are placeholders so
 * the flow can be judged before any endpoint exists.
 */

const STEPS = [
    { id: 'scope', label: __( 'Scope', 'snel' ) },
    { id: 'action', label: __( 'Action', 'snel' ) },
    { id: 'run', label: __( 'Run mode', 'snel' ) },
    { id: 'confirm', label: __( 'Confirm', 'snel' ) },
];

const HINTS = {
    unattached: __( 'Not attached to any post', 'snel' ),
    all: __( 'Whole media library, including unattached files', 'snel' ),
};

// Variables the prompt can reference. `resolve` reads a row from /media/list,
// so the preview shows real values rather than invented ones.
const VARIABLES = [
    { tag: '%%post_title%%', label: __( 'Post title', 'snel' ), resolve: ( r ) => r?.parentTitle },
    { tag: '%%post_type%%', label: __( 'Post type', 'snel' ), resolve: ( r ) => r?.parentType },
    { tag: '%%filename%%', label: __( 'File name', 'snel' ), resolve: ( r ) => r?.title },
    { tag: '%%current_alt%%', label: __( 'Current alt', 'snel' ), resolve: ( r ) => r?.alt },
    { tag: '%%site_name%%', label: __( 'Site name', 'snel' ), resolve: () => window.snelTranslations?.siteName || document.title },
];

/** Swap %%tags%% for the example row's real values. */
function resolvePreview( text, row ) {
    let out = text;
    VARIABLES.forEach( ( v ) => {
        const value = v.resolve( row );
        out = out.split( v.tag ).join( value || `[${ v.label.toLowerCase() } empty]` );
    } );
    return out;
}

const ACTIONS = [
    {
        id: 'translate',
        label: __( 'Translate existing alt', 'snel' ),
        hint: __( 'Uses the alt already on the image. No vision, cheapest by far.', 'snel' ),
        icon: Languages,
    },
    {
        id: 'describe',
        label: __( 'Describe with vision, then translate', 'snel' ),
        hint: __( 'Looks at the image itself. Use where alt is missing or generic.', 'snel' ),
        icon: Sparkles,
    },
    {
        id: 'describe-only',
        label: __( 'Describe only', 'snel' ),
        hint: __( 'Write the source alt, translate later once you have reviewed it.', 'snel' ),
        icon: Sparkles,
    },
];

const RUN_MODES = [
    {
        id: 'batch',
        label: __( 'Batch', 'snel' ),
        hint: __( 'Half price, runs in the background, can take up to a few hours.', 'snel' ),
        icon: Clock,
    },
    {
        id: 'live',
        label: __( 'Live', 'snel' ),
        hint: __( 'Runs now, image by image. Full price, keep this tab open.', 'snel' ),
        icon: Zap,
    },
];

/** Rough placeholder estimate so the confirm step has something real-shaped. */
function estimate( count, action, runMode ) {
    const perImage = 'translate' === action ? 0.00002 : 0.00021;
    const raw = count * perImage;
    return 'batch' === runMode ? raw / 2 : raw;
}

export default function MediaBatchModal( { onClose } ) {
    const cfg = window.snelTranslations || {};
    const [ scopes, setScopes ] = useState( null );
    const [ loadingScopes, setLoadingScopes ] = useState( true );

    // Scopes come from our own endpoint, not /wp/v2/types: post types
    // registered with show_in_rest=false (products) are absent from core's
    // route entirely, and only a direct query can count images per parent type.
    useEffect( () => {
        let cancelled = false;

        fetch( `${ cfg.restUrl }/media/scopes`, { headers: { 'X-WP-Nonce': cfg.nonce } } )
            .then( ( r ) => r.json() )
            .then( ( data ) => {
                if ( cancelled ) return;
                const list = ( data.scopes || [] ).map( ( s ) => ( {
                    id: s.id,
                    label: 'unattached' === s.id
                        ? s.label
                        : sprintf( __( '%s images', 'snel' ), s.label ),
                    hint: HINTS[ s.id ] || sprintf( __( 'Attached to %s', 'snel' ), s.label.toLowerCase() ),
                    count: s.count,
                    noAlt: s.noAlt ?? 0,
                    missingTrans: s.missingTrans ?? 0,
                    icon: 'unattached' === s.id ? Images : Layers,
                } ) );

                list.push( {
                    id: 'all',
                    label: __( 'All images', 'snel' ),
                    hint: HINTS.all,
                    count: data.total ?? null,
                    noAlt: data.noAlt ?? 0,
                    missingTrans: data.missingTrans ?? 0,
                    icon: Images,
                } );

                setScopes( list );
                setLoadingScopes( false );
            } )
            .catch( () => {
                if ( cancelled ) return;
                setScopes( [ {
                    id: 'all',
                    label: __( 'All images', 'snel' ),
                    hint: HINTS.all,
                    count: null,
                    icon: Images,
                } ] );
                setLoadingScopes( false );
            } );

        return () => { cancelled = true; };
    }, [] );

    const [ step, setStep ] = useState( 0 );
    const [ scope, setScope ] = useState( '' );
    const [ onlyMissing, setOnlyMissing ] = useState( true );
    const [ action, setAction ] = useState( 'translate' );
    const [ runMode, setRunMode ] = useState( 'batch' );
    const [ instructions, setInstructions ] = useState( '' );
    const [ example, setExample ] = useState( null );
    const instructionsRef = useRef( null );
    const [ queued, setQueued ] = useState( false );
    const [ starting, setStarting ] = useState( false );

    // Default to the first scope once the list arrives.
    useEffect( () => {
        if ( scopes?.length && ! scope ) {
            setScope( scopes[ 0 ].id );
        }
    }, [ scopes ] );

    // One real row from the chosen scope, used to preview the prompt.
    useEffect( () => {
        if ( ! scope ) return;
        let cancelled = false;

        fetch( `${ cfg.restUrl }/media/list?scope=${ encodeURIComponent( scope ) }&page=1&per_page=1`, {
            headers: { 'X-WP-Nonce': cfg.nonce },
        } )
            .then( ( r ) => r.json() )
            .then( ( data ) => {
                if ( ! cancelled ) setExample( data?.rows?.[ 0 ] || null );
            } )
            .catch( () => { if ( ! cancelled ) setExample( null ); } );

        return () => { cancelled = true; };
    }, [ scope ] );

    /** Insert a variable at the cursor in the instructions box. */
    const insertVariable = ( tag ) => {
        const el = instructionsRef.current;
        if ( ! el ) {
            setInstructions( ( v ) => v + tag );
            return;
        }
        const start = el.selectionStart ?? instructions.length;
        const end = el.selectionEnd ?? instructions.length;
        const next = instructions.slice( 0, start ) + tag + instructions.slice( end );
        setInstructions( next );
        window.requestAnimationFrame( () => {
            el.focus();
            el.selectionStart = el.selectionEnd = start + tag.length;
        } );
    };

    const scopeObj = scopes?.find( ( s ) => s.id === scope );
    const actionObj = ACTIONS.find( ( a ) => a.id === action );
    const runObj = RUN_MODES.find( ( r ) => r.id === runMode );

    const needsVision = 'translate' !== action;

    // Real backlog, not a guess. Which number applies depends on the action:
    // describing only touches images with no alt, translating only touches
    // images whose languages aren't filled in yet.
    const affected = ( () => {
        if ( ! scopeObj ) return null;
        if ( ! onlyMissing ) return scopeObj.count;
        if ( 'translate' === action ) return scopeObj.missingTrans;
        if ( 'describe-only' === action ) return scopeObj.noAlt;
        // describe + translate: vision for the ones with no alt, then every
        // image still missing a language.
        return Math.max( scopeObj.noAlt, scopeObj.missingTrans );
    } )();

    const start = () => {
        setStarting( true );
        window.setTimeout( () => {
            setStarting( false );
            setQueued( true );
        }, 900 );
    };

    // ── Reusable option card ──
    const Card = ( { active, onClick, icon: Icon, label, hint, right } ) => (
        <button
            onClick={ onClick }
            className={ `w-full text-left flex items-start gap-3 p-3 rounded-lg border transition-colors ${ active
                ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-500'
                : 'border-gray-200 hover:border-gray-300 bg-white'
            }` }
        >
            { Icon && (
                <span className={ `mt-0.5 shrink-0 ${ active ? 'text-blue-600' : 'text-gray-400' }` }>
                    <Icon size={ 17 } />
                </span>
            ) }
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium text-gray-900">{ label }</span>
                <span className="block text-xs text-gray-500 mt-0.5">{ hint }</span>
            </span>
            { right && <span className="text-xs text-gray-400 shrink-0 pt-0.5">{ right }</span> }
        </button>
    );

    // ── Stepper ──
    const Stepper = () => (
        <div className="flex items-center gap-2 mb-5">
            { STEPS.map( ( s, i ) => (
                <div key={ s.id } className="flex items-center gap-2">
                    <div className="flex items-center gap-1.5">
                        <span className={ `w-5 h-5 rounded-full flex items-center justify-center text-[11px] font-semibold ${
                            i < step ? 'bg-blue-600 text-white'
                                : i === step ? 'bg-blue-100 text-blue-700 ring-1 ring-blue-500'
                                    : 'bg-gray-100 text-gray-400'
                        }` }>
                            { i < step ? <Check size={ 11 } /> : i + 1 }
                        </span>
                        <span className={ `text-xs font-medium ${ i === step ? 'text-gray-900' : 'text-gray-400' }` }>
                            { s.label }
                        </span>
                    </div>
                    { i < STEPS.length - 1 && <span className="w-6 h-px bg-gray-200" /> }
                </div>
            ) ) }
        </div>
    );

    // ── Queued state replaces the wizard entirely ──
    if ( queued ) {
        return (
            <Modal
                title={ __( 'Job queued', 'snel' ) }
                onClose={ onClose }
                footer={
                    <div className="flex justify-end">
                        <Btn variant="primary" onClick={ onClose }>{ __( 'Done', 'snel' ) }</Btn>
                    </div>
                }
            >
                <div className="text-center py-6">
                    <div className="w-11 h-11 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-3">
                        <Check size={ 20 } />
                    </div>
                    <p className="text-sm text-gray-900 font-medium">
                        { null == affected
                            ? __( 'Job queued', 'snel' )
                            : sprintf( __( '%d images queued', 'snel' ), affected ) }
                    </p>
                    <p className="text-sm text-gray-500 mt-1 max-w-sm mx-auto">
                        { 'batch' === runMode
                            ? __( 'Running in the background. You can close this tab — progress shows at the top of the Translations page.', 'snel' )
                            : __( 'Running now. Keep this tab open until it finishes.', 'snel' ) }
                    </p>
                </div>
            </Modal>
        );
    }

    const footer = (
        <div className="flex items-center justify-between">
            <Btn variant="ghost" onClick={ onClose }>{ __( 'Cancel', 'snel' ) }</Btn>

            <div className="flex items-center gap-2">
                <Btn
                    variant="secondary"
                    icon={ ChevronLeft }
                    onClick={ () => setStep( ( s ) => s - 1 ) }
                    disabled={ step === 0 }
                >
                    { __( 'Back', 'snel' ) }
                </Btn>
                { step < STEPS.length - 1 ? (
                    <Btn
                        variant="primary"
                        onClick={ () => setStep( ( s ) => s + 1 ) }
                        disabled={ 0 === step && ! scope }
                    >
                        { __( 'Next', 'snel' ) }
                        <ChevronRight size={ 16 } />
                    </Btn>
                ) : (
                    <Btn variant="primary" busy={ starting } onClick={ start }>
                        { 'batch' === runMode ? __( 'Queue job', 'snel' ) : __( 'Start now', 'snel' ) }
                    </Btn>
                ) }
            </div>
        </div>
    );

    return (
        <Modal
            title={ __( 'Batch alt text', 'snel' ) }
            subtitle={ __( 'Generate and translate alt text across many images at once.', 'snel' ) }
            onClose={ onClose }
            footer={ footer }
        >
            <Stepper />

            { /* ── 1. Scope ─────────────────────────────────── */ }
            { 0 === step && (
                <div className="space-y-2">
                    { loadingScopes && (
                        <div className="flex items-center justify-center gap-2 py-10 text-sm text-gray-400">
                            <Loader2 size={ 14 } className="animate-spin" />
                            { __( 'Loading post types…', 'snel' ) }
                        </div>
                    ) }

                    { ! loadingScopes && scopes.map( ( s ) => (
                        <Card
                            key={ s.id }
                            active={ scope === s.id }
                            onClick={ () => setScope( s.id ) }
                            icon={ s.icon }
                            label={ s.label }
                            hint={ s.hint }
                            right={ null == s.count ? '' : (
                                <span className="flex flex-col items-end gap-0.5">
                                    <span>{ sprintf( __( '%s images', 'snel' ), s.count.toLocaleString() ) }</span>
                                    { s.noAlt > 0 && (
                                        <span className="text-purple-600">
                                            { sprintf( __( '%s no alt', 'snel' ), s.noAlt.toLocaleString() ) }
                                        </span>
                                    ) }
                                    { s.missingTrans > 0 && (
                                        <span className="text-amber-600">
                                            { sprintf( __( '%s untranslated', 'snel' ), s.missingTrans.toLocaleString() ) }
                                        </span>
                                    ) }
                                </span>
                            ) }
                        />
                    ) ) }

                    <label className="flex items-start gap-2 mt-4 p-3 rounded-lg bg-gray-50 border border-gray-100 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={ onlyMissing }
                            onChange={ ( e ) => setOnlyMissing( e.target.checked ) }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="block text-sm text-gray-900">{ __( 'Skip images that are already done', 'snel' ) }</span>
                            <span className="block text-xs text-gray-500 mt-0.5">
                                { __( 'Leave on unless you want to overwrite existing alt text.', 'snel' ) }
                            </span>
                        </span>
                    </label>
                </div>
            ) }

            { /* ── 2. Action ────────────────────────────────── */ }
            { 1 === step && (
                <div className="space-y-2">
                    { ACTIONS.map( ( a ) => {
                        const n = 'translate' === a.id
                            ? scopeObj?.missingTrans
                            : 'describe-only' === a.id
                                ? scopeObj?.noAlt
                                : Math.max( scopeObj?.noAlt || 0, scopeObj?.missingTrans || 0 );
                        return (
                            <Card
                                key={ a.id }
                                active={ action === a.id }
                                onClick={ () => setAction( a.id ) }
                                icon={ a.icon }
                                label={ a.label }
                                hint={ a.hint }
                                right={ onlyMissing && null != n
                                    ? sprintf( __( '%s to do', 'snel' ), Number( n ).toLocaleString() )
                                    : '' }
                            />
                        );
                    } ) }

                    { needsVision && (
                        <div className="mt-4">
                            <label className="block text-xs font-medium text-gray-700 mb-1">
                                { __( 'Extra instructions for the vision model', 'snel' ) }
                            </label>
                            <textarea
                                ref={ instructionsRef }
                                rows="3"
                                value={ instructions }
                                onChange={ ( e ) => setInstructions( e.target.value ) }
                                placeholder={ __( 'e.g. Antique furniture shop. This is %%post_title%%. Name the material, style and era when visible. Never start with "image of". Keep under 100 characters.', 'snel' ) }
                                className="w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:border-blue-500 font-mono"
                            />

                            <div className="flex flex-wrap items-center gap-1.5 mt-2">
                                <span className="text-xs text-gray-500 mr-0.5">{ __( 'Insert:', 'snel' ) }</span>
                                { VARIABLES.map( ( v ) => (
                                    <button
                                        key={ v.tag }
                                        onClick={ () => insertVariable( v.tag ) }
                                        title={ v.tag }
                                        className="px-1.5 py-0.5 text-[11px] font-mono rounded bg-gray-100 text-gray-600 hover:bg-blue-50 hover:text-blue-700 transition-colors"
                                    >
                                        { v.label }
                                    </button>
                                ) ) }
                            </div>

                            { instructions.includes( '%%' ) && (
                                <div className="mt-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                                    <div className="flex items-center gap-2 mb-1.5">
                                        <span className="text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                            { __( 'Preview', 'snel' ) }
                                        </span>
                                        { example && (
                                            <span className="text-[11px] text-gray-400">
                                                { sprintf( __( 'using #%d', 'snel' ), example.id ) }
                                            </span>
                                        ) }
                                    </div>
                                    <div className="flex gap-2.5">
                                        { example?.thumb && (
                                            <img
                                                src={ example.thumb }
                                                alt=""
                                                className="w-10 h-10 rounded object-cover border border-gray-200 shrink-0"
                                            />
                                        ) }
                                        <p className="text-xs text-gray-700 leading-relaxed">
                                            { resolvePreview( instructions, example ) }
                                        </p>
                                    </div>
                                </div>
                            ) }

                            <p className="text-xs text-gray-500 mt-2">
                                { __( 'Sent with every image. Variables are filled in per image.', 'snel' ) }
                            </p>
                        </div>
                    ) }
                </div>
            ) }

            { /* ── 3. Run mode ──────────────────────────────── */ }
            { 2 === step && (
                <div className="space-y-2">
                    { RUN_MODES.map( ( r ) => (
                        <Card
                            key={ r.id }
                            active={ runMode === r.id }
                            onClick={ () => setRunMode( r.id ) }
                            icon={ r.icon }
                            label={ r.label }
                            hint={ r.hint }
                        />
                    ) ) }

                    { 'live' === runMode && affected > 200 && null != affected && (
                        <div className="flex items-start gap-2 mt-3 p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800">
                            <AlertTriangle size={ 14 } className="mt-0.5 shrink-0" />
                            <span>
                                { sprintf(
                                    __( '%d images in live mode will take a while and costs double. Batch is the better choice at this size.', 'snel' ),
                                    affected
                                ) }
                            </span>
                        </div>
                    ) }
                </div>
            ) }

            { /* ── 4. Confirm ───────────────────────────────── */ }
            { 3 === step && (
                <div>
                    <dl className="divide-y divide-gray-100 border border-gray-200 rounded-lg overflow-hidden">
                        { [
                            [ __( 'Scope', 'snel' ), scopeObj?.label || '—' ],
                            [ __( 'Images affected', 'snel' ), null == affected
                                ? __( 'counted when the job starts', 'snel' )
                                : affected.toLocaleString() ],
                            [ __( 'Action', 'snel' ), actionObj.label ],
                            [ __( 'Run mode', 'snel' ), runObj.label ],
                            [ __( 'Skip already done', 'snel' ), onlyMissing ? __( 'Yes', 'snel' ) : __( 'No — overwrites', 'snel' ) ],
                            [ __( 'Estimated cost', 'snel' ), null == affected
                                ? __( 'unknown until counted', 'snel' )
                                : '± $' + estimate( affected, action, runMode ).toFixed( 2 ) ],
                        ].map( ( [ k, v ] ) => (
                            <div key={ k } className="flex items-center justify-between px-4 py-2.5 text-sm">
                                <dt className="text-gray-500">{ k }</dt>
                                <dd className="text-gray-900 font-medium text-right">{ v }</dd>
                            </div>
                        ) ) }
                    </dl>

                    { ! onlyMissing && (
                        <div className="flex items-start gap-2 mt-3 p-3 rounded-lg bg-red-50 border border-red-200 text-xs text-red-700">
                            <AlertTriangle size={ 14 } className="mt-0.5 shrink-0" />
                            { __( 'Existing alt text will be overwritten and cannot be recovered.', 'snel' ) }
                        </div>
                    ) }

                    <p className="text-xs text-gray-400 mt-3 flex items-center gap-1.5">
                        <Loader2 size={ 12 } />
                        { __( 'Cost is an estimate — actual tokens depend on image size.', 'snel' ) }
                    </p>
                </div>
            ) }
        </Modal>
    );
}
