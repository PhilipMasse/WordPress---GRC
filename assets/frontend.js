(function () {
	'use strict';

	function el( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function showMessage( container, text, type ) {
		container.textContent = text;
		container.className = 'grc-form-message grc-form-message--' + ( type || 'info' );
		container.style.display = 'block';
	}

	function statutLabel( statut ) {
		var labels = {
			nouveau: 'Nouveau',
			en_cours: 'En cours',
			assigne: 'Assigné',
			resolu: 'Résolu',
			cloture: 'Clôturé',
			reouvert: 'Réouvert'
		};
		return labels[ statut ] || statut;
	}

	function renderDemandesList( container, demandes ) {
		if ( ! demandes || ! demandes.length ) {
			container.innerHTML = '<p>Aucune demande trouvée.</p>';
			return;
		}
		var html = '<div class="grc-demandes-cards">';
		demandes.forEach( function ( d ) {
			html += '<div class="grc-demande-card">';
			html += '<div class="grc-demande-card-header">';
			html += '<code>' + d.numero_suivi + '</code>';
			html += '<span class="grc-badge grc-badge--' + d.statut + '">' + statutLabel( d.statut ) + '</span>';
			html += '</div>';
			html += '<h3>' + d.titre + '</h3>';
			if ( d.pieces_jointes && d.pieces_jointes.length ) {
				html += '<p class="grc-demande-pj">' + d.pieces_jointes.length + ' pièce(s) jointe(s)</p>';
			}
			html += '<p class="grc-demande-date">Créée le ' + new Date( d.created_at ).toLocaleDateString( 'fr-FR' ) + '</p>';
			html += '</div>';
		} );
		html += '</div>';
		container.innerHTML = html;
	}

	// ---- Formulaire de signalement ----
	document.addEventListener( 'DOMContentLoaded', function () {
		var form = el( '#grc-signalement-form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '#grc-form-message', form );
				var submitBtn = form.querySelector( '.grc-btn-submit' );
				submitBtn.disabled = true;
				submitBtn.textContent = 'Envoi en cours...';

				var payload = {
					titre: el( '#grc-titre', form ).value,
					description: el( '#grc-description', form ).value,
					categorie_id: el( '#grc-categorie', form ).value || null,
					adresse_lieu: el( '#grc-adresse', form ).value
				};

				if ( ! grcConfig.isLoggedIn ) {
					payload.prenom = el( '#grc-prenom', form ).value;
					payload.nom = el( '#grc-nom', form ).value;
					payload.email = el( '#grc-email', form ).value;
					payload.telephone = el( '#grc-telephone', form ).value;
				}

				fetch( grcConfig.restUrl + '/demandes/public-submit', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': grcConfig.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( payload )
				} )
					.then( function ( res ) { return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Erreur lors de l\'envoi.' );
						}
						var demande = result.data;
						var photoInput = el( '#grc-photo', form );

						if ( photoInput.files && photoInput.files[ 0 ] ) {
							var fd = new FormData();
							fd.append( 'file', photoInput.files[ 0 ] );
							if ( payload.email ) {
								fd.append( 'email', payload.email );
							}
							return fetch( grcConfig.restUrl + '/demandes/' + demande.id + '/pieces-jointes', {
								method: 'POST',
								headers: { 'X-WP-Nonce': grcConfig.nonce },
								credentials: 'same-origin',
								body: fd
							} ).then( function () { return demande; } );
						}
						return demande;
					} )
					.then( function ( demande ) {
						showMessage( msgBox, 'Signalement envoyé avec succès. Votre numéro de suivi : ' + demande.numero_suivi, 'success' );
						form.reset();
					} )
					.catch( function ( err ) {
						showMessage( msgBox, err.message, 'error' );
					} )
					.finally( function () {
						submitBtn.disabled = false;
						submitBtn.textContent = 'Envoyer le signalement';
					} );
			} );
		}

		// ---- Suivi connecté ----
		var wrapperConnecte = document.querySelector( '.grc-mes-demandes-wrapper[data-mode="connecte"]' );
		if ( wrapperConnecte ) {
			var listContainer = el( '#grc-demandes-liste', wrapperConnecte );
			fetch( grcConfig.restUrl + '/mes-demandes', {
				headers: { 'X-WP-Nonce': grcConfig.nonce },
				credentials: 'same-origin'
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( demandes ) { renderDemandesList( listContainer, demandes ); } )
				.catch( function () { listContainer.innerHTML = '<p>Erreur lors du chargement.</p>'; } );
		}

		// ---- Suivi invité ----
		var guestForm = el( '#grc-guest-lookup-form' );
		if ( guestForm ) {
			guestForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var listContainer = el( '#grc-demandes-liste', guestForm.parentElement );
				var payload = {
					numero_suivi: el( '#grc-lookup-numero', guestForm ).value,
					email: el( '#grc-lookup-email', guestForm ).value
				};
				listContainer.innerHTML = '<p>Recherche en cours...</p>';

				fetch( grcConfig.restUrl + '/demandes/guest-lookup', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload )
				} )
					.then( function ( res ) { return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							listContainer.innerHTML = '<p>Aucune demande trouvée pour ces informations.</p>';
							return;
						}
						renderDemandesList( listContainer, [ result.data ] );
					} )
					.catch( function () {
						listContainer.innerHTML = '<p>Erreur lors de la recherche.</p>';
					} );
			} );
		}
	} );
})();
