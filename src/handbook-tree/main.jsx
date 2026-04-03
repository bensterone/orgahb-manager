import { createRoot } from '@wordpress/element';
import { setup } from '@shared/api';
import App from './App.jsx';

const vars = window.ORGAHB_TREE_VARS;
if ( vars ) {
	setup( vars.nonce );
}

const root = document.getElementById( 'orgahb-handbook-tree' );
if ( root && vars ) {
	createRoot( root ).render( <App config={vars} /> );
}
