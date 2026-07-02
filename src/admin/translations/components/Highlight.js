/**
 * Highlights matching text with a yellow background.
 * If no query or no match, renders the text as-is.
 */
export default function Highlight( { text, query } ) {
    if ( ! query || ! text ) return text || '';

    const lower = text.toLowerCase();
    const q = query.toLowerCase().trim();
    if ( ! q || ! lower.includes( q ) ) return text;

    const parts = [];
    let lastIndex = 0;
    let index = lower.indexOf( q );

    while ( index !== -1 ) {
        if ( index > lastIndex ) {
            parts.push( text.slice( lastIndex, index ) );
        }
        parts.push(
            <mark key={ index } className="bg-yellow-200 text-yellow-900 rounded-sm px-0.5">
                { text.slice( index, index + q.length ) }
            </mark>
        );
        lastIndex = index + q.length;
        index = lower.indexOf( q, lastIndex );
    }

    if ( lastIndex < text.length ) {
        parts.push( text.slice( lastIndex ) );
    }

    return <>{ parts }</>;
}
