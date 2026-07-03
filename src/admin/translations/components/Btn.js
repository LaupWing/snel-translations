import { Loader2 } from 'lucide-react';

// One button style for the whole admin. primary = blue (matches TranslationGrid),
// secondary = bordered, ghost = subtle. Pass `busy` for a spinner + disabled.
const STYLES = {
    primary: 'bg-blue-600 text-white border border-transparent hover:bg-blue-700',
    secondary: 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50',
    ai: 'bg-white text-purple-600 border border-purple-200 hover:bg-purple-50',
    ghost: 'bg-transparent text-gray-600 border border-transparent hover:bg-gray-100',
};

export default function Btn( { variant = 'primary', busy = false, icon: Icon, children, className = '', disabled, ...props } ) {
    return (
        <button
            className={ `inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-colors disabled:opacity-50 disabled:pointer-events-none ${ STYLES[ variant ] } ${ className }` }
            disabled={ disabled || busy }
            { ...props }
        >
            { busy ? <Loader2 size={ 16 } className="animate-spin" /> : ( Icon ? <Icon size={ 16 } /> : null ) }
            { children }
        </button>
    );
}
