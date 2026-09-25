/**
 * Entry point of the creator dashboard (build/dashboard.js). The shell prints
 * the script after the root element, so the element exists when this runs.
 */
import { mountDashboard } from './mount';
import './styles/index.scss';

const root = document.getElementById( 'merchboard-dashboard-root' );

if ( root !== null ) {
	mountDashboard( root, window.merchboardDashboard, window.location.origin );
}
