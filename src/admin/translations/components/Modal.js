import { useEffect, createPortal } from '@wordpress/element';
import { X } from 'lucide-react';

/**
 * Plain Tailwind modal — matches the rest of the admin rather than
 * wp-components, which brings its own look. Closes on Escape and backdrop
 * click. `footer` is pinned below a divider so wizard buttons stay in place
 * while the body scrolls.
 */
export default function Modal( { title, subtitle, onClose, children, footer, width = 'max-w-2xl' } ) {
    useEffect( () => {
        const onKey = ( e ) => {
            if ( 'Escape' === e.key ) onClose?.();
        };
        document.addEventListener( 'keydown', onKey );
        return () => document.removeEventListener( 'keydown', onKey );
    }, [ onClose ] );

    // Portalled to <body>: inside the admin app, an ancestor with a transform
    // becomes the containing block for `fixed`, which throws the centering off.
    return createPortal(
        <div className="fixed inset-0 z-[100000] flex items-center justify-center p-6">
            <div
                className="absolute inset-0 bg-gray-900/40"
                onClick={ onClose }
                aria-hidden="true"
            />

            <div className={ `relative bg-white rounded-xl shadow-2xl w-full ${ width } flex flex-col max-h-[85vh]` }>
                <div className="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-100">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">{ title }</h2>
                        { subtitle && <p className="text-sm text-gray-500 mt-0.5">{ subtitle }</p> }
                    </div>
                    <button
                        onClick={ onClose }
                        className="p-1 -mr-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0"
                        aria-label="Close"
                    >
                        <X size={ 18 } />
                    </button>
                </div>

                <div className="px-6 py-5 overflow-y-auto">{ children }</div>

                { footer && (
                    <div className="px-6 py-4 border-t border-gray-100 bg-gray-50/50 rounded-b-xl">
                        { footer }
                    </div>
                ) }
            </div>
        </div>,
        document.body
    );
}
