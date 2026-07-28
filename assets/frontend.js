(function () {
	'use strict';

	var STORAGE_ACCESS = 'grc_access_token';
	var STORAGE_REFRESH = 'grc_refresh_token';
	var STORAGE_CITOYEN = 'grc_citoyen_info';

	function el( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function showMessage( container, text, type ) {
		if ( ! container ) {
			return;
		}
		container.textContent = text;
		container.className = 'grc-form-message grc-form-message--' + ( type || 'info' );
		container.style.display = 'block';
	}

	function getAccessToken() {
		return localStorage.getItem( STORAGE_ACCESS );
	}

	function isCitoyenLoggedIn() {
		return !! getAccessToken();
	}

	function storeSession( data ) {
		localStorage.setItem( STORAGE_ACCESS, data.access_token );
		if ( data.refresh_token ) {
			localStorage.setItem( STORAGE_REFRESH, data.refresh_token );
		}
	}

	function clearSession() {
		localStorage.removeItem( STORAGE_ACCESS );
		localStorage.removeItem( STORAGE_REFRESH );
		localStorage.removeItem( STORAGE_CITOYEN );
	}

	function decodeJwtPayload( token ) {
		try {
			var base64 = token.split( '.' )[ 1 ].replace( /-/g, '+' ).replace( /_/g, '/' );
			return JSON.parse( atob( base64 ) );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * S'assure que le token en localStorage est encore valide (avec 10s de marge).
	 * S'il est expiré, tente un rafraîchissement silencieux via le refresh token.
	 * C'est nécessaire car les routes "publiques" (ex: /demarches) n'émettent pas
	 * d'erreur 401 sur un token expiré — elles retombent silencieusement en mode
	 * invité, ce qui donnait l'impression trompeuse que le citoyen n'était plus reconnu.
	 */
	function ensureFreshToken() {
		var token = getAccessToken();
		if ( ! token ) {
			return Promise.resolve( null );
		}
		var payload = decodeJwtPayload( token );
		if ( payload && payload.exp && ( payload.exp * 1000 ) > ( Date.now() + 10000 ) ) {
			return Promise.resolve( token );
		}

		var refreshToken = localStorage.getItem( STORAGE_REFRESH );
		if ( ! refreshToken ) {
			clearSession();
			return Promise.resolve( null );
		}

		return fetch( grcConfig.restUrl + '/citoyen/refresh', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { refresh_token: refreshToken } )
		} )
			.then( function ( r ) { return r.ok ? r.json() : Promise.reject(); } )
			.then( function ( d ) {
				localStorage.setItem( STORAGE_ACCESS, d.access_token );
				return d.access_token;
			} )
			.catch( function () {
				clearSession();
				return null;
			} );
	}

	/**
	 * Requête authentifiée avec le token citoyen. Rafraîchit le token en amont s'il
	 * est expiré, et tente un refresh de secours une fois en cas de 401 imprévu.
	 */
	function authFetch( url, options, retry ) {
		options = options || {};
		options.headers = options.headers || {};

		return ensureFreshToken().then( function ( token ) {
			if ( token ) {
				options.headers['Authorization'] = 'Bearer ' + token;
			}

			return fetch( url, options ).then( function ( res ) {
				if ( 401 === res.status && ! retry && localStorage.getItem( STORAGE_REFRESH ) ) {
					return fetch( grcConfig.restUrl + '/citoyen/refresh', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { refresh_token: localStorage.getItem( STORAGE_REFRESH ) } )
					} )
						.then( function ( r ) { return r.ok ? r.json() : Promise.reject(); } )
						.then( function ( refreshed ) {
							localStorage.setItem( STORAGE_ACCESS, refreshed.access_token );
							return authFetch( url, options, true );
						} )
						.catch( function () {
							clearSession();
							return res;
						} );
				}
				return res;
			} );
		} );
	}

	function statutLabel( statut ) {
		var labels = {
			nouveau: 'Nouveau', en_cours: 'En cours', assigne: 'Assigné',
			resolu: 'Résolu', cloture: 'Clôturé', reouvert: 'Réouvert'
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
			if ( d.peut_etre_note ) {
				html += '<div class="grc-satisfaction-form" data-demande-id="' + d.id + '">';
				html += '<p class="grc-hint">Cette demande est résolue, donnez votre avis :</p>';
				html += '<div class="grc-stars">';
				for ( var n = 1; n <= 5; n++ ) {
					html += '<button type="button" class="grc-star" data-note="' + n + '">★</button>';
				}
				html += '</div>';
				html += '<textarea class="grc-satisfaction-comment" placeholder="Commentaire (facultatif)" rows="2"></textarea>';
				html += '<button type="button" class="grc-btn-submit grc-satisfaction-submit" disabled>Envoyer mon avis</button>';
				html += '</div>';
			}
			html += '</div>';
		} );
		html += '</div>';
		container.innerHTML = html;
		attachSatisfactionHandlers( container );
	}

	function attachSatisfactionHandlers( container ) {
		container.querySelectorAll( '.grc-satisfaction-form' ).forEach( function ( formEl ) {
			var selectedNote = 0;
			var stars = formEl.querySelectorAll( '.grc-star' );
			var submitBtn = formEl.querySelector( '.grc-satisfaction-submit' );

			stars.forEach( function ( star ) {
				star.addEventListener( 'click', function () {
					selectedNote = parseInt( star.dataset.note, 10 );
					stars.forEach( function ( s ) {
						s.classList.toggle( 'grc-star--active', parseInt( s.dataset.note, 10 ) <= selectedNote );
					} );
					submitBtn.disabled = false;
				} );
			} );

			submitBtn.addEventListener( 'click', function () {
				var demandeId = formEl.dataset.demandeId;
				var commentaire = formEl.querySelector( '.grc-satisfaction-comment' ).value;
				submitBtn.disabled = true;
				submitBtn.textContent = 'Envoi...';

				var body = { note: selectedNote, commentaire: commentaire };
				var guestEmail = container.dataset.guestEmail;
				if ( guestEmail ) {
					body.email = guestEmail;
				}

				authFetch( grcConfig.restUrl + '/demandes/' + demandeId + '/satisfaction', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( body )
				} )
					.then( function ( res ) { return res.ok ? res.json() : Promise.reject(); } )
					.then( function () {
						formEl.innerHTML = '<p class="grc-form-message grc-form-message--success" style="display:block;">Merci pour votre avis !</p>';
					} )
					.catch( function () {
						submitBtn.disabled = false;
						submitBtn.textContent = 'Envoyer mon avis';
					} );
			} );
		} );
	}

	function buildFieldHtml( champ ) {
		var key = champ.key;
		var label = champ.label || key;
		var requis = champ.requis ? 'required' : '';
		var star = champ.requis ? ' <span class="required">*</span>' : '';
		var html = '<div class="grc-field"><label for="grc-champ-' + key + '">' + label + star + '</label>';

		if ( 'textarea' === champ.type ) {
			html += '<textarea id="grc-champ-' + key + '" data-key="' + key + '" rows="4" ' + requis + '></textarea>';
		} else {
			var inputType = 'email' === champ.type ? 'email' : ( 'number' === champ.type ? 'number' : 'text' );
			html += '<input type="' + inputType + '" id="grc-champ-' + key + '" data-key="' + key + '" ' + requis + '>';
		}
		html += '</div>';
		return html;
	}

	function initDemarcheForm( wrapper ) {
		var form = el( '#grc-demarche-form', wrapper );
		var selectorField = el( '#grc-demarche-type-selector', wrapper );
		var typeSelect = el( '#grc-demarche-type-select', wrapper );
		var descriptionEl = el( '#grc-demarche-description', wrapper );
		var dynamicFields = el( '#grc-demarche-dynamic-fields', wrapper );
        var guestFields = el( '#grc-demarche-guest-fields', wrapper );
		var banner = el( '#grc-demarche-connected-banner', wrapper );
		var bannerName = el( '#grc-demarche-connected-name', wrapper );
		var submitBtn = form.querySelector( '.grc-btn-submit' );
		var preselectType = wrapper.dataset.preselectType;
		var types = [];
		var currentChamps = [];

		if ( isCitoyenLoggedIn() ) {
			if ( guestFields ) {
				guestFields.style.display = 'none';
				guestFields.querySelectorAll( 'input' ).forEach( function ( i ) { i.required = false; } );
			}
			if ( banner ) {
				banner.style.display = 'block';
				authFetch( grcConfig.restUrl + '/citoyen/me' )
					.then( function ( res ) { return res.ok ? res.json() : null; } )
					.then( function ( me ) {
						if ( me && bannerName ) {
							bannerName.textContent = ( me.prenom || me.email || 'vous' ) + ( me.nom ? ' ' + me.nom : '' );
						}
					} );
			}
		}

		function renderFieldsForType( type ) {
			currentChamps = type.champs || [];
			descriptionEl.style.display = type.description ? 'block' : 'none';
			descriptionEl.textContent = type.description || '';
			dynamicFields.innerHTML = currentChamps.map( buildFieldHtml ).join( '' );
			submitBtn.disabled = false;
		}

		fetch( grcConfig.restUrl + '/demarches/types' )
			.then( function ( res ) { return res.json(); } )
			.then( function ( result ) {
				types = result || [];
				if ( preselectType ) {
					var match = types.filter( function ( t ) { return t.slug === preselectType; } )[ 0 ];
					if ( match ) {
						renderFieldsForType( match );
					} else {
						dynamicFields.innerHTML = '<p>Type de démarche introuvable ou inactif.</p>';
					}
					return;
				}

				selectorField.style.display = 'block';
				typeSelect.innerHTML = '<option value="">— Sélectionner —</option>' + types.map( function ( t ) {
					return '<option value="' + t.slug + '">' + t.nom + '</option>';
				} ).join( '' );

				typeSelect.addEventListener( 'change', function () {
					var match = types.filter( function ( t ) { return t.slug === typeSelect.value; } )[ 0 ];
					if ( match ) {
						renderFieldsForType( match );
					} else {
						dynamicFields.innerHTML = '';
						submitBtn.disabled = true;
					}
				} );
			} )
			.catch( function () {
				dynamicFields.innerHTML = '<p>Erreur lors du chargement des types de démarches.</p>';
			} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var msgBox = el( '.grc-form-message', form );
			var slug = preselectType || typeSelect.value;

			var donnees = {};
			currentChamps.forEach( function ( champ ) {
				var fieldEl = el( '#grc-champ-' + champ.key, form );
				donnees[ champ.key ] = fieldEl ? fieldEl.value : '';
			} );

			var payload = { type_slug: slug, donnees: donnees };
			if ( ! isCitoyenLoggedIn() ) {
				var emailField = el( '#grc-demarche-email', form );
				payload.email = emailField ? emailField.value : '';
			}

			submitBtn.disabled = true;
			submitBtn.textContent = 'Envoi en cours...';

			authFetch( grcConfig.restUrl + '/demarches', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} )
				.then( function ( res ) { return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error( result.data.message || 'Erreur lors de l\'envoi.' );
					}
					showMessage( msgBox, 'Votre dossier a bien été transmis. Vous serez notifié(e) de son traitement.', 'success' );
					form.reset();
					dynamicFields.innerHTML = '';
				} )
				.catch( function ( err ) {
					showMessage( msgBox, err.message, 'error' );
				} )
				.finally( function () {
					submitBtn.disabled = false;
					submitBtn.textContent = 'Envoyer le dossier';
				} );
		} );
	}

	function initGlobalCitoyenBar() {
		if ( ! isCitoyenLoggedIn() ) {
			return;
		}

		var bar = document.createElement( 'div' );
		bar.id = 'grc-global-bar';
		bar.innerHTML =
			'<div class="grc-global-bar-inner">' +
				'<span id="grc-global-bar-name">Connecté</span>' +
				'<button type="button" id="grc-global-profil-btn" class="grc-btn-link">Mon profil</button>' +
				'<button type="button" id="grc-global-logout-btn" class="grc-btn-link">Se déconnecter</button>' +
			'</div>' +
			'<div id="grc-global-profil-panel" class="grc-global-profil-panel" style="display:none;">' +
				'<form id="grc-global-profil-form" class="grc-form">' +
					'<div class="grc-field"><label>Prénom</label><input type="text" id="grc-gb-prenom"></div>' +
					'<div class="grc-field"><label>Nom</label><input type="text" id="grc-gb-nom"></div>' +
					'<div class="grc-field"><label>Email</label><input type="email" id="grc-gb-email"></div>' +
					'<div class="grc-field"><label>Téléphone</label><input type="tel" id="grc-gb-telephone"></div>' +
					'<button type="submit" class="grc-btn-submit">Enregistrer</button>' +
					'<div class="grc-form-message" style="display:none;"></div>' +
				'</form>' +
				'<hr style="margin:16px 0;border:none;border-top:1px solid #ddd;">' +
				'<form id="grc-global-password-form" class="grc-form">' +
					'<div class="grc-field"><label>Mot de passe actuel</label><input type="password" id="grc-gb-current-password" required></div>' +
					'<div class="grc-field"><label>Nouveau mot de passe</label><input type="password" id="grc-gb-new-password" minlength="8" required></div>' +
					'<button type="submit" class="grc-btn-submit">Changer le mot de passe</button>' +
					'<div class="grc-form-message" style="display:none;"></div>' +
				'</form>' +
			'</div>';

		document.body.insertBefore( bar, document.body.firstChild );

		var nameSpan = el( '#grc-global-bar-name', bar );
		var panel = el( '#grc-global-profil-panel', bar );

		authFetch( grcConfig.restUrl + '/citoyen/me' )
			.then( function ( res ) { return res.ok ? res.json() : null; } )
			.then( function ( me ) {
				if ( ! me ) {
					return;
				}
				nameSpan.textContent = 'Connecté : ' + ( ( me.prenom || '' ) + ' ' + ( me.nom || me.email || '' ) ).trim();
				el( '#grc-gb-prenom', bar ).value = me.prenom || '';
				el( '#grc-gb-nom', bar ).value = me.nom || '';
				el( '#grc-gb-email', bar ).value = me.email || '';
				el( '#grc-gb-telephone', bar ).value = me.telephone || '';
			} );

		el( '#grc-global-profil-btn', bar ).addEventListener( 'click', function () {
			panel.style.display = 'block' === panel.style.display ? 'none' : 'block';
		} );

		el( '#grc-global-logout-btn', bar ).addEventListener( 'click', function () {
			clearSession();
			window.location.reload();
		} );

		el( '#grc-global-profil-form', bar ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var msgBox = el( '.grc-form-message', e.target );
			authFetch( grcConfig.restUrl + '/citoyen/me', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					prenom: el( '#grc-gb-prenom', bar ).value,
					nom: el( '#grc-gb-nom', bar ).value,
					email: el( '#grc-gb-email', bar ).value,
					telephone: el( '#grc-gb-telephone', bar ).value
				} )
			} )
				.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error( result.data.message || 'Erreur lors de la mise à jour.' );
					}
					nameSpan.textContent = 'Connecté : ' + ( ( result.data.prenom || '' ) + ' ' + ( result.data.nom || result.data.email || '' ) ).trim();
					showMessage( msgBox, 'Profil mis à jour.', 'success' );
				} )
				.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
		} );

		el( '#grc-global-password-form', bar ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var msgBox = el( '.grc-form-message', e.target );
			authFetch( grcConfig.restUrl + '/citoyen/password', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					current_password: el( '#grc-gb-current-password', bar ).value,
					new_password: el( '#grc-gb-new-password', bar ).value
				} )
			} )
				.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error( result.data.message || 'Erreur lors du changement de mot de passe.' );
					}
					showMessage( msgBox, 'Mot de passe modifié.', 'success' );
					e.target.reset();
				} )
				.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initGlobalCitoyenBar();

		// ================= Formulaire de signalement =================
		var form = el( '#grc-signalement-form' );
		if ( form ) {
			var guestFields = el( '#grc-guest-fields', form );
			var banner = el( '#grc-connected-banner' );
			var bannerName = el( '#grc-connected-name' );

			if ( isCitoyenLoggedIn() ) {
				if ( guestFields ) {
					guestFields.style.display = 'none';
					guestFields.querySelectorAll( 'input' ).forEach( function ( i ) { i.required = false; } );
				}
				if ( banner ) {
					banner.style.display = 'block';
					authFetch( grcConfig.restUrl + '/citoyen/me' )
						.then( function ( res ) { return res.ok ? res.json() : null; } )
						.then( function ( me ) {
							if ( me && bannerName ) {
								bannerName.textContent = ( me.prenom || me.email || 'vous' ) + ( me.nom ? ' ' + me.nom : '' );
							}
						} );
				}
			}

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', form ) || el( '#grc-form-message', form );
				var submitBtn = form.querySelector( '.grc-btn-submit' );
				submitBtn.disabled = true;
				submitBtn.textContent = 'Envoi en cours...';

				var payload = {
					titre: el( '#grc-titre', form ).value,
					description: el( '#grc-description', form ).value,
					categorie_id: el( '#grc-categorie', form ).value || null,
					adresse_lieu: el( '#grc-adresse', form ).value
				};

				if ( ! isCitoyenLoggedIn() ) {
					payload.prenom = el( '#grc-prenom', form ).value;
					payload.nom = el( '#grc-nom', form ).value;
					payload.email = el( '#grc-email', form ).value;
					payload.telephone = el( '#grc-telephone', form ).value;
				}

				authFetch( grcConfig.restUrl + '/demandes/public-submit', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload )
				} )
					.then( function ( res ) { return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Erreur lors de l\'envoi.' );
						}
						var demande = result.data;
						var photoInput = el( '#grc-photo', form );

						if ( photoInput && photoInput.files && photoInput.files[ 0 ] ) {
							var fd = new FormData();
							fd.append( 'file', photoInput.files[ 0 ] );
							if ( payload.email ) {
								fd.append( 'email', payload.email );
							}
							return authFetch( grcConfig.restUrl + '/demandes/' + demande.id + '/pieces-jointes', {
								method: 'POST',
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

		// ================= Formulaire de démarche administrative =================
		var demarcheWrapper = el( '.grc-demarche-form-wrapper' );
		if ( demarcheWrapper ) {
			initDemarcheForm( demarcheWrapper );
		}

		// ================= Suivi des demandes =================
		var wrapper = el( '.grc-mes-demandes-wrapper' );
		if ( ! wrapper ) {
			return;
		}

		var authForms = el( '#grc-auth-forms', wrapper );
		var connecteView = el( '#grc-citoyen-connecte', wrapper );
		var demandesListe = el( '#grc-demandes-liste', wrapper );
		var demarchesListe = el( '#grc-demarches-liste', wrapper );

		function demarcheStatutLabel( statut ) {
			var labels = {
				en_attente: 'En attente', en_cours: 'En cours', valide: 'Validé',
				rejete: 'Rejeté', complement_requis: 'Complément requis'
			};
			return labels[ statut ] || statut;
		}

		function renderDemarchesList( container, demarches ) {
			if ( ! demarches || ! demarches.length ) {
				container.innerHTML = '<p>Aucune démarche trouvée.</p>';
				return;
			}
			var html = '<div class="grc-demandes-cards">';
			demarches.forEach( function ( d ) {
				var needsAction = 'rejete' === d.statut || 'complement_requis' === d.statut;
				html += '<div class="grc-demande-card">';
				html += '<div class="grc-demande-card-header">';
				html += '<code>#' + d.id + '</code>';
				html += '<span class="grc-badge grc-badge--' + d.statut + '">' + demarcheStatutLabel( d.statut ) + '</span>';
				html += '</div>';
				html += '<h3>' + ( d.type_nom || d.type_demarche ) + '</h3>';
				html += '<p class="grc-demande-date">Soumise le ' + new Date( d.created_at ).toLocaleDateString( 'fr-FR' ) + '</p>';
				html += '<button type="button" class="grc-btn-link grc-demarche-toggle-thread" data-demarche-id="' + d.id + '">' + ( needsAction ? 'Voir le message et répondre' : 'Voir l\'échange' ) + '</button>';
				html += '<div class="grc-demarche-thread" data-demarche-id="' + d.id + '" style="display:none;"></div>';
				html += '</div>';
			} );
			html += '</div>';
			container.innerHTML = html;
			attachDemarcheThreadHandlers( container );
		}

		function attachDemarcheThreadHandlers( container ) {
			container.querySelectorAll( '.grc-demarche-toggle-thread' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var id = btn.dataset.demarcheId;
					var threadEl = container.querySelector( '.grc-demarche-thread[data-demarche-id="' + id + '"]' );
					var isOpen = threadEl.style.display === 'block';
					threadEl.style.display = isOpen ? 'none' : 'block';
					if ( ! isOpen ) {
						loadDemarcheThread( id, threadEl );
					}
				} );
			} );
		}

		function loadDemarcheThread( id, threadEl ) {
			threadEl.innerHTML = '<p>Chargement...</p>';
			authFetch( grcConfig.restUrl + '/demarches/' + id )
				.then( function ( res ) { return res.ok ? res.json() : Promise.reject(); } )
				.then( function ( dossier ) {
					var html = '';
					( dossier.messages || [] ).forEach( function ( m ) {
						html += '<div class="grc-thread-message grc-thread-message--' + m.auteur_type + '">';
						html += '<strong>' + ( 'agent' === m.auteur_type ? 'Mairie' : 'Vous' ) + '</strong>';
						html += '<span class="grc-demande-date"> — ' + new Date( m.created_at ).toLocaleDateString( 'fr-FR' ) + '</span>';
						html += '<p>' + m.contenu + '</p></div>';
					} );
					html += '<textarea class="grc-thread-reply" rows="2" placeholder="Votre réponse..."></textarea>';
					html += '<button type="button" class="grc-btn-submit grc-thread-send" data-demarche-id="' + id + '">Envoyer</button>';
					threadEl.innerHTML = html;

					threadEl.querySelector( '.grc-thread-send' ).addEventListener( 'click', function () {
						var textarea = threadEl.querySelector( '.grc-thread-reply' );
						var contenu = textarea.value.trim();
						if ( ! contenu ) {
							return;
						}
						authFetch( grcConfig.restUrl + '/demarches/' + id + '/messages', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify( { contenu: contenu } )
						} )
							.then( function ( res ) { return res.ok ? res.json() : Promise.reject(); } )
							.then( function () { loadDemarcheThread( id, threadEl ); } );
					} );
				} )
				.catch( function () { threadEl.innerHTML = '<p>Erreur lors du chargement de l\'échange.</p>'; } );
		}

		function loadMesDemandes() {
			demandesListe.innerHTML = '<p>Chargement de vos demandes...</p>';
			authFetch( grcConfig.restUrl + '/mes-demandes' )
				.then( function ( res ) { return res.ok ? res.json() : []; } )
				.then( function ( demandes ) { renderDemandesList( demandesListe, demandes ); } )
				.catch( function () { demandesListe.innerHTML = '<p>Erreur lors du chargement.</p>'; } );

			demarchesListe.innerHTML = '<p>Chargement de vos démarches...</p>';
			authFetch( grcConfig.restUrl + '/mes-demarches' )
				.then( function ( res ) { return res.ok ? res.json() : []; } )
				.then( function ( demarches ) { renderDemarchesList( demarchesListe, demarches ); } )
				.catch( function () { demarchesListe.innerHTML = '<p>Erreur lors du chargement.</p>'; } );
		}

		function showConnecteView() {
			authForms.style.display = 'none';
			connecteView.style.display = 'block';
			loadMesDemandes();
		}

		function showAuthForms() {
			authForms.style.display = 'block';
			connecteView.style.display = 'none';
		}

		if ( isCitoyenLoggedIn() ) {
			showConnecteView();
		} else {
			showAuthForms();
		}

		// ---- Onglets (connexion / inscription / invité) ----
		var tabs = wrapper.querySelectorAll( '.grc-auth-tab' );
		var panels = {
			login: el( '#grc-citoyen-login-form', wrapper ),
			register: el( '#grc-citoyen-register-form', wrapper ),
			guest: el( '#grc-guest-lookup-form', wrapper )
		};
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				tabs.forEach( function ( t ) { t.classList.remove( 'grc-auth-tab--active' ); } );
				tab.classList.add( 'grc-auth-tab--active' );
				Object.keys( panels ).forEach( function ( key ) {
					if ( panels[ key ] ) {
						panels[ key ].style.display = key === tab.dataset.tab ? 'block' : 'none';
					}
				} );
			} );
		} );

		// ---- Connexion ----
		var loginForm = panels.login;
		if ( loginForm ) {
			loginForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', loginForm );
				fetch( grcConfig.restUrl + '/citoyen/login', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						email: el( '#grc-login-email', loginForm ).value,
						password: el( '#grc-login-password', loginForm ).value
					} )
				} )
					.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Identifiants invalides.' );
						}
						storeSession( result.data );
						showConnecteView();
					} )
					.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
			} );
		}

		// ---- Inscription ----
		var registerForm = panels.register;
		if ( registerForm ) {
			registerForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', registerForm );
				fetch( grcConfig.restUrl + '/citoyen/register', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						prenom: el( '#grc-reg-prenom', registerForm ).value,
						nom: el( '#grc-reg-nom', registerForm ).value,
						email: el( '#grc-reg-email', registerForm ).value,
						password: el( '#grc-reg-password', registerForm ).value
					} )
				} )
					.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Erreur lors de l\'inscription.' );
						}
						storeSession( result.data );
						showConnecteView();
					} )
					.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
			} );
		}

		// ---- Déconnexion ----
		var logoutBtn = el( '#grc-citoyen-logout', wrapper );
		if ( logoutBtn ) {
			logoutBtn.addEventListener( 'click', function () {
				clearSession();
				showAuthForms();
			} );
		}

		// ---- Suivi invité ----
		var guestForm = panels.guest;
		if ( guestForm ) {
			guestForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var resultsContainer = el( '#grc-guest-results', wrapper );
				var payload = {
					numero_suivi: el( '#grc-lookup-numero', guestForm ).value,
					email: el( '#grc-lookup-email', guestForm ).value
				};
				resultsContainer.innerHTML = '<p>Recherche en cours...</p>';

				fetch( grcConfig.restUrl + '/demandes/guest-lookup', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload )
				} )
					.then( function ( res ) { return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							resultsContainer.innerHTML = '<p>Aucune demande trouvée pour ces informations.</p>';
							return;
						}
						resultsContainer.dataset.guestEmail = payload.email;
						renderDemandesList( resultsContainer, [ result.data ] );
					} )
					.catch( function () {
						resultsContainer.innerHTML = '<p>Erreur lors de la recherche.</p>';
					} );
			} );
		}
	} );
})();
