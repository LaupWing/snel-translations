import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import TabHeader from '../components/TabHeader';
import Btn from '../components/Btn';

// Detect each CPT's custom meta fields and let the user pick which get
// AI-translated when creating/syncing a translation. Saved to an option the
// Create/Sync flow reads — generic, no code per project.
export default function FieldsTab() {
    const cfg = window.snelTranslations || {};
    const [ groups, setGroups ] = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ status, setStatus ] = useState( '' );

    useEffect( () => {
        fetch( `${ cfg.restUrl }/fields`, { headers: { 'X-WP-Nonce': cfg.nonce } } )
            .then( ( r ) => r.json() )
            .then( setGroups )
            .catch( () => setStatus( __( 'Could not load fields.', 'snel' ) ) );
    }, [] );

    const toggle = ( pt, key ) => {
        setGroups( ( prev ) => prev.map( ( g ) => (
            g.postType !== pt ? g : {
                ...g,
                fields: g.fields.map( ( f ) => ( f.key === key ? { ...f, translate: ! f.translate } : f ) ),
            }
        ) ) );
    };

    const save = async () => {
        setSaving( true );
        setStatus( '' );
        const payload = {};
        groups.forEach( ( g ) => {
            payload[ g.postType ] = g.fields.filter( ( f ) => f.translate ).map( ( f ) => f.key );
        } );
        try {
            const res = await fetch( `${ cfg.restUrl }/fields`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify( payload ),
            } );
            const j = await res.json();
            setStatus( j && j.success ? __( 'Saved.', 'snel' ) : __( 'Could not save.', 'snel' ) );
        } catch ( e ) {
            setStatus( __( 'Request failed.', 'snel' ) );
        }
        setSaving( false );
    };

    if ( status && ! groups ) {
        return <p className="text-sm text-red-600">{ status }</p>;
    }
    if ( ! groups ) {
        return <p className="text-sm text-gray-500">{ __( 'Loading…', 'snel' ) }</p>;
    }

    return (
        <div className="max-w-2xl">
            <TabHeader
                title={ __( 'Custom fields', 'snel' ) }
                description={ __( 'Pick which post-meta fields get AI-translated on create/sync. Flat text fields only.', 'snel' ) }
            >
                <Btn variant="primary" busy={ saving } disabled={ saving } onClick={ save }>
                    { __( 'Save', 'snel' ) }
                </Btn>
            </TabHeader>

            { status && <p className="text-sm text-gray-600 mb-3">{ status }</p> }

            { groups.length === 0 && (
                <p className="text-sm text-gray-400">{ __( 'No custom fields detected.', 'snel' ) }</p>
            ) }

            { groups.map( ( g ) => (
                <div key={ g.postType } className="border border-gray-200 rounded-lg mb-3 overflow-hidden bg-white">
                    <div className="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <span className="text-sm font-semibold text-gray-800">{ g.label }</span>
                        <span className="ml-1 text-xs text-gray-400">({ g.postType })</span>
                    </div>
                    <div className="divide-y divide-gray-100">
                        { g.fields.map( ( f ) => (
                            <label
                                key={ f.key }
                                className="flex items-center gap-2.5 px-4 py-2 text-sm cursor-pointer hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    checked={ f.translate }
                                    onChange={ () => toggle( g.postType, f.key ) }
                                />
                                <code className="text-xs text-gray-700">{ f.key }</code>
                            </label>
                        ) ) }
                    </div>
                </div>
            ) ) }
        </div>
    );
}
