# Changelog

## 0.3.1 — Correctif détection des clés mal configurées

- Correction d'un piège de détection : si `GRC_ENCRYPTION_KEY`/`GRC_JWT_SECRET` sont définies dans `wp-config.php` **après** `require_once wp-settings.php`, le plugin échouait silencieusement à se charger (shortcodes, menu admin, API absents) sans jamais afficher d'alerte. La vérification se faisait sur le hook `admin_init`, qui s'exécute après que `wp-config.php` ait fini de tourner — donc les constantes y apparaissaient définies, masquant le vrai problème survenu plus tôt sur `plugins_loaded`.
- Le statut réel d'initialisation est désormais enregistré au moment exact où il se produit (`plugins_loaded`), avec un message d'erreur spécifique si les clés sont définies mais dans le mauvais ordre.

## 0.3.0 — Pièces jointes + portail citoyen front-office

### Pièces jointes
- Endpoint REST d'upload (`POST /demandes/{id}/pieces-jointes`) : validation MIME réelle (finfo, pas juste l'extension), taille max 8 Mo, noms de fichiers randomisés
- Stockage protégé (`wp-content/uploads/grc-attachments/`) avec `.htaccess` interdisant l'accès direct — les fichiers ne sont servables que via l'endpoint authentifié
- Endpoint de téléchargement (`GET /pieces-jointes/{id}`) : autorisation vérifiée (agent, citoyen propriétaire connecté, ou invité via email correspondant)
- Affichage des pièces jointes dans la vue détail admin (miniatures/icônes selon le type)
- Liste des pièces jointes incluse dans les réponses API `/demandes/{id}`, `/mes-demandes`, `/demandes/guest-lookup`

### Portail citoyen front-office
- Shortcode `[grc_signalement_form]` : formulaire public (compte connecté ou invité), upload photo intégré, catégories dynamiques
- Shortcode `[grc_mes_demandes]` : suivi des demandes (liste automatique si connecté, recherche par numéro + email en mode invité)
- CSS aux couleurs municipales (#2D6AB0 / #587526 / #DEA128)
- Le middleware d'authentification REST accepte désormais aussi bien le JWT (app mobile) que l'authentification cookie+nonce native de WordPress (front-office web)

## 0.2.0 — Interface admin des demandes

- Liste des demandes avec filtres (numéro de suivi, statut, service, catégorie) et pagination
- Restriction automatique par service pour les agents (un agent ne voit que les demandes de son service, sauf rôle Élu/Admin avec `grc_view_all`)
- Vue détail : informations citoyen (déchiffrées à l'affichage uniquement), description, fil d'échanges (messages + notes internes)
- Changement de statut avec notification email automatique au citoyen si un email est disponible
- Assignation d'un agent/service (visible seulement avec la capacité `grc_assign_demandes`)
- Ajout de messages/notes internes (non visibles du citoyen si cochées "note interne")
- Audit log sur la consultation, l'assignation, le changement de statut et l'ajout de message
- Toutes les actions protégées par nonce + vérification de capacité (`check_admin_referer`, `current_user_can`)

## 0.1.1 — Filet de sécurité création des tables

- Les tables sont désormais (re)créées/mises à jour automatiquement à chaque chargement du plugin si la version de schéma (`grc_db_version`) diffère de la version du plugin — pas seulement à l'activation manuelle. Couvre le cas d'une mise à jour automatique via GitHub Releases, qui ne déclenche pas `register_activation_hook`.
- `dbDelta()` étant idempotent, aucune donnée existante n'est perdue lors de ce contrôle.

## 0.1.0 — Fondations (en cours)

### Implémenté
- Structure complète des tables (citoyens, demandes, services, catégories, agents, messages, pièces jointes, rdv, créneaux, démarches, satisfaction, audit log, api tokens)
- Chiffrement applicatif (libsodium XChaCha20-Poly1305) des données personnelles + hash de recherche HMAC
- Authentification JWT custom (access token + refresh token) pour l'API REST
- API REST : auth (login/refresh/logout), demandes (création publique compte/invité, suivi invité, CRUD agent), rendez-vous (créneaux, réservation)
- Rôles WordPress custom : Agent GRC, Responsable de service GRC, Élu GRC
- Rate limiting sur les endpoints publics
- Audit log RGPD sur les actions sensibles
- Cron quotidien : détection dépassement SLA, purge/anonymisation RGPD automatique
- Notifications email (création de demande, changement de statut)
- Auto-update via GitHub Releases (Plugin Update Checker v5.7)
- Menu d'administration (squelette : dashboard avec stats de base, sous-menus)

### À implémenter (prochaines versions)
- Interface admin complète : liste des demandes avec filtres/tri, assignation manuelle, drag & drop
- CRUD services/catégories en interface admin (actuellement seedé par défaut uniquement)
- Interface admin rendez-vous (gestion des créneaux)
- Statistiques avancées (graphiques, cartographie thermique, export CSV/Excel)
- Module Démarches administratives (formulaires dynamiques par type)
- Module Satisfaction (enquête post-résolution, endpoint REST manquant)
- Portail citoyen front-office (shortcode "Mes demandes", suivi invité en front)
- Upload de pièces jointes (photos signalement) — endpoint REST manquant
- SMS (passerelle à définir, ex. Twilio/OVH)
- Géolocalisation cartographique (Leaflet/OSM) en front et en admin
- Application mobile Android/iOS consommant l'API REST
- Tests unitaires PHPUnit sur les classes de chiffrement et JWT
