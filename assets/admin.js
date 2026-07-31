(function () {
	'use strict';

	var TYPE_LABELS = {
		text: 'Texte court',
		textarea: 'Texte long',
		email: 'Email',
		number: 'Nombre',
		date: 'Date',
		phone: 'Téléphone',
		file: 'Fichier (PDF/.docx)'
	};

	function slugify( str ) {
		return str
			.toLowerCase()
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
	}

	function buildRow( champ ) {
		champ = champ || { key: '', label: '', type: 'text', requis: false };

		var row = document.createElement( 'div' );
		row.className = 'grc-champ-row';
		row.innerHTML =
			'<span class="grc-champ-order">' +
				'<button type="button" class="grc-champ-up" title="Monter">▲</button>' +
				'<button type="button" class="grc-champ-down" title="Descendre">▼</button>' +
			'</span>' +
			'<input type="text" class="grc-champ-label" placeholder="Libellé (ex: Adresse du terrain)" value="' + ( champ.label || '' ).replace( /"/g, '&quot;' ) + '">' +
			'<input type="text" class="grc-champ-key" placeholder="clé (auto)" value="' + ( champ.key || '' ).replace( /"/g, '&quot;' ) + '">' +
			'<select class="grc-champ-type">' +
				Object.keys( TYPE_LABELS ).map( function ( val ) {
					var selected = val === champ.type ? ' selected' : '';
					return '<option value="' + val + '"' + selected + '>' + TYPE_LABELS[ val ] + '</option>';
				} ).join( '' ) +
			'</select>' +
			'<label class="grc-champ-requis-label"><input type="checkbox" class="grc-champ-requis" ' + ( champ.requis ? 'checked' : '' ) + '> Obligatoire</label>' +
			'<button type="button" class="button grc-champ-remove" title="Supprimer ce champ">✕</button>';

		var labelInput = row.querySelector( '.grc-champ-label' );
		var keyInput = row.querySelector( '.grc-champ-key' );

		// Auto-génère la clé technique depuis le libellé, tant que l'utilisateur
		// n'a pas lui-même modifié la clé manuellement.
		var keyManuallyEdited = !! champ.key;
		labelInput.addEventListener( 'input', function () {
			if ( ! keyManuallyEdited ) {
				keyInput.value = slugify( labelInput.value );
			}
		} );
		keyInput.addEventListener( 'input', function () {
			keyManuallyEdited = true;
		} );

		row.querySelector( '.grc-champ-remove' ).addEventListener( 'click', function () {
			row.remove();
		} );

		row.querySelector( '.grc-champ-up' ).addEventListener( 'click', function () {
			var prev = row.previousElementSibling;
			if ( prev ) {
				row.parentNode.insertBefore( row, prev );
			}
		} );

		row.querySelector( '.grc-champ-down' ).addEventListener( 'click', function () {
			var next = row.nextElementSibling;
			if ( next ) {
				row.parentNode.insertBefore( next, row );
			}
		} );

		return row;
	}

	function initChampsBuilder( container ) {
		var initial = [];
		try {
			initial = JSON.parse( container.dataset.initial || '[]' );
		} catch ( e ) {
			initial = [];
		}

		if ( ! initial.length ) {
			container.appendChild( buildRow() );
		} else {
			initial.forEach( function ( champ ) {
				container.appendChild( buildRow( champ ) );
			} );
		}
	}

	function serializeChamps( container ) {
		var rows = container.querySelectorAll( '.grc-champ-row' );
		var champs = [];
		rows.forEach( function ( row ) {
			var label = row.querySelector( '.grc-champ-label' ).value.trim();
			if ( ! label ) {
				return;
			}
			var keyInput = row.querySelector( '.grc-champ-key' ).value.trim();
			champs.push( {
				key: keyInput || slugify( label ),
				label: label,
				type: row.querySelector( '.grc-champ-type' ).value,
				requis: row.querySelector( '.grc-champ-requis' ).checked
			} );
		} );
		return champs;
	}

	/**
	 * Déconnexion automatique après inactivité sur les écrans GRC (recommandation
	 * CNIL), configurable dans Réglages GRC. Alerte 1 minute avant expiration.
	 */
	function initAdminIdleTimeout() {
		if ( typeof grcAdminConfig === 'undefined' ) {
			return;
		}
		var timeoutMs = ( grcAdminConfig.sessionTimeoutMinutes || 30 ) * 60 * 1000;
		var warningMs = Math.max( 0, timeoutMs - 60000 );
		var warningTimer, logoutTimer, warningShown = false;

		function showWarning() {
			warningShown = true;
			if ( window.confirm( 'Votre session administrateur va expirer dans 1 minute pour votre sécurité (délai d\'inactivité). Cliquez sur OK pour rester connecté(e).' ) ) {
				resetTimers();
			}
		}

		function doLogout() {
			window.location.href = grcAdminConfig.logoutUrl;
		}

		function resetTimers() {
			warningShown = false;
			clearTimeout( warningTimer );
			clearTimeout( logoutTimer );
			warningTimer = setTimeout( showWarning, warningMs );
			logoutTimer = setTimeout( doLogout, timeoutMs );
		}

		[ 'mousemove', 'keydown', 'click', 'scroll' ].forEach( function ( evt ) {
			document.addEventListener( evt, function () {
				if ( ! warningShown ) {
					resetTimers();
				}
			}, { passive: true } );
		} );

		resetTimers();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initAdminIdleTimeout();
		document.querySelectorAll( '.grc-champs-builder' ).forEach( function ( container ) {
			initChampsBuilder( container );
		} );

		document.querySelectorAll( '.grc-champs-add-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var container = document.getElementById( btn.dataset.target );
				if ( container ) {
					container.appendChild( buildRow() );
				}
			} );
		} );

		document.querySelectorAll( '.grc-demarche-type-form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function () {
				var container = form.querySelector( '.grc-champs-builder' );
				var hiddenInput = form.querySelector( '.grc-champs-json-input' );
				if ( container && hiddenInput ) {
					hiddenInput.value = JSON.stringify( serializeChamps( container ) );
				}
				// Pas de preventDefault : la valeur est déjà à jour, la soumission continue normalement.
			} );
		} );
	} );
})();
