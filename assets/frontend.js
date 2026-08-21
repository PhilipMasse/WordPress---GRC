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
		// role="status" + aria-live="polite" : garantit qu'un message de
		// succès ou d'erreur affiché dans N'IMPORTE QUEL formulaire du site
		// (connexion, inscription, RDV, fil de messages, profil...) soit
		// annoncé automatiquement par un lecteur d'écran, sans que
		// l'utilisateur ait à naviguer manuellement pour le découvrir.
		container.setAttribute( 'role', 'status' );
		container.setAttribute( 'aria-live', 'polite' );
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
		// Affiche immédiatement la barre citoyenne (connexion, inscription ou
		// validation 2FA venant de réussir) plutôt que d'attendre un
		// rechargement de page pour qu'elle apparaisse.
		initGlobalCitoyenBar();
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

	function renderDemandesList( container, demandes, vue ) {
		if ( ! demandes || ! demandes.length ) {
			container.innerHTML = '<p>Aucune demande trouvée.</p>';
			return;
		}

		if ( 'list' === vue ) {
			var rows = '<table class="grc-liste-table"><thead><tr><th>N° suivi</th><th>Titre</th><th>Statut</th><th>Date</th><th></th></tr></thead><tbody>';
			demandes.forEach( function ( d ) {
				rows += '<tr>';
				rows += '<td><code>' + d.numero_suivi + '</code></td>';
				rows += '<td>' + d.titre + '</td>';
				rows += '<td><span class="grc-badge grc-badge--' + d.statut + '">' + statutLabel( d.statut ) + '</span></td>';
				rows += '<td>' + new Date( d.created_at ).toLocaleDateString( 'fr-FR' ) + '</td>';
				rows += '<td>' + ( d.peut_etre_note ? '<span class="grc-hint">À noter</span>' : '' ) + '</td>';
				rows += '</tr>';
			} );
			rows += '</tbody></table>';
			container.innerHTML = rows;
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
				html += '<div class="grc-stars" role="radiogroup" aria-label="Note de satisfaction sur 5 étoiles">';
				for ( var n = 1; n <= 5; n++ ) {
					html += '<button type="button" class="grc-star" data-note="' + n + '" role="radio" aria-checked="false" aria-label="' + n + ' étoile' + ( n > 1 ? 's' : '' ) + ' sur 5">★</button>';
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
						var active = parseInt( s.dataset.note, 10 ) <= selectedNote;
						s.classList.toggle( 'grc-star--active', active );
						s.setAttribute( 'aria-checked', parseInt( s.dataset.note, 10 ) === selectedNote ? 'true' : 'false' );
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

	var GRC_COUNTRIES = [
		{ code: 'FR', dial: '+33', flag: '🇫🇷', name: 'France' },
		{ code: 'BE', dial: '+32', flag: '🇧🇪', name: 'Belgique' },
		{ code: 'CH', dial: '+41', flag: '🇨🇭', name: 'Suisse' },
		{ code: 'LU', dial: '+352', flag: '🇱🇺', name: 'Luxembourg' },
		{ code: 'MC', dial: '+377', flag: '🇲🇨', name: 'Monaco' },
		{ code: 'DE', dial: '+49', flag: '🇩🇪', name: 'Allemagne' },
		{ code: 'ES', dial: '+34', flag: '🇪🇸', name: 'Espagne' },
		{ code: 'IT', dial: '+39', flag: '🇮🇹', name: 'Italie' },
		{ code: 'GB', dial: '+44', flag: '🇬🇧', name: 'Royaume-Uni' },
		{ code: 'PT', dial: '+351', flag: '🇵🇹', name: 'Portugal' },
		{ code: 'NL', dial: '+31', flag: '🇳🇱', name: 'Pays-Bas' },
		{ code: 'US', dial: '+1', flag: '🇺🇸', name: 'États-Unis' },
		{ code: 'CA', dial: '+1', flag: '🇨🇦', name: 'Canada' },
		{ code: 'MA', dial: '+212', flag: '🇲🇦', name: 'Maroc' },
		{ code: 'DZ', dial: '+213', flag: '🇩🇿', name: 'Algérie' },
		{ code: 'TN', dial: '+216', flag: '🇹🇳', name: 'Tunisie' },
		{ code: 'RE', dial: '+262', flag: '🇷🇪', name: 'La Réunion' },
		{ code: 'GP', dial: '+590', flag: '🇬🇵', name: 'Guadeloupe' },
		{ code: 'MQ', dial: '+596', flag: '🇲🇶', name: 'Martinique' },
		{ code: 'GF', dial: '+594', flag: '🇬🇫', name: 'Guyane' }
	];

	function buildPhoneCountryOptions() {
		return GRC_COUNTRIES.map( function ( c ) {
			var selected = 'FR' === c.code ? ' selected' : '';
			return '<option value="' + c.dial + '"' + selected + '>' + c.flag + ' ' + c.dial + ' (' + c.name + ')</option>';
		} ).join( '' );
	}

	function buildFieldHtml( champ ) {
		var key = champ.key;
		var label = champ.label || key;
		var requis = champ.requis ? 'required' : '';
		var star = champ.requis ? ' <span class="required">*</span>' : '';
		var html = '<div class="grc-field"><label for="grc-champ-' + key + '">' + label + star + '</label>';

		if ( 'file' === champ.type ) {
			html += '<input type="file" id="grc-champ-' + key + '" data-key="' + key + '" data-filetype="1" multiple accept=".pdf,.docx,.jpg,.jpeg,.png,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png" ' + requis + '>';
			html += '<p class="grc-hint">Formats acceptés : PDF ou Word (.docx), 8 Mo maximum par fichier. Plusieurs fichiers peuvent être sélectionnés.</p>';
		} else if ( 'date' === champ.type ) {
			html += '<input type="date" id="grc-champ-' + key + '" data-key="' + key + '" ' + requis + '>';
		} else if ( 'phone' === champ.type ) {
			html += '<div class="grc-phone-field" id="grc-champ-' + key + '" data-key="' + key + '" data-phonetype="1">';
			html += '<select class="grc-phone-country">' + buildPhoneCountryOptions() + '</select>';
			html += '<input type="tel" class="grc-phone-number" placeholder="6 12 34 56 78" ' + requis + '>';
			html += '</div>';
		} else if ( 'textarea' === champ.type ) {
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

		var demarcheCaptchaProvider = grcConfig.captchaProvider || 'interne';
		var demarcheUsingProvider = ! isCitoyenLoggedIn() && 'interne' !== demarcheCaptchaProvider;
		var demarcheCaptchaQuestionEl, demarcheCaptchaTokenEl, demarcheCaptchaInputEl, demarcheLoadCaptcha;

		if ( ! isCitoyenLoggedIn() && 'interne' === demarcheCaptchaProvider ) {
			demarcheCaptchaQuestionEl = el( '#grc-demarche-captcha-question', wrapper );
			demarcheCaptchaTokenEl = el( '#grc-demarche-captcha-token', wrapper );
			demarcheCaptchaInputEl = el( '#grc-demarche-captcha', wrapper );

			demarcheLoadCaptcha = function () {
				if ( ! demarcheCaptchaQuestionEl ) { return; }
				demarcheCaptchaQuestionEl.textContent = 'Chargement...';
				demarcheCaptchaInputEl.value = '';
				fetch( grcConfig.restUrl + '/captcha' )
					.then( function ( res ) { return res.json(); } )
					.then( function ( data ) {
						demarcheCaptchaQuestionEl.textContent = data.question;
						demarcheCaptchaTokenEl.value = data.token;
					} )
					.catch( function () { demarcheCaptchaQuestionEl.textContent = 'Erreur de chargement de la vérification anti-robot.'; } );
			};
			demarcheLoadCaptcha();
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
			var fichiers = [];
			currentChamps.forEach( function ( champ ) {
				var fieldEl = el( '#grc-champ-' + champ.key, form );
				if ( ! fieldEl ) {
					return;
				}
				if ( 'file' === champ.type ) {
					var selectedFiles = fieldEl.files ? Array.prototype.slice.call( fieldEl.files ) : [];
					fichiers = fichiers.concat( selectedFiles );
					donnees[ champ.key ] = selectedFiles.map( function ( f ) { return f.name; } ).join( ', ' );
				} else if ( 'phone' === champ.type ) {
					var dial = fieldEl.querySelector( '.grc-phone-country' ).value;
					var num = fieldEl.querySelector( '.grc-phone-number' ).value.replace( /[^\d]/g, '' );
					donnees[ champ.key ] = num ? ( dial + num ) : '';
				} else {
					donnees[ champ.key ] = fieldEl.value;
				}
			} );

			var payload = { type_slug: slug, donnees: donnees };
			if ( ! isCitoyenLoggedIn() ) {
				var emailField = el( '#grc-demarche-email', form );
				payload.email = emailField ? emailField.value : '';
				payload.site_web = el( '#grc-demarche-site-web', form ) ? el( '#grc-demarche-site-web', form ).value : '';

				if ( demarcheUsingProvider ) {
					var demarcheResponseField = form.querySelector( '[name="' + grcConfig.captchaResponseField + '"]' );
					payload.captcha_provider_token = demarcheResponseField ? demarcheResponseField.value : '';
				} else if ( demarcheCaptchaTokenEl ) {
					payload.captcha_token = demarcheCaptchaTokenEl.value;
					payload.captcha_reponse = demarcheCaptchaInputEl.value;
				}
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
					var dossierId = result.data.id;

					if ( ! fichiers.length ) {
						return { uploadsOk: true };
					}

					var fd = new FormData();
					fichiers.forEach( function ( file ) {
						fd.append( 'files[]', file );
					} );
					if ( payload.email ) {
						fd.append( 'email', payload.email );
					}

					return authFetch( grcConfig.restUrl + '/demarches/' + dossierId + '/pieces-jointes', {
						method: 'POST',
						body: fd
					} )
						.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
						.then( function ( result ) {
							if ( ! result.ok ) {
								throw new Error( result.data.message || 'Erreur lors de l\'envoi des fichiers.' );
							}
							var failed = result.data.filter( function ( r ) { return r.error; } );
							if ( failed.length ) {
								throw new Error( 'Dossier envoyé, mais certains fichiers ont été refusés : ' + failed.map( function ( f ) { return f.nom_original + ' (' + f.message + ')'; } ).join( ' / ' ) );
							}
							return { uploadsOk: true };
						} );
				} )
				.then( function () {
					showMessage( msgBox, 'Votre dossier a bien été transmis. Vous serez notifié(e) de son traitement.', 'success' );
					form.reset();
					dynamicFields.innerHTML = '';
				} )
				.catch( function ( err ) {
					showMessage( msgBox, err.message, 'error' );
					if ( demarcheLoadCaptcha ) {
						demarcheLoadCaptcha(); // Nouveau défi requis après tout échec (le précédent a été consommé).
					}
				} )
				.finally( function () {
					submitBtn.disabled = false;
					submitBtn.textContent = 'Envoyer le dossier';
				} );
		} );
	}

	/**
	 * Affiche une pop-up incitant le citoyen à activer la double
	 * authentification s'il ne l'a pas encore fait — non bloquante, avec un
	 * délai de rappel de 7 jours si l'utilisateur choisit "Plus tard".
	 */
	function maybeAfficherPromptDeuxFacteurs( me ) {
		if ( me.two_factor_method ) {
			return; // Déjà activée, rien à faire.
		}
		if ( document.getElementById( 'grc-2fa-prompt-overlay' ) ) {
			return; // Déjà affichée dans cette page.
		}

		var cleRappel = 'grc_2fa_prompt_reporte_jusqua';
		var reporteJusqua = parseInt( localStorage.getItem( cleRappel ) || '0', 10 );
		if ( Date.now() < reporteJusqua ) {
			return;
		}

		var overlay = document.createElement( 'div' );
		overlay.id = 'grc-2fa-prompt-overlay';
		overlay.className = 'grc-2fa-prompt-overlay';
		overlay.innerHTML =
			'<div class="grc-2fa-prompt-box" role="dialog" aria-modal="true" aria-labelledby="grc-2fa-prompt-titre">' +
				'<h3 id="grc-2fa-prompt-titre">🔒 Protégez votre compte</h3>' +
				'<p>Votre espace citoyen n\'est protégé que par un mot de passe. Activez la double authentification (par email ou application) pour renforcer sa sécurité — cela prend moins d\'une minute.</p>' +
				'<div class="grc-2fa-prompt-actions">' +
					'<button type="button" id="grc-2fa-prompt-activer" class="grc-btn-submit">Activer maintenant</button>' +
					'<button type="button" id="grc-2fa-prompt-plus-tard" class="grc-btn-link">Plus tard</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild( overlay );

		// Gestion du focus clavier (RGAA/WCAG 2.4.3, 2.1.2) : le focus doit
		// entrer dans la boîte de dialogue à l'ouverture, y rester piégé tant
		// qu'elle est affichée, et revenir à l'élément d'origine à la fermeture.
		var elementAvantOuverture = document.activeElement;
		var boiteDialogue = el( '.grc-2fa-prompt-box', overlay );
		var elementsFocusables = boiteDialogue.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		var premierFocusable = elementsFocusables[ 0 ];
		var dernierFocusable = elementsFocusables[ elementsFocusables.length - 1 ];

		function piegerFocus( e ) {
			if ( 'Tab' !== e.key ) {
				return;
			}
			if ( e.shiftKey && document.activeElement === premierFocusable ) {
				e.preventDefault();
				dernierFocusable.focus();
			} else if ( ! e.shiftKey && document.activeElement === dernierFocusable ) {
				e.preventDefault();
				premierFocusable.focus();
			}
		}
		overlay.addEventListener( 'keydown', piegerFocus );

		if ( premierFocusable ) {
			premierFocusable.focus();
		}

		function fermerPrompt() {
			overlay.remove();
			if ( elementAvantOuverture && elementAvantOuverture.focus ) {
				elementAvantOuverture.focus();
			}
		}

		document.getElementById( 'grc-2fa-prompt-plus-tard' ).addEventListener( 'click', function () {
			localStorage.setItem( cleRappel, String( Date.now() + 7 * 24 * 60 * 60 * 1000 ) );
			fermerPrompt();
		} );

		document.getElementById( 'grc-2fa-prompt-activer' ).addEventListener( 'click', function () {
			fermerPrompt();
			var bar = document.getElementById( 'grc-global-bar' );
			var panel = bar ? el( '#grc-global-profil-panel', bar ) : null;
			if ( panel ) {
				panel.style.display = 'block';
				var boutonProfil = el( '#grc-global-profil-btn', bar );
				if ( boutonProfil ) {
					boutonProfil.setAttribute( 'aria-expanded', 'true' );
				}
				panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				var cible = el( '#grc-2fa-activer-email', bar );
				if ( cible && cible.focus ) {
					cible.focus();
				}
			}
		} );

		// Fermeture au clic en dehors de la boîte, ou à la touche Échap —
		// équivaut à "Plus tard" (ne bloque jamais l'accès au site).
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				localStorage.setItem( cleRappel, String( Date.now() + 7 * 24 * 60 * 60 * 1000 ) );
				fermerPrompt();
			}
		} );
		document.addEventListener( 'keydown', function gestionEchap( e ) {
			if ( 'Escape' === e.key && document.getElementById( 'grc-2fa-prompt-overlay' ) ) {
				localStorage.setItem( cleRappel, String( Date.now() + 7 * 24 * 60 * 60 * 1000 ) );
				fermerPrompt();
				document.removeEventListener( 'keydown', gestionEchap );
			}
		} );
	}

	function initGlobalCitoyenBar() {
		if ( ! isCitoyenLoggedIn() ) {
			return;
		}
		if ( document.getElementById( 'grc-global-bar' ) ) {
			return; // Déjà affichée (ex: appelée à nouveau juste après une connexion réussie).
		}

		var bar = document.createElement( 'div' );
		bar.id = 'grc-global-bar';
		bar.innerHTML =
			'<div class="grc-global-bar-inner">' +
				'<span id="grc-global-bar-name">Connecté</span>' +
				'<nav id="grc-global-nav" class="grc-global-nav" aria-label="Navigation de l\'espace citoyen"></nav>' +
				'<span class="grc-global-bar-spacer"></span>' +
				'<button type="button" id="grc-global-profil-btn" class="grc-btn-link" aria-expanded="false" aria-controls="grc-global-profil-panel">Mon profil</button>' +
				'<button type="button" id="grc-global-logout-btn" class="grc-btn-link">Se déconnecter</button>' +
			'</div>' +
			'<div id="grc-global-profil-panel" class="grc-global-profil-panel" role="region" aria-label="Mon profil" tabindex="-1" style="display:none;">' +
				'<h3>Mon profil</h3>' +
				'<form id="grc-global-profil-form" class="grc-form">' +
					'<div class="grc-field"><label for="grc-gb-prenom">Prénom</label><input type="text" id="grc-gb-prenom" autocomplete="given-name"></div>' +
					'<div class="grc-field"><label for="grc-gb-nom">Nom</label><input type="text" id="grc-gb-nom" autocomplete="family-name"></div>' +
					'<div class="grc-field"><label for="grc-gb-email">Email</label><input type="email" id="grc-gb-email" autocomplete="email"></div>' +
					'<div class="grc-field"><label for="grc-gb-telephone">Téléphone</label><input type="tel" id="grc-gb-telephone" autocomplete="tel"></div>' +
					'<button type="submit" class="grc-btn-submit">Enregistrer</button>' +
					'<div class="grc-form-message" style="display:none;"></div>' +
				'</form>' +
				'<hr style="margin:16px 0;border:none;border-top:1px solid #ddd;">' +
				'<form id="grc-global-password-form" class="grc-form">' +
					'<div class="grc-field"><label for="grc-gb-current-password">Mot de passe actuel</label><input type="password" id="grc-gb-current-password" autocomplete="current-password" required></div>' +
					'<div class="grc-field"><label for="grc-gb-new-password">Nouveau mot de passe</label><input type="password" id="grc-gb-new-password" minlength="8" autocomplete="new-password" required></div>' +
					'<button type="submit" class="grc-btn-submit">Changer le mot de passe</button>' +
					'<div class="grc-form-message" style="display:none;"></div>' +
				'</form>' +
				'<hr style="margin:16px 0;border:none;border-top:1px solid #ddd;">' +
				'<div id="grc-2fa-section">' +
					'<h4>Double authentification</h4>' +
					'<p id="grc-2fa-statut" class="grc-hint">Vérification du statut...</p>' +
					'<div id="grc-2fa-choix" style="display:none;">' +
						'<button type="button" id="grc-2fa-activer-email" class="button">Activer par email</button> ' +
						'<button type="button" id="grc-2fa-activer-totp" class="button">Activer par application</button>' +
					'</div>' +
					'<div id="grc-2fa-totp-setup" style="display:none;margin-top:10px;">' +
						'<p class="grc-hint">Scannez ce QR code avec votre application d\'authentification (Google Authenticator, Authy...), puis saisissez le code affiché pour confirmer.</p>' +
						'<div id="grc-2fa-qrcode" aria-hidden="true" style="margin:10px 0;"></div>' +
						'<p class="grc-hint">Ou saisissez cette clé manuellement : <code id="grc-2fa-secret-manuel"></code></p>' +
						'<input type="text" id="grc-2fa-totp-code" inputmode="numeric" placeholder="123456" style="max-width:120px;">' +
						'<button type="button" id="grc-2fa-totp-confirmer" class="button button-primary">Confirmer l\'activation</button>' +
					'</div>' +
					'<button type="button" id="grc-2fa-desactiver" class="button" style="display:none;color:#b32d2e;">Désactiver la double authentification</button>' +
					'<div id="grc-2fa-message" class="grc-form-message" role="status" aria-live="polite" style="display:none;"></div>' +
				'</div>' +
			'</div>';

		document.body.insertBefore( bar, document.body.firstChild );

		var navEl = el( '#grc-global-nav', bar );
		var navLinks = [
			{ url: grcConfig.pages && grcConfig.pages.signalement, label: 'Signaler un problème' },
			{ url: grcConfig.pages && grcConfig.pages.mesDemandes, label: 'Mes demandes' },
			{ url: grcConfig.pages && grcConfig.pages.demarche, label: 'Faire une démarche' },
			{ url: grcConfig.pages && grcConfig.pages.rdv, label: 'Prendre rendez-vous' }
		];
		navLinks.forEach( function ( link ) {
			if ( ! link.url ) {
				return;
			}
			var a = document.createElement( 'a' );
			a.href = link.url;
			a.textContent = link.label;
			if ( window.location.href.split( '?' )[ 0 ].replace( /\/$/, '' ) === link.url.replace( /\/$/, '' ) ) {
				a.className = 'grc-global-nav-active';
				a.setAttribute( 'aria-current', 'page' );
			}
			navEl.appendChild( a );
		} );

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
				maybeAfficherPromptDeuxFacteurs( me );
			} );

		var profilBtn = el( '#grc-global-profil-btn', bar );
		profilBtn.addEventListener( 'click', function () {
			var ouvrir = 'block' !== panel.style.display;
			panel.style.display = ouvrir ? 'block' : 'none';
			profilBtn.setAttribute( 'aria-expanded', ouvrir ? 'true' : 'false' );
			if ( ouvrir ) {
				panel.focus();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && 'block' === panel.style.display ) {
				panel.style.display = 'none';
				profilBtn.setAttribute( 'aria-expanded', 'false' );
				profilBtn.focus();
			}
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

		// --- Double authentification ------------------------------------
		var statutEl = el( '#grc-2fa-statut', bar );
		var choixEl = el( '#grc-2fa-choix', bar );
		var totpSetupEl = el( '#grc-2fa-totp-setup', bar );
		var desactiverBtn = el( '#grc-2fa-desactiver', bar );
		var msg2fa = el( '#grc-2fa-message', bar );

		function rafraichir2faStatut() {
			authFetch( grcConfig.restUrl + '/citoyen/me' )
				.then( function ( res ) { return res.ok ? res.json() : null; } )
				.then( function ( me ) {
					if ( ! me ) { return; }
					totpSetupEl.style.display = 'none';
					if ( me.two_factor_method ) {
						var label = 'totp' === me.two_factor_method ? 'application d\'authentification' : 'email';
						statutEl.textContent = '✅ Double authentification active (' + label + ').';
						choixEl.style.display = 'none';
						desactiverBtn.style.display = 'inline-block';
					} else {
						statutEl.textContent = 'Double authentification non activée. Recommandée pour renforcer la sécurité de votre compte.';
						choixEl.style.display = 'block';
						desactiverBtn.style.display = 'none';
					}
				} )
				.catch( function () { statutEl.textContent = ''; } );
		}
		rafraichir2faStatut();

		el( '#grc-2fa-activer-email', bar ).addEventListener( 'click', function () {
			authFetch( grcConfig.restUrl + '/citoyen/2fa/email/activer', { method: 'POST' } )
				.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) { throw new Error( result.data.message || 'Erreur.' ); }
					showMessage( msg2fa, result.data.message, 'success' );
					rafraichir2faStatut();
				} )
				.catch( function ( err ) { showMessage( msg2fa, err.message, 'error' ); } );
		} );

		el( '#grc-2fa-activer-totp', bar ).addEventListener( 'click', function () {
			loadQrcodeThenSetupTotp();
		} );

		function loadQrcodeThenSetupTotp() {
			if ( typeof QRCode !== 'undefined' ) {
				demarrerSetupTotp();
				return;
			}
			var script = document.createElement( 'script' );
			script.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
			script.onload = demarrerSetupTotp;
			document.body.appendChild( script );
		}

		function demarrerSetupTotp() {
			authFetch( grcConfig.restUrl + '/citoyen/2fa/totp/demarrer', { method: 'POST' } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					totpSetupEl.style.display = 'block';
					el( '#grc-2fa-secret-manuel', bar ).textContent = data.secret;
					var qrEl = el( '#grc-2fa-qrcode', bar );
					qrEl.innerHTML = '';
					new QRCode( qrEl, { text: data.uri, width: 180, height: 180 } );
				} )
				.catch( function () { showMessage( msg2fa, 'Impossible de démarrer l\'activation.', 'error' ); } );
		}

		el( '#grc-2fa-totp-confirmer', bar ).addEventListener( 'click', function () {
			var code = el( '#grc-2fa-totp-code', bar ).value;
			authFetch( grcConfig.restUrl + '/citoyen/2fa/totp/confirmer', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { code: code } )
			} )
				.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) { throw new Error( result.data.message || 'Code invalide.' ); }
					showMessage( msg2fa, result.data.message, 'success' );
					rafraichir2faStatut();
				} )
				.catch( function ( err ) { showMessage( msg2fa, err.message, 'error' ); } );
		} );

		desactiverBtn.addEventListener( 'click', function () {
			if ( ! confirm( 'Désactiver la double authentification ? Votre compte sera moins protégé.' ) ) { return; }
			authFetch( grcConfig.restUrl + '/citoyen/2fa/desactiver', { method: 'POST' } )
				.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) { throw new Error( result.data.message || 'Erreur.' ); }
					showMessage( msg2fa, result.data.message, 'success' );
					rafraichir2faStatut();
				} )
				.catch( function ( err ) { showMessage( msg2fa, err.message, 'error' ); } );
		} );
	}

	var grcGeolocMapInstance = null;
	var grcGeolocMarker = null;
	var grcGeocodeTimer = null;

	function loadLeafletThenShowMap( form, lat, lng ) {
		if ( typeof L !== 'undefined' ) {
			showGeolocMap( form, lat, lng );
			return;
		}

		// Charge Leaflet dynamiquement (CSS + JS) uniquement au premier besoin,
		// pour ne pas alourdir le chargement de toutes les pages du site.
		if ( ! document.getElementById( 'grc-leaflet-css' ) ) {
			var link = document.createElement( 'link' );
			link.id = 'grc-leaflet-css';
			link.rel = 'stylesheet';
			link.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css';
			document.head.appendChild( link );
		}
		if ( ! document.getElementById( 'grc-leaflet-js' ) ) {
			var script = document.createElement( 'script' );
			script.id = 'grc-leaflet-js';
			script.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js';
			script.onload = function () {
				// Les images de l'icône par défaut ne se chargent pas automatiquement
				// depuis ce CDN (chemins relatifs cassés) : ceci causait un décalage
				// visuel entre la pointe du repère affichée et les coordonnées réelles.
				L.Icon.Default.mergeOptions( {
					iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon-2x.png',
					iconUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon.png',
					shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
					iconSize: [ 25, 41 ],
					iconAnchor: [ 12, 41 ],
					popupAnchor: [ 1, -34 ],
					shadowSize: [ 41, 41 ]
				} );
				showGeolocMap( form, lat, lng );
			};
			document.body.appendChild( script );
		}
	}

	function showGeolocMap( form, lat, lng ) {
		var mapEl = el( '#grc-geoloc-map', form );
		if ( ! mapEl ) {
			return;
		}
		mapEl.style.display = 'block';

		function updateFields( latlng ) {
			el( '#grc-latitude', form ).value = latlng.lat;
			el( '#grc-longitude', form ).value = latlng.lng;
			var coordsEl = el( '#grc-geoloc-coords', form );
			if ( coordsEl ) {
				coordsEl.style.display = 'block';
				coordsEl.textContent = 'Position retenue : ' + Number( latlng.lat ).toFixed( 6 ) + ', ' + Number( latlng.lng ).toFixed( 6 );
			}

			// Récupération automatique de l'adresse (anti-rebond : évite un appel
			// à chaque pixel lors d'un glisser-déposer, un seul appel après une
			// courte pause d'inactivité sur la position).
			clearTimeout( grcGeocodeTimer );
			grcGeocodeTimer = setTimeout( function () {
				var adresseField = el( '#grc-adresse', form );
				if ( ! adresseField ) {
					return;
				}
				if ( coordsEl ) {
					coordsEl.textContent += ' — recherche de l\'adresse...';
				}
				fetch( grcConfig.restUrl + '/geocode/reverse?lat=' + latlng.lat + '&lng=' + latlng.lng )
					.then( function ( res ) { return res.ok ? res.json() : null; } )
					.then( function ( data ) {
						if ( data && data.adresse ) {
							adresseField.value = data.adresse;
						}
						if ( coordsEl ) {
							coordsEl.textContent = 'Position retenue : ' + Number( latlng.lat ).toFixed( 6 ) + ', ' + Number( latlng.lng ).toFixed( 6 );
						}
					} )
					.catch( function () { /* Adresse non trouvée : le citoyen peut la saisir manuellement. */ } );

				var prochesEl = el( '#grc-signalements-proches', form );
				if ( prochesEl ) {
					fetch( grcConfig.restUrl + '/demandes/proches?lat=' + latlng.lat + '&lng=' + latlng.lng )
						.then( function ( res ) { return res.ok ? res.json() : []; } )
						.then( function ( proches ) {
							if ( ! proches || ! proches.length ) {
								prochesEl.style.display = 'none';
								return;
							}
							var html = '<p><strong>⚠️ ' + proches.length + ' signalement(s) déjà en cours à proximité (moins de 100 m)</strong> — vérifiez qu\'il ne s\'agit pas du même problème avant d\'envoyer :</p><ul>';
							proches.forEach( function ( p ) {
								html += '<li>' + p.titre + ' — ' + p.statut + ', à ' + p.distance_m + ' m (signalé le ' + p.date + ')</li>';
							} );
							html += '</ul><p class="grc-hint">Vous pouvez tout de même envoyer votre signalement si ce n\'est pas le même problème.</p>';
							prochesEl.innerHTML = html;
							prochesEl.style.display = 'block';
						} )
						.catch( function () { prochesEl.style.display = 'none'; } );
				}
			}, 600 );
		}

		if ( grcGeolocMapInstance ) {
			grcGeolocMapInstance.setView( [ lat, lng ], 18 );
			grcGeolocMarker.setLatLng( [ lat, lng ] );
			updateFields( { lat: lat, lng: lng } );
			return;
		}

		grcGeolocMapInstance = L.map( mapEl ).setView( [ lat, lng ], 18 );
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '© OpenStreetMap contributors',
			maxZoom: 19
		} ).addTo( grcGeolocMapInstance );

		grcGeolocMarker = L.marker( [ lat, lng ], {
			draggable: true,
			icon: L.divIcon( {
				className: 'grc-geoloc-pin',
				html: '<div style="width:20px;height:20px;border-radius:50%;background:#b32d2e;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>',
				iconSize: [ 20, 20 ],
				iconAnchor: [ 10, 10 ] // Centre exact de la pastille = coordonnée réelle (pas de décalage possible).
			} )
		} ).addTo( grcGeolocMapInstance );
		grcGeolocMarker.bindPopup( 'Faites glisser ce repère, ou cliquez ailleurs sur la carte, pour ajuster l\'emplacement exact.' ).openPopup();
		grcGeolocMarker.on( 'dragend', function () {
			updateFields( grcGeolocMarker.getLatLng() );
		} );

		updateFields( { lat: lat, lng: lng } ); // Déclenche aussi la recherche d'adresse dès la position initiale.

		// Permet aussi de cliquer n'importe où sur la carte pour déplacer le repère
		// (plus intuitif que le seul glisser-déposer sur une petite carte).
		grcGeolocMapInstance.on( 'click', function ( e ) {
			grcGeolocMarker.setLatLng( e.latlng );
			updateFields( e.latlng );
		} );
	}

	/**
	 * Déconnexion automatique après inactivité (recommandation CNIL), configurable
	 * dans Réglages GRC. Une alerte s'affiche 1 minute avant l'expiration.
	 */
	function initIdleTimeout() {
		if ( ! isCitoyenLoggedIn() ) {
			return;
		}
		var timeoutMs = ( grcConfig.sessionTimeoutMinutes || 30 ) * 60 * 1000;
		var warningMs = Math.max( 0, timeoutMs - 60000 );
		var warningTimer, logoutTimer, warningShown = false;

		function showWarning() {
			warningShown = true;
			if ( window.confirm( 'Votre session va expirer dans 1 minute pour votre sécurité (délai d\'inactivité). Cliquez sur OK pour rester connecté(e).' ) ) {
				resetTimers();
			}
		}

		function doLogout() {
			clearSession();
			alert( 'Votre session a expiré après une période d\'inactivité, pour la protection de vos données personnelles. Veuillez vous reconnecter si besoin.' );
			window.location.reload();
		}

		function resetTimers() {
			warningShown = false;
			clearTimeout( warningTimer );
			clearTimeout( logoutTimer );
			if ( ! isCitoyenLoggedIn() ) {
				return;
			}
			warningTimer = setTimeout( showWarning, warningMs );
			logoutTimer = setTimeout( doLogout, timeoutMs );
		}

		[ 'mousemove', 'keydown', 'click', 'touchstart', 'scroll' ].forEach( function ( evt ) {
			document.addEventListener( evt, function () {
				if ( ! warningShown ) {
					resetTimers();
				}
			}, { passive: true } );
		} );

		resetTimers();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initGlobalCitoyenBar();
		initIdleTimeout();

		// ================= Formulaire de signalement =================
		var form = el( '#grc-signalement-form' );
		if ( form ) {
			var geolocBtn = el( '#grc-geoloc-btn', form );
			var geolocStatus = el( '#grc-geoloc-status', form );

			function triggerGeolocation() {
				if ( ! navigator.geolocation ) {
					geolocStatus.textContent = 'La géolocalisation n\'est pas disponible sur ce navigateur.';
					return;
				}
				geolocStatus.textContent = 'Localisation en cours...';
				navigator.geolocation.getCurrentPosition(
					function ( position ) {
						var lat = position.coords.latitude;
						var lng = position.coords.longitude;
						el( '#grc-latitude', form ).value = lat;
						el( '#grc-longitude', form ).value = lng;
						geolocStatus.textContent = '✅ Position enregistrée — vous pouvez ajuster le repère si besoin.';
						loadLeafletThenShowMap( form, lat, lng );
					},
					function () {
						geolocStatus.textContent = 'Position non détectée automatiquement — vous pouvez réessayer ou saisir l\'adresse manuellement.';
					}
				);
			}

			if ( geolocBtn ) {
				geolocBtn.addEventListener( 'click', triggerGeolocation );
				// Tentative automatique à l'ouverture du formulaire : le navigateur
				// affichera sa propre demande d'autorisation si nécessaire. En cas de
				// refus ou d'indisponibilité, le citoyen garde la main via le bouton.
				triggerGeolocation();
			}
			var banner = el( '#grc-connected-banner' );
			var bannerName = el( '#grc-connected-name' );
			var loginNotice = el( '#grc-login-required-notice' );

			if ( isCitoyenLoggedIn() ) {
				form.style.display = 'block';
				if ( loginNotice ) {
					loginNotice.style.display = 'none';
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
			} else {
				form.style.display = 'none';
				if ( loginNotice ) {
					loginNotice.style.display = 'block';
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

				var latField = el( '#grc-latitude', form );
				var lonField = el( '#grc-longitude', form );
				if ( latField && latField.value ) {
					payload.latitude = latField.value;
					payload.longitude = lonField.value;
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

						if ( photoInput && photoInput.files && photoInput.files.length ) {
							var fd = new FormData();
							Array.prototype.slice.call( photoInput.files ).forEach( function ( file ) {
								fd.append( 'files[]', file );
							} );
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

		// ================= Formulaire de prise de rendez-vous (calendrier) =================
		var rdvForm = el( '#grc-rdv-form' );
		if ( rdvForm ) {
			var rdvBanner = el( '#grc-rdv-connected-banner' );
			var rdvBannerName = el( '#grc-rdv-connected-name' );
			var rdvLoginNotice = el( '#grc-rdv-login-required-notice' );
			var rdvServiceSelect = el( '#grc-rdv-service', rdvForm );
			var rdvDureeField = el( '#grc-rdv-duree-field', rdvForm );
			var rdvDureeToggle = el( '#grc-rdv-duree-toggle', rdvForm );
			var rdvCalendarField = el( '#grc-rdv-calendar-field', rdvForm );
			var rdvCreneauxField = el( '#grc-rdv-creneaux-field', rdvForm );
			var rdvCreneauxContainer = el( '#grc-rdv-creneaux', rdvForm );
			var calendarGrid = el( '#grc-calendar-grid', rdvForm );
			var calMonthLabel = el( '#grc-cal-month-label', rdvForm );
			var rdvSubmitBtn = rdvForm.querySelector( '.grc-btn-submit' );

			var selectedCreneauId = null;
			var selectedDuree = 30;
			var currentMonthDate = new Date();
			currentMonthDate.setDate( 1 );
			var monthCreneauxCache = [];
			var selectedDay = null;

			if ( isCitoyenLoggedIn() ) {
				rdvForm.style.display = 'block';
				if ( rdvLoginNotice ) {
					rdvLoginNotice.style.display = 'none';
				}
				if ( rdvBanner ) {
					rdvBanner.style.display = 'block';
					authFetch( grcConfig.restUrl + '/citoyen/me' )
						.then( function ( res ) { return res.ok ? res.json() : null; } )
						.then( function ( me ) {
							if ( me && rdvBannerName ) {
								rdvBannerName.textContent = ( me.prenom || me.email || 'vous' ) + ( me.nom ? ' ' + me.nom : '' );
							}
						} );
				}
			} else {
				rdvForm.style.display = 'none';
				if ( rdvLoginNotice ) {
					rdvLoginNotice.style.display = 'block';
				}
			}

			function dateKey( d ) {
				return d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
			}

			function loadMonthCreneaux() {
				var serviceId = rdvServiceSelect.value;
				if ( ! serviceId ) {
					return;
				}
				var moisStr = currentMonthDate.getFullYear() + '-' + String( currentMonthDate.getMonth() + 1 ).padStart( 2, '0' );
				calendarGrid.innerHTML = '<p class="grc-hint">Chargement...</p>';

				fetch( grcConfig.restUrl + '/rdv/creneaux?service_id=' + serviceId + '&mois=' + moisStr + '&duree=' + selectedDuree + '&_=' + Date.now() )
					.then( function ( res ) { return res.json(); } )
					.then( function ( creneaux ) {
						monthCreneauxCache = creneaux || [];
						renderCalendar();
					} )
					.catch( function () { calendarGrid.innerHTML = '<p class="grc-hint">Erreur lors du chargement.</p>'; } );
			}

			function renderCalendar() {
				var year = currentMonthDate.getFullYear();
				var month = currentMonthDate.getMonth();
				var monthNames = [ 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre' ];
				calMonthLabel.textContent = monthNames[ month ] + ' ' + year;

				// Regroupe les places restantes par jour.
				var parJour = {};
				monthCreneauxCache.forEach( function ( c ) {
					var d = new Date( c.debut );
					var key = dateKey( d );
					if ( ! parJour[ key ] ) {
						parJour[ key ] = { total: 0, restantes: 0 };
					}
					parJour[ key ].total += c.capacite;
					parJour[ key ].restantes += c.places_restantes;
				} );

				var firstDay = new Date( year, month, 1 );
				var startOffset = ( firstDay.getDay() + 6 ) % 7; // Lundi = 0
				var daysInMonth = new Date( year, month + 1, 0 ).getDate();
				var today = new Date();
				today.setHours( 0, 0, 0, 0 );

				var html = '<div class="grc-calendar-weekdays">';
				[ 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim' ].forEach( function ( d ) { html += '<span aria-hidden="true">' + d + '</span>'; } );
				html += '</div><div class="grc-calendar-days" role="grid" aria-label="Calendrier des jours disponibles pour la prise de rendez-vous">';

				for ( var i = 0; i < startOffset; i++ ) {
					html += '<span class="grc-cal-day grc-cal-day--empty" aria-hidden="true"></span>';
				}

				var moisLabels = [ 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' ];

				for ( var day = 1; day <= daysInMonth; day++ ) {
					var thisDate = new Date( year, month, day );
					var key = dateKey( thisDate );
					var info = parJour[ key ];
					var isPast = thisDate < today;
					var cssClass = 'grc-cal-day';
					var statutTexte = '';

					if ( isPast || ! info ) {
						cssClass += ' grc-cal-day--none';
						statutTexte = isPast ? 'jour passé' : 'aucun créneau';
					} else if ( 0 === info.restantes ) {
						cssClass += ' grc-cal-day--full';
						statutTexte = 'complet';
					} else if ( info.restantes <= 2 || info.restantes <= info.total * 0.2 ) {
						cssClass += ' grc-cal-day--few';
						statutTexte = 'dernières places';
					} else {
						cssClass += ' grc-cal-day--available';
						statutTexte = 'places disponibles';
					}

					if ( selectedDay === key ) {
						cssClass += ' grc-cal-day--selected';
					}

					var clickable = ! isPast && info && info.restantes > 0;
					var libelle = day + ' ' + moisLabels[ month ] + ', ' + statutTexte;

					if ( clickable ) {
						html += '<button type="button" class="' + cssClass + '" data-day="' + key + '" aria-label="' + libelle + '" aria-pressed="' + ( selectedDay === key ) + '">' + day + '</button>';
					} else {
						html += '<span class="' + cssClass + '" aria-label="' + libelle + '">' + day + '</span>';
					}
				}

				html += '</div>';
				calendarGrid.innerHTML = html;

				calendarGrid.querySelectorAll( '[data-day]' ).forEach( function ( el2 ) {
					el2.addEventListener( 'click', function () {
						selectedDay = el2.dataset.day;
						renderCalendar();
						renderCreneauxForDay( selectedDay );
						var focusTarget = calendarGrid.querySelector( '[data-day="' + selectedDay + '"]' );
						if ( focusTarget ) { focusTarget.focus(); }
					} );
				} );
			}

			function renderCreneauxForDay( key ) {
				var creneauxDuJour = monthCreneauxCache.filter( function ( c ) {
					return dateKey( new Date( c.debut ) ) === key && c.places_restantes > 0;
				} );

				rdvCreneauxField.style.display = 'block';
				selectedCreneauId = null;
				rdvSubmitBtn.disabled = true;

				if ( ! creneauxDuJour.length ) {
					rdvCreneauxContainer.innerHTML = '<p class="grc-hint">Aucun créneau disponible ce jour.</p>';
					return;
				}

				var html = '';
				creneauxDuJour.forEach( function ( c ) {
					var d = new Date( c.debut );
					var heure = d.toLocaleTimeString( 'fr-FR', { hour: '2-digit', minute: '2-digit' } );
					html += '<button type="button" class="grc-creneau-btn" data-id="' + c.id + '" aria-pressed="false">' + heure + '</button>';
				} );
				rdvCreneauxContainer.innerHTML = html;

				rdvCreneauxContainer.querySelectorAll( '.grc-creneau-btn' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						rdvCreneauxContainer.querySelectorAll( '.grc-creneau-btn' ).forEach( function ( b ) {
							b.classList.remove( 'grc-creneau-btn--selected' );
							b.setAttribute( 'aria-pressed', 'false' );
						} );
						btn.classList.add( 'grc-creneau-btn--selected' );
						btn.setAttribute( 'aria-pressed', 'true' );
						selectedCreneauId = btn.dataset.id;
						rdvSubmitBtn.disabled = false;
					} );
				} );
			}

			function loadDureesEtCalendrier() {
				var serviceId = rdvServiceSelect.value;
				fetch( grcConfig.restUrl + '/rdv/durees?service_id=' + serviceId + '&_=' + Date.now() )
					.then( function ( res ) { return res.json(); } )
					.then( function ( durees ) {
						if ( ! durees || ! durees.length ) {
							rdvDureeField.style.display = 'none';
							rdvCalendarField.style.display = 'block';
							calendarGrid.innerHTML = '<p class="grc-hint">Aucun horaire n\'est configuré pour ce service actuellement.</p>';
							return;
						}

						if ( durees.length > 1 ) {
							rdvDureeField.style.display = 'block';
							rdvDureeToggle.innerHTML = durees.map( function ( d, i ) {
								var label = d >= 60 ? ( d / 60 ) + ' h' + ( d % 60 ? ( d % 60 ) : '' ) : d + ' min';
								return '<button type="button" class="grc-duree-btn' + ( 0 === i ? ' grc-duree-btn--active' : '' ) + '" data-duree="' + d + '" aria-pressed="' + ( 0 === i ) + '">' + label + '</button>';
							} ).join( '' );

							rdvDureeToggle.querySelectorAll( '.grc-duree-btn' ).forEach( function ( btn ) {
								btn.addEventListener( 'click', function () {
									rdvDureeToggle.querySelectorAll( '.grc-duree-btn' ).forEach( function ( b ) {
										b.classList.remove( 'grc-duree-btn--active' );
										b.setAttribute( 'aria-pressed', 'false' );
									} );
									btn.classList.add( 'grc-duree-btn--active' );
									btn.setAttribute( 'aria-pressed', 'true' );
									selectedDuree = parseInt( btn.dataset.duree, 10 );
									selectedDay = null;
									rdvCreneauxField.style.display = 'none';
									loadMonthCreneaux();
								} );
							} );
						} else {
							rdvDureeField.style.display = 'none';
						}

						selectedDuree = durees[ 0 ];
						rdvCalendarField.style.display = 'block';
						currentMonthDate = new Date();
						currentMonthDate.setDate( 1 );
						loadMonthCreneaux();
					} )
					.catch( function () {
						rdvCalendarField.style.display = 'block';
						calendarGrid.innerHTML = '<p class="grc-hint">Erreur lors du chargement des disponibilités.</p>';
					} );
			}

			rdvServiceSelect.addEventListener( 'change', function () {
				selectedDay = null;
				rdvCreneauxField.style.display = 'none';
				if ( rdvServiceSelect.value ) {
					loadDureesEtCalendrier();
				} else {
					rdvDureeField.style.display = 'none';
					rdvCalendarField.style.display = 'none';
				}
			} );

			el( '#grc-cal-prev', rdvForm ).addEventListener( 'click', function () {
				currentMonthDate.setMonth( currentMonthDate.getMonth() - 1 );
				selectedDay = null;
				rdvCreneauxField.style.display = 'none';
				loadMonthCreneaux();
			} );
			el( '#grc-cal-next', rdvForm ).addEventListener( 'click', function () {
				currentMonthDate.setMonth( currentMonthDate.getMonth() + 1 );
				selectedDay = null;
				rdvCreneauxField.style.display = 'none';
				loadMonthCreneaux();
			} );

			rdvForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				if ( ! selectedCreneauId ) {
					return;
				}
				var msgBox = el( '.grc-form-message', rdvForm );
				rdvSubmitBtn.disabled = true;
				rdvSubmitBtn.textContent = 'Envoi en cours...';

				var payload = {
					creneau_id: selectedCreneauId,
					motif: el( '#grc-rdv-motif', rdvForm ).value
				};

				authFetch( grcConfig.restUrl + '/rdv', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload )
				} )
					.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Erreur lors de la prise de rendez-vous.' );
						}
						showMessage( msgBox, 'Votre demande de rendez-vous a bien été enregistrée et est en attente de validation. Vous recevrez un email dès qu\'elle sera traitée.', 'success' );
						rdvForm.reset();
						selectedCreneauId = null;
						selectedDay = null;
						rdvCreneauxField.style.display = 'none';
						loadMonthCreneaux();
					} )
					.catch( function ( err ) {
						showMessage( msgBox, err.message, 'error' );
					} )
					.finally( function () {
						rdvSubmitBtn.disabled = ! selectedCreneauId;
						rdvSubmitBtn.textContent = 'Confirmer le rendez-vous';
					} );
			} );
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
		var rdvListe = el( '#grc-rdv-liste', wrapper );

		function demarcheStatutLabel( statut ) {
			var labels = {
				en_attente: 'En attente', en_cours: 'En cours', valide: 'Validé',
				rejete: 'Rejeté', complement_requis: 'Complément requis'
			};
			return labels[ statut ] || statut;
		}

		function renderDemarchesList( container, demarches, vue ) {
			if ( ! demarches || ! demarches.length ) {
				container.innerHTML = '<p>Aucune démarche trouvée.</p>';
				return;
			}

			if ( 'list' === vue ) {
				var rows = '<table class="grc-liste-table"><thead><tr><th>N° dossier</th><th>Type</th><th>Statut</th><th>Date</th><th></th></tr></thead><tbody>';
				demarches.forEach( function ( d ) {
					var needsAction = 'rejete' === d.statut || 'complement_requis' === d.statut;
					rows += '<tr>';
					rows += '<td><code>' + ( d.numero_dossier || ( '#' + d.id ) ) + '</code></td>';
					rows += '<td>' + ( d.type_nom || d.type_demarche ) + '</td>';
					rows += '<td><span class="grc-badge grc-badge--' + d.statut + '">' + demarcheStatutLabel( d.statut ) + '</span></td>';
					rows += '<td>' + new Date( d.created_at ).toLocaleDateString( 'fr-FR' ) + '</td>';
					rows += '<td><button type="button" class="grc-btn-link grc-demarche-toggle-thread" data-demarche-id="' + d.id + '">' + ( needsAction ? 'Répondre' : 'Échange' ) + '</button>';
					rows += '<div class="grc-demarche-thread" data-demarche-id="' + d.id + '" style="display:none;"></div></td>';
					rows += '</tr>';
				} );
				rows += '</tbody></table>';
				container.innerHTML = rows;
				attachDemarcheThreadHandlers( container );
				return;
			}

			var html = '<div class="grc-demandes-cards">';
			demarches.forEach( function ( d ) {
				var needsAction = 'rejete' === d.statut || 'complement_requis' === d.statut;
				html += '<div class="grc-demande-card">';
				html += '<div class="grc-demande-card-header">';
				html += '<code>' + ( d.numero_dossier || ( '#' + d.id ) ) + '</code>';
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

		function renderMessageAttachments( pieces ) {
			if ( ! pieces || ! pieces.length ) {
				return '';
			}
			var token = getAccessToken();
			var html = '<div class="grc-message-attachments">';
			pieces.forEach( function ( p ) {
				var url = p.download_url + ( token ? ( p.download_url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'token=' + encodeURIComponent( token ) : '' );
				html += '<a href="' + url + '" target="_blank" class="grc-attachment-chip">📄 ' + p.nom_original + '</a>';
			} );
			html += '</div>';
			return html;
		}

		function loadDemarcheThread( id, threadEl ) {
			threadEl.innerHTML = '<p>Chargement...</p>';
			authFetch( grcConfig.restUrl + '/demarches/' + id + '?_=' + Date.now() )
				.then( function ( res ) { return res.ok ? res.json() : Promise.reject(); } )
				.then( function ( dossier ) {
					var html = '';

					if ( dossier.pieces_jointes && dossier.pieces_jointes.length ) {
						html += '<div class="grc-hint">Documents du dossier :</div>';
						html += renderMessageAttachments( dossier.pieces_jointes );
					}

					( dossier.messages || [] ).forEach( function ( m ) {
						html += '<div class="grc-thread-message grc-thread-message--' + m.auteur_type + '">';
						html += '<strong>' + ( 'agent' === m.auteur_type ? 'Mairie' : 'Vous' ) + '</strong>';
						html += '<span class="grc-demande-date"> — ' + new Date( m.created_at ).toLocaleDateString( 'fr-FR' ) + '</span>';
						html += '<p>' + m.contenu + '</p>';
						html += renderMessageAttachments( m.pieces_jointes );
						html += '</div>';
					} );
					if ( ! dossier.messages || ! dossier.messages.length ) {
						html += '<p class="grc-hint">Aucun message pour le moment.</p>';
					}
					html += '<textarea class="grc-thread-reply" rows="2" placeholder="Votre réponse..."></textarea>';
					html += '<input type="file" class="grc-thread-reply-files" multiple accept=".pdf,.docx,.jpg,.jpeg,.png,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png">';
					html += '<p class="grc-hint">Vous pouvez joindre un ou plusieurs documents (PDF/.docx) à votre réponse.</p>';
					html += '<div class="grc-form-message grc-thread-error" style="display:none;"></div>';
					html += '<button type="button" class="grc-btn-submit grc-thread-send" data-demarche-id="' + id + '">Envoyer</button>';
					threadEl.innerHTML = html;

					threadEl.querySelector( '.grc-thread-send' ).addEventListener( 'click', function () {
						var textarea = threadEl.querySelector( '.grc-thread-reply' );
						var fileInput = threadEl.querySelector( '.grc-thread-reply-files' );
						var errorBox = threadEl.querySelector( '.grc-thread-error' );
						var contenu = textarea.value.trim();
						var files = fileInput.files ? Array.prototype.slice.call( fileInput.files ) : [];

						if ( ! contenu && ! files.length ) {
							return;
						}

						var fd = new FormData();
						fd.append( 'contenu', contenu );
						files.forEach( function ( f ) { fd.append( 'files[]', f ); } );

						authFetch( grcConfig.restUrl + '/demarches/' + id + '/messages', {
							method: 'POST',
							body: fd
						} )
							.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
							.then( function ( result ) {
								if ( ! result.ok ) {
									throw new Error( result.data.message || 'Erreur lors de l\'envoi du message.' );
								}
								loadDemarcheThread( id, threadEl );
							} )
							.catch( function ( err ) {
								showMessage( errorBox, err.message, 'error' );
							} );
					} );
				} )
				.catch( function () { threadEl.innerHTML = '<p>Erreur lors du chargement de l\'échange.</p>'; } );
		}

		function rdvStatutLabel( statut ) {
			var labels = {
				en_attente: 'En attente de validation',
				confirme: 'Confirmé',
				refuse: 'Refusé',
				annule: 'Annulé'
			};
			return labels[ statut ] || statut;
		}

		function renderRdvList( container, rdvList, vue ) {
			if ( ! rdvList || ! rdvList.length ) {
				container.innerHTML = '<p>Aucun rendez-vous trouvé.</p>';
				return;
			}
			var badgeClasses = { en_attente: 'assigne', confirme: 'resolu', refuse: 'rejete', annule: 'cloture' };

			if ( 'list' === vue ) {
				var rows = '<table class="grc-liste-table"><thead><tr><th>Service</th><th>Date</th><th>Motif</th><th>Statut</th><th></th></tr></thead><tbody>';
				rdvList.forEach( function ( r ) {
					var isPast = r.debut && new Date( r.debut ) < new Date();
					rows += '<tr>';
					rows += '<td>' + ( r.service_nom || '' ) + ( r.numero_rdv ? ' <code>' + r.numero_rdv + '</code>' : '' ) + '</td>';
					rows += '<td>' + ( r.debut ? new Date( r.debut ).toLocaleString( 'fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } ) : '—' ) + '</td>';
					rows += '<td>' + ( r.motif || '—' ) + '</td>';
					rows += '<td><span class="grc-badge grc-badge--' + ( badgeClasses[ r.statut ] || 'cloture' ) + '">' + rdvStatutLabel( r.statut ) + '</span></td>';
					rows += '<td>' + ( ( ( 'confirme' === r.statut || 'en_attente' === r.statut ) && ! isPast ) ? '<button type="button" class="grc-btn-link grc-rdv-cancel" data-id="' + r.id + '">Annuler</button>' : '' ) + '</td>';
					rows += '</tr>';
				} );
				rows += '</tbody></table>';
				container.innerHTML = rows;
				attachRdvCancelHandlers( container );
				return;
			}

			var html = '<div class="grc-demandes-cards">';
			rdvList.forEach( function ( r ) {
				var isPast = r.debut && new Date( r.debut ) < new Date();
				html += '<div class="grc-demande-card">';
				html += '<div class="grc-demande-card-header">';
				html += '<strong>' + ( r.service_nom || '' ) + '</strong>';
				html += '<span class="grc-badge grc-badge--' + ( badgeClasses[ r.statut ] || 'cloture' ) + '">' + rdvStatutLabel( r.statut ) + '</span>';
				html += '</div>';
				if ( r.numero_rdv ) {
					html += '<p class="grc-demande-date"><code>' + r.numero_rdv + '</code></p>';
				}
				if ( r.debut ) {
					html += '<p class="grc-demande-date">' + new Date( r.debut ).toLocaleString( 'fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' } ) + '</p>';
				}
				if ( r.motif ) {
					html += '<p>' + r.motif + '</p>';
				}
				if ( ( 'confirme' === r.statut || 'en_attente' === r.statut ) && ! isPast ) {
					html += '<button type="button" class="grc-btn-link grc-rdv-cancel" data-id="' + r.id + '">Annuler ce rendez-vous</button>';
				}
				html += '</div>';
			} );
			html += '</div>';
			container.innerHTML = html;
			attachRdvCancelHandlers( container );
		}

		function attachRdvCancelHandlers( container ) {
			container.querySelectorAll( '.grc-rdv-cancel' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'Annuler ce rendez-vous ?' ) ) {
						return;
					}
					authFetch( grcConfig.restUrl + '/rdv/' + btn.dataset.id + '/annuler', { method: 'POST' } )
						.then( function ( res ) { return res.ok ? res.json() : Promise.reject(); } )
						.then( function () { loadMesDemandes(); } )
						.catch( function () { window.alert( 'Erreur lors de l\'annulation.' ); } );
				} );
			} );
		}

		var grcListState = {
			demandes: { data: [] },
			demarches: { data: [] },
			rdv: { data: [] }
		};
		var grcVueMode = localStorage.getItem( 'grc_vue_mode' ) || 'cards';

		function grcFilterAndRender( type ) {
			var select = wrapper.querySelector( '.grc-statut-filter[data-target="' + type + '"]' );
			var statut = select ? select.value : '';
			var data = grcListState[ type ].data;
			var filtered = statut ? data.filter( function ( item ) { return item.statut === statut; } ) : data;

			if ( 'demandes' === type ) {
				renderDemandesList( demandesListe, filtered, grcVueMode );
			} else if ( 'demarches' === type ) {
				renderDemarchesList( demarchesListe, filtered, grcVueMode );
			} else if ( 'rdv' === type && rdvListe ) {
				renderRdvList( rdvListe, filtered, grcVueMode );
			}
		}

		function grcUpdateVueToggleButtons() {
			wrapper.querySelectorAll( '.grc-vue-toggle' ).forEach( function ( btn ) {
				btn.textContent = 'list' === grcVueMode ? '🔲' : '☰';
				var label = 'list' === grcVueMode ? 'Afficher en cartes' : 'Afficher en liste';
				btn.title = label;
				btn.setAttribute( 'aria-label', label );
			} );
		}

		wrapper.querySelectorAll( '.grc-vue-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				grcVueMode = 'list' === grcVueMode ? 'cards' : 'list';
				localStorage.setItem( 'grc_vue_mode', grcVueMode );
				grcUpdateVueToggleButtons();
				[ 'demandes', 'demarches', 'rdv' ].forEach( grcFilterAndRender );
			} );
		} );
		grcUpdateVueToggleButtons();

		wrapper.querySelectorAll( '.grc-statut-filter' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				grcFilterAndRender( select.dataset.target );
			} );
		} );

		function loadMesDemandes() {
			demandesListe.innerHTML = '<p>Chargement de vos demandes...</p>';
			authFetch( grcConfig.restUrl + '/mes-demandes' )
				.then( function ( res ) { return res.ok ? res.json() : []; } )
				.then( function ( demandes ) { grcListState.demandes.data = demandes || []; grcFilterAndRender( 'demandes' ); } )
				.catch( function () { demandesListe.innerHTML = '<p>Erreur lors du chargement.</p>'; } );

			demarchesListe.innerHTML = '<p>Chargement de vos démarches...</p>';
			authFetch( grcConfig.restUrl + '/mes-demarches' )
				.then( function ( res ) { return res.ok ? res.json() : []; } )
				.then( function ( demarches ) { grcListState.demarches.data = demarches || []; grcFilterAndRender( 'demarches' ); } )
				.catch( function () { demarchesListe.innerHTML = '<p>Erreur lors du chargement.</p>'; } );

			if ( rdvListe ) {
				rdvListe.innerHTML = '<p>Chargement de vos rendez-vous...</p>';
				authFetch( grcConfig.restUrl + '/mes-rdv' )
					.then( function ( res ) { return res.ok ? res.json() : []; } )
					.then( function ( rdvList ) { grcListState.rdv.data = rdvList || []; grcFilterAndRender( 'rdv' ); } )
					.catch( function () { rdvListe.innerHTML = '<p>Erreur lors du chargement.</p>'; } );
			}
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
				tabs.forEach( function ( t ) {
					t.classList.remove( 'grc-auth-tab--active' );
					t.setAttribute( 'aria-selected', 'false' );
				} );
				tab.classList.add( 'grc-auth-tab--active' );
				tab.setAttribute( 'aria-selected', 'true' );
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
			var pendingToken2fa = null;

			loginForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', loginForm );

				if ( pendingToken2fa ) {
					// Second temps : le mot de passe a déjà été validé, on
					// envoie maintenant le code de vérification.
					fetch( grcConfig.restUrl + '/citoyen/2fa/verifier', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( {
							pending_token: pendingToken2fa,
							code: el( '#grc-login-2fa-code', loginForm ).value
						} )
					} )
						.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
						.then( function ( result ) {
							if ( ! result.ok ) {
								throw new Error( result.data.message || 'Code invalide.' );
							}
							storeSession( result.data );
							showConnecteView();
						} )
						.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
					return;
				}

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
						if ( result.data.requires_2fa ) {
							pendingToken2fa = result.data.pending_token;
							el( '#grc-login-email', loginForm ).disabled = true;
							el( '#grc-login-password', loginForm ).disabled = true;
							var champ2fa = el( '#grc-login-2fa-field', loginForm );
							champ2fa.style.display = 'block';
							var hint = el( '#grc-login-2fa-hint', loginForm );
							hint.textContent = 'email' === result.data.method
								? 'Un code vous a été envoyé par email.'
								: 'Saisissez le code affiché par votre application d\'authentification.';
							showMessage( msgBox, 'Vérification supplémentaire requise.', 'success' );
							return;
						}
						storeSession( result.data );
						showConnecteView();
					} )
					.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
			} );
		}

		// ---- Mot de passe oublié ----
		var mdpOublieBtn = el( '#grc-mdp-oublie-lien' );
		var mdpOublieForm = el( '#grc-mdp-oublie-form' );
		var retourLoginBtn = el( '#grc-retour-login-lien' );
		if ( mdpOublieBtn && mdpOublieForm && loginForm ) {
			mdpOublieBtn.addEventListener( 'click', function () {
				loginForm.style.display = 'none';
				mdpOublieForm.style.display = 'block';
			} );
			if ( retourLoginBtn ) {
				retourLoginBtn.addEventListener( 'click', function () {
					mdpOublieForm.style.display = 'none';
					loginForm.style.display = 'block';
				} );
			}
			mdpOublieForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', mdpOublieForm );
				fetch( grcConfig.restUrl + '/citoyen/mot-de-passe-oublie', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { email: el( '#grc-mdp-oublie-email' ).value } )
				} )
					.then( function ( res ) { return res.json(); } )
					.then( function ( data ) { showMessage( msgBox, data.message, 'success' ); } )
					.catch( function () { showMessage( msgBox, 'Erreur lors de l\'envoi.', 'error' ); } );
			} );
		}

		// ---- Réinitialisation du mot de passe (arrivée via lien email) ----
		var resetForm = el( '#grc-reset-mdp-form' );
		var resetTokenUrl = new URLSearchParams( window.location.search ).get( 'reset_token' );
		if ( resetForm && resetTokenUrl ) {
			var authFormsWrapper = el( '#grc-auth-forms' );
			if ( authFormsWrapper ) {
				authFormsWrapper.querySelectorAll( '.grc-auth-panel' ).forEach( function ( p ) { p.style.display = 'none'; } );
				var tabsWrapper = el( '.grc-auth-tabs' );
				if ( tabsWrapper ) { tabsWrapper.style.display = 'none'; }
			}
			resetForm.style.display = 'block';
			resetForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', resetForm );
				fetch( grcConfig.restUrl + '/citoyen/reinitialiser-mot-de-passe', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { token: resetTokenUrl, mot_de_passe: el( '#grc-reset-mdp-nouveau' ).value } )
				} )
					.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Erreur.' );
						}
						showMessage( msgBox, result.data.message + ' Redirection...', 'success' );
						setTimeout( function () { window.location.href = window.location.pathname; }, 2000 );
					} )
					.catch( function ( err ) { showMessage( msgBox, err.message, 'error' ); } );
			} );
		}

		// ---- Inscription ----
		var registerForm = panels.register;
		if ( registerForm ) {
			var provider = grcConfig.captchaProvider || 'interne';
			var usingProvider = 'interne' !== provider;
			var captchaQuestionEl, captchaTokenEl, captchaInputEl;

			if ( ! usingProvider ) {
				captchaQuestionEl = el( '#grc-captcha-question', registerForm );
				captchaTokenEl = el( '#grc-captcha-token', registerForm );
				captchaInputEl = el( '#grc-reg-captcha', registerForm );

				var loadCaptcha = function () {
					captchaQuestionEl.textContent = 'Chargement...';
					captchaInputEl.value = '';
					fetch( grcConfig.restUrl + '/captcha' )
						.then( function ( res ) { return res.json(); } )
						.then( function ( data ) {
							captchaQuestionEl.textContent = data.question;
							captchaTokenEl.value = data.token;
						} )
						.catch( function () { captchaQuestionEl.textContent = 'Erreur de chargement de la vérification anti-robot.'; } );
				};
				loadCaptcha();
			}

			function resetProviderWidget() {
				if ( 'turnstile' === provider && window.turnstile ) { window.turnstile.reset(); }
				else if ( 'recaptcha' === provider && window.grecaptcha ) { window.grecaptcha.reset(); }
				else if ( 'hcaptcha' === provider && window.hcaptcha ) { window.hcaptcha.reset(); }
			}

			registerForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msgBox = el( '.grc-form-message', registerForm );

				var payload = {
					prenom: el( '#grc-reg-prenom', registerForm ).value,
					nom: el( '#grc-reg-nom', registerForm ).value,
					email: el( '#grc-reg-email', registerForm ).value,
					password: el( '#grc-reg-password', registerForm ).value,
					site_web: el( '#grc-reg-site-web', registerForm ).value
				};

				if ( usingProvider ) {
					var responseField = registerForm.querySelector( '[name="' + grcConfig.captchaResponseField + '"]' );
					payload.captcha_provider_token = responseField ? responseField.value : '';
				} else {
					payload.captcha_token = captchaTokenEl.value;
					payload.captcha_reponse = captchaInputEl.value;
				}

				fetch( grcConfig.restUrl + '/citoyen/register', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload )
				} )
					.then( function ( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); } )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( result.data.message || 'Erreur lors de l\'inscription.' );
						}
						storeSession( result.data );
						showConnecteView();
					} )
					.catch( function ( err ) {
						showMessage( msgBox, err.message, 'error' );
						if ( ! usingProvider ) {
							loadCaptcha(); // Un nouveau défi est requis après tout échec (le précédent a été consommé).
						} else {
							resetProviderWidget();
						}
					} );
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
