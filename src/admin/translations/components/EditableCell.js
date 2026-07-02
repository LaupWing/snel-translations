import { useState, useRef, useEffect } from '@wordpress/element';
import { Pencil } from 'lucide-react';
import Highlight from './Highlight';

/**
 * A cell that shows text with search highlights by default,
 * and switches to a textarea on click/edit.
 */
export default function EditableCell( { value, onChange, placeholder, query, missing } ) {
    const [ editing, setEditing ] = useState( false );
    const ref = useRef( null );

    useEffect( () => {
        if ( editing && ref.current ) {
            ref.current.focus();
            ref.current.style.height = 'auto';
            ref.current.style.height = ref.current.scrollHeight + 'px';
        }
    }, [ editing ] );

    if ( editing ) {
        return (
            <textarea
                ref={ ref }
                value={ value || '' }
                onChange={ ( e ) => onChange( e.target.value ) }
                onBlur={ () => setEditing( false ) }
                placeholder={ placeholder }
                rows={ 1 }
                onInput={ ( e ) => { e.target.style.height = 'auto'; e.target.style.height = e.target.scrollHeight + 'px'; } }
                className={ `w-full px-2.5 py-1.5 text-sm border rounded-md resize-none overflow-hidden focus:outline-none focus:border-blue-500 focus:shadow-[0_0_0_1px_#3b82f6] ${ value ? 'border-gray-200' : 'border-amber-300 bg-amber-50/50' }` }
            />
        );
    }

    return (
        <div
            onClick={ () => setEditing( true ) }
            className={ `group relative w-full px-2.5 py-1.5 text-sm rounded-md cursor-text min-h-[32px] border transition-colors ${ missing ? 'border-amber-300 bg-amber-50/50' : 'border-transparent hover:border-gray-300' }` }
        >
            { value ? (
                <span className="text-gray-700 break-words">
                    <Highlight text={ value } query={ query } />
                </span>
            ) : (
                <span className="text-gray-300 italic">{ placeholder || '' }</span>
            ) }
            <span className="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                <Pencil size={ 12 } className="text-gray-400" />
            </span>
        </div>
    );
}
