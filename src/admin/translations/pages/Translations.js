import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Languages, Settings, Bug } from 'lucide-react';
import GlobalSearch from '../components/GlobalSearch';
import Tabs from '../components/Tabs';
import SettingsTab from './SettingsTab';
import StringsTab from './StringsTab';
import DebugTab from './DebugTab';
import OrphansPanel from './OrphansPanel';

const TABS = [
    { id: 'languages', label: __( 'Languages', 'snel' ), icon: Languages },
    { id: 'settings', label: __( 'Settings', 'snel' ), icon: Settings },
    { id: 'debug', label: __( 'Debug', 'snel' ), icon: Bug },
];

export default function Translations() {
    const [ active, setActive ] = useState( 'languages' );

    return (
        <div className="p-6">
            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-bold text-gray-900">
                        Snel <em className="font-serif font-normal italic">Translations</em>
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        { __( 'Manage all translations for your multilingual site', 'snel' ) }
                    </p>
                </div>
                <GlobalSearch onNavigate={ () => {} } />
            </div>

            <Tabs tabs={ TABS } active={ active } onChange={ setActive } />

            { active === 'languages' && (
                <>
                    <SettingsTab />
                    <OrphansPanel />
                </>
            ) }
            { active === 'settings' && <StringsTab /> }
            { active === 'debug' && <DebugTab /> }
        </div>
    );
}
