/**
 * Reusable Tabs component — same as Snel SEO.
 */
export default function Tabs( { tabs, active, onChange } ) {
    return (
        <div className="flex items-center gap-1 mb-6 border-b border-gray-200">
            { tabs.map( ( tab ) => (
                <button
                    key={ tab.id }
                    onClick={ () => onChange( tab.id ) }
                    className={ `flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px ${ active === tab.id
                        ? 'border-blue-600 text-blue-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700'
                    }` }
                >
                    { tab.icon && <tab.icon size={ 16 } /> }
                    { tab.label }
                    { tab.badge !== undefined && (
                        <span
                            className={ `px-1.5 py-0.5 text-xs rounded-full ${ tab.badgeClass
                                ? tab.badgeClass
                                : active === tab.id
                                    ? 'bg-blue-100 text-blue-600'
                                    : 'bg-gray-100 text-gray-500'
                            }` }
                        >
                            { tab.badge }
                        </span>
                    ) }
                </button>
            ) ) }
        </div>
    );
}
