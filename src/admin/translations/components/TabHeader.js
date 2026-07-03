// Standard top toolbar for every tab: title + description on the left, action
// buttons (passed as children) on the right, divider below.
export default function TabHeader( { title, description, children } ) {
    return (
        <div className="flex items-start justify-between gap-4 mb-5 pb-4 border-b border-gray-100">
            <div>
                { title && <h2 className="text-base font-semibold text-gray-900">{ title }</h2> }
                { description && <p className="text-sm text-gray-500 mt-0.5 max-w-xl">{ description }</p> }
            </div>
            { children && <div className="flex items-center gap-2 shrink-0">{ children }</div> }
        </div>
    );
}
