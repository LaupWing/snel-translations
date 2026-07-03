import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Languages, X, Check, AlertTriangle } from 'lucide-react';
import Btn from '../components/Btn';

// "Translate everything" — walks every source post × missing/outdated language,
// running the create/sync flow one item at a time with a live progress popup.
export default function BulkTranslate() {
    const cfg = window.snelTranslations || {};
    const [ open, setOpen ]         = useState( false );
    const [ publish, setPublish ]   = useState( false );
    const [ phase, setPhase ]       = useState( 'idle' ); // idle | running | done
    const [ total, setTotal ]       = useState( 0 );
    const [ done, setDone ]         = useState( 0 );
    const [ current, setCurrent ]   = useState( '' );
    const [ failed, setFailed ]     = useState( [] );
    const [ quotaStop, setQuotaStop ] = useState( false );
    const cancelRef = useRef( false );

    const reset = () => {
        setPhase( 'idle' ); setTotal( 0 ); setDone( 0 ); setCurrent( '' ); setFailed( [] ); setQuotaStop( false );
    };

    const close = () => {
        if ( phase === 'running' ) { cancelRef.current = true; }
        setOpen( false );
        reset();
    };

    const start = async () => {
        cancelRef.current = false;
        setPhase( 'running' ); setDone( 0 ); setFailed( [] ); setCurrent( __( 'Scanning…', 'snel' ) );

        let plan;
        try {
            const res = await fetch( `${ cfg.restUrl }/bulk/plan`, { headers: { 'X-WP-Nonce': cfg.nonce } } );
            plan = await res.json();
        } catch ( e ) {
            setCurrent( __( 'Could not build the plan.', 'snel' ) ); setPhase( 'done' ); return;
        }
        setTotal( plan.total || 0 );
        if ( ! plan.total ) { setPhase( 'done' ); setCurrent( '' ); return; }

        const fails = [];
        for ( let i = 0; i < plan.items.length; i++ ) {
            if ( cancelRef.current ) break;
            const it = plan.items[ i ];
            setCurrent( `${ it.title } → ${ it.langLabel }` );
            try {
                const r = await fetch( `${ cfg.restUrl }/bulk/run`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                    body: JSON.stringify( { postId: it.postId, lang: it.lang, publish } ),
                } );
                if ( ! r.ok ) {
                    const j = await r.json().catch( () => ( {} ) );
                    // Quota/billing exhausted — no point continuing; stop the run.
                    if ( j.code === 'snel_ai_quota' ) {
                        setQuotaStop( true );
                        setDone( i );
                        break;
                    }
                    fails.push( `${ it.title } (${ it.langLabel }): ${ j.message || r.status }` );
                }
            } catch ( e ) {
                fails.push( `${ it.title } (${ it.langLabel })` );
            }
            setDone( i + 1 );
            // Gentle throttle so big runs stay under rate limits.
            await new Promise( ( res ) => setTimeout( res, 250 ) );
        }
        setFailed( fails );
        setCurrent( '' );
        setPhase( 'done' );
    };

    const pct = total ? Math.round( ( done / total ) * 100 ) : 0;

    return (
        <>
            <Btn variant="ai" icon={ Languages } onClick={ () => { reset(); setOpen( true ); } }>
                { __( 'Translate everything', 'snel' ) }
            </Btn>

            { open && (
                <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white shadow-2xl">
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                            <h2 className="text-sm font-semibold text-gray-900">{ __( 'Translate everything', 'snel' ) }</h2>
                            <button onClick={ close } className="text-gray-400 hover:text-gray-700"><X size={ 18 } /></button>
                        </div>

                        <div className="px-5 py-4">
                            { phase === 'idle' && (
                                <>
                                    <p className="text-sm text-gray-500 mb-4">
                                        { __( 'Creates missing translations and re-syncs outdated ones for every page and post, in all enabled languages. Unchanged text is reused (no AI cost); only new text is translated.', 'snel' ) }
                                    </p>
                                    <label className="flex items-center gap-2 text-sm text-gray-700 mb-5 cursor-pointer">
                                        <input type="checkbox" checked={ publish } onChange={ () => setPublish( ! publish ) } />
                                        { __( 'Publish new translations (otherwise saved as draft)', 'snel' ) }
                                    </label>
                                    <Btn variant="primary" onClick={ start }>{ __( 'Start', 'snel' ) }</Btn>
                                </>
                            ) }

                            { phase === 'running' && (
                                <>
                                    <div className="mb-2 flex items-center justify-between text-xs text-gray-500">
                                        <span>{ done } / { total }</span>
                                        <span>{ pct }%</span>
                                    </div>
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                        <div className="h-full rounded-full bg-blue-600 transition-all duration-200" style={ { width: `${ pct }%` } } />
                                    </div>
                                    <p className="mt-3 truncate text-sm text-gray-600">{ current }</p>
                                    <div className="mt-5">
                                        <Btn variant="secondary" onClick={ close }>{ __( 'Cancel', 'snel' ) }</Btn>
                                    </div>
                                </>
                            ) }

                            { phase === 'done' && (
                                <>
                                    { quotaStop && (
                                        <div className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3">
                                            <p className="flex items-center gap-1.5 text-sm font-semibold text-red-800">
                                                <AlertTriangle size={ 15 } /> { __( 'Stopped — AI provider out of quota', 'snel' ) }
                                            </p>
                                            <p className="mt-1 text-xs text-red-700">
                                                { __( 'Your AI provider rejected the requests (no credit / billing). Add billing at your provider, then run this again — it resumes with what is still missing.', 'snel' ) }
                                            </p>
                                        </div>
                                    ) }
                                    { total === 0 ? (
                                        <p className="flex items-center gap-2 text-sm text-green-700">
                                            <Check size={ 18 } /> { __( 'Everything is already translated and up to date.', 'snel' ) }
                                        </p>
                                    ) : (
                                        <>
                                            <p className="flex items-center gap-2 text-sm font-medium text-gray-800">
                                                <Check size={ 18 } className="text-green-600" />
                                                { done - failed.length } / { total } { __( 'translated', 'snel' ) }
                                            </p>
                                            { failed.length > 0 && (
                                                <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                                    <p className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-amber-800">
                                                        <AlertTriangle size={ 14 } /> { failed.length } { __( 'failed', 'snel' ) }
                                                    </p>
                                                    <ul className="max-h-40 space-y-1 overflow-auto text-xs text-amber-700">
                                                        { failed.map( ( f, i ) => <li key={ i }>{ f }</li> ) }
                                                    </ul>
                                                </div>
                                            ) }
                                        </>
                                    ) }
                                    <div className="mt-5">
                                        <Btn variant="primary" onClick={ close }>{ __( 'Done', 'snel' ) }</Btn>
                                    </div>
                                </>
                            ) }
                        </div>
                    </div>
                </div>
            ) }
        </>
    );
}
