// Standard inline notice. Replaces ad-hoc inline-styled boxes.
const TONES = {
    info: 'bg-blue-50 border-blue-400 text-blue-800',
    warn: 'bg-amber-50 border-amber-400 text-amber-800',
    success: 'bg-green-50 border-green-500 text-green-800',
    error: 'bg-red-50 border-red-400 text-red-800',
};

export default function Notice( { tone = 'info', children, className = '' } ) {
    return (
        <div className={ `text-sm mb-4 p-3 rounded border-l-4 ${ TONES[ tone ] } ${ className }` }>
            { children }
        </div>
    );
}
