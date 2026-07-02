import { createRoot } from '@wordpress/element';
import Translations from './pages/Translations';
import './styles/main.css';

function mountApp() {
    const container = document.getElementById( 'snel-translations-root' );
    if ( ! container ) return;

    createRoot( container ).render(
        <div className="snel-translations-app">
            <Translations />
        </div>
    );
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', mountApp );
} else {
    mountApp();
}
