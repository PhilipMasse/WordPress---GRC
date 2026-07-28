# Changelog

## 0.10.0 — Documents multiples + pièces jointes dans les échanges

- Les champs de type `"file"` d'une démarche acceptent désormais **plusieurs fichiers** à la fois (attribut `multiple`), chacun validé et scanné individuellement — un fichier refusé n'empêche pas les autres d'être acceptés
- Nouvelle colonne `demarche_message_id` sur `wp_grc_pieces_jointes` : les pièces jointes peuvent maintenant être liées à un **message précis** du fil d'échange, pas seulement au dossier global
- Le citoyen peut désormais **joindre un ou plusieurs documents à sa réponse** dans le fil d'échange (en plus du texte)
- L'agent peut également joindre des documents à ses messages depuis l'administration (formulaire enrichi avec upload multiple)
- Les pièces jointes de chaque message s'affichent sous forme de puces cliquables, aussi bien côté citoyen que côté admin
- Toujours les mêmes contrôles de sécurité (`GRC_File_Scanner`) appliqués à chaque fichier individuellement : signature binaire, détection de macros VBA (.docx), détection de JavaScript embarqué (PDF), ClamAV si disponible

## 0.9.0 — Correctif cache fil d'échange + upload de documents sécurisé

### Correctif fil d'échange
- Les réponses de l'API GRC (`/wp-json/grc/v1/*`) n'étaient potentiellement pas rafraîchies à cause d'un cache (serveur ou plugin) mettant en cache les requêtes GET du fil de messages, donnant l'impression que les échanges n'apparaissaient jamais. Ajout d'en-têtes `no-cache` sur toutes les réponses de l'API GRC, et paramètre anti-cache côté client sur le chargement du fil.
- Ajout d'une gestion d'erreur visible lors de l'envoi d'un message (au lieu d'un échec silencieux).

### Upload de documents pour les démarches
- Nouveau type de champ `"type":"file"` dans la définition JSON d'un type de démarche : génère un input fichier dans le formulaire citoyen
- Formats acceptés : **PDF** et **Word (.docx)** — le format `.doc` legacy (OLE binaire) est volontairement exclu, sa structure propriétaire rendant peu fiable la détection de contenu malveillant comparé aux formats modernes
- **Contrôles de sécurité avant stockage** (`GRC_File_Scanner`) :
  - Vérification de la signature binaire réelle du fichier (pas seulement l'extension ou le type déclaré par le navigateur)
  - Pour les `.docx` : validation de la structure interne (fichier ZIP valide, parties XML attendues présentes) et **rejet si une macro VBA est détectée** (`word/vbaProject.bin`), le vecteur d'infection le plus courant des documents Office
  - Pour les PDF : **rejet si du JavaScript embarqué ou des actions d'ouverture/lancement automatique** sont détectés (vecteurs classiques d'exploitation PDF)
  - Analyse ClamAV automatique si le binaire `clamscan` est disponible sur le serveur (sinon, seules les vérifications heuristiques ci-dessus s'appliquent — voir la note importante ci-dessous)
- Les documents sont stockés dans le même répertoire protégé que les photos de signalement (non accessible par URL directe), et téléchargeables uniquement via l'API avec vérification d'autorisation (agent ou citoyen propriétaire du dossier)

**Note de sécurité importante** : sans moteur antivirus dédié installé sur le serveur de production, ces contrôles ne garantissent pas qu'un fichier soit totalement exempt de code malveillant — ils bloquent les vecteurs d'attaque les plus courants et les fichiers structurellement incohérents. Pour une garantie plus forte, il est recommandé d'installer ClamAV sur le serveur (le plugin l'utilisera automatiquement s'il est détecté).

## 0.8.0 — Barre citoyenne globale + démarches dans "Mes demandes" + échanges

### Barre citoyenne globale
- "Mon profil" et "Se déconnecter" apparaissent désormais sur **toutes les pages du site** via une barre injectée en haut de page (plus besoin d'être sur la page `[grc_mes_demandes]`), avec panneau profil/mot de passe intégré

### Démarches visibles côté citoyen
- `[grc_mes_demandes]` affiche maintenant une seconde liste "Mes démarches" en plus des signalements

### Vrai fil d'échange sur les démarches
- Nouvelle table `wp_grc_demarche_messages` : fil de discussion dédié à chaque dossier de démarche
- Endpoints REST : `GET /demarches/{id}` (détail + messages), `POST /demarches/{id}/messages` (agent ou citoyen propriétaire)
- Le changement de statut en admin accepte un **commentaire à communiquer au citoyen**, automatiquement ajouté au fil d'échange (particulièrement utile pour "Rejeté" et "Complément requis")
- Côté citoyen, chaque démarche a un bouton "Voir l'échange" (ou "Voir le message et répondre" si le statut nécessite une action) : le citoyen peut lire les messages de la mairie et répondre directement
- Côté admin, la vue détail d'un dossier affiche l'intégralité du fil et permet d'envoyer un message indépendamment d'un changement de statut

## 0.7.0 — Correctif expiration de token + gestion du profil citoyen

### Correctif important
- Le token d'accès citoyen (JWT) expire après 1h. Sur les routes "publiques" (ex: `/demarches`, `/demandes/public-submit`), un token expiré ne renvoyait pas d'erreur 401 et la requête retombait silencieusement en mode invité — donnant l'impression trompeuse que le citoyen n'était plus reconnu (ex: email redemandé alors que le compte existait). Le token est désormais vérifié et rafraîchi de façon proactive avant chaque requête authentifiée.
- Correction défensive : les champs invité masqués (email) restaient marqués "obligatoire" au niveau HTML.

### Gestion du profil citoyen
- Nouveaux endpoints `PUT /citoyen/me` (mise à jour nom/prénom/email/téléphone, avec vérification d'unicité de l'email) et `POST /citoyen/password` (changement de mot de passe avec vérification de l'ancien)
- Section "Mon profil" ajoutée dans `[grc_mes_demandes]` : formulaire d'informations personnelles + formulaire de changement de mot de passe

### Rappel
- Les dossiers de démarches soumis sont visibles dans **GRC Citoyenne → Démarches → Dossiers soumis** (déjà disponible depuis la v0.6.0) ; ils n'apparaissaient pas car les soumissions échouaient à cause du bug d'expiration de token ci-dessus.

## 0.6.1 — Shortcode formulaire de démarche dynamique

- Nouveau shortcode `[grc_demarche_form]` : génère automatiquement le formulaire à partir des champs JSON définis pour chaque type de démarche (texte, zone de texte, email, nombre ; obligatoire ou non)
- Sans attribut, affiche un sélecteur de type puis le formulaire correspondant ; avec `[grc_demarche_form type="mon-slug"]`, affiche directement le formulaire de ce type (utile pour dédier une page par démarche)
- Masque automatiquement les champs invité et affiche la bannière "Connecté en tant que" si un citoyen est authentifié, comme pour le formulaire de signalement

## 0.6.0 — Démarches administratives + Satisfaction citoyenne

### Démarches administratives
- Nouvelle table `wp_grc_demarche_types` : chaque type de démarche définit ses propres champs (JSON simple : clé, label, type, requis)
- Endpoints REST : `GET /demarches/types`, `POST /demarches` (soumission validée contre la définition du type, compte citoyen ou invité), `GET /mes-demarches`, `POST /demarches/{id}/statut`
- Interface admin **Démarches** : gestion des types (CRUD avec champs JSON), liste des dossiers soumis, vue détail par dossier avec changement de statut (en attente / en cours / validé / rejeté / complément requis)

### Satisfaction citoyenne
- Endpoint `POST /demandes/{id}/satisfaction` : notation 1-5 + commentaire, autorisée uniquement sur une demande résolue/clôturée, une seule fois, par le citoyen propriétaire (JWT) ou en invité (email correspondant)
- Endpoint `GET /satisfaction/stats` (agents/élus) : moyenne globale + répartition des notes
- Le front-office (`[grc_mes_demandes]`) affiche désormais un formulaire de notation (étoiles + commentaire) directement sur les demandes résolues non encore évaluées
- La page Statistiques de l'administration affiche la moyenne de satisfaction et sa répartition
- L'email de résolution invite le citoyen à évaluer sa demande

## 0.5.0 — Admin Services & Catégories + indicateur citoyen connecté

- Nouvelle page d'administration **Services & Catégories** : CRUD complet (ajout, édition inline, suppression) pour les services et les catégories/sous-catégories, avec configuration du SLA (délai en heures) et de l'ordre d'affichage par catégorie
- Le formulaire de signalement affiche désormais une bannière "Connecté en tant que [prénom]" quand un citoyen est authentifié, en plus de masquer les champs invité

## 0.4.0 — Comptes citoyens indépendants de WordPress

### Changement d'architecture
- Les comptes citoyens ne sont plus liés aux comptes WordPress (`wp_users`) : nouveau système d'authentification propre à la table `wp_grc_citoyens`, avec mot de passe hashé (`wp_hash_password`, indépendant de wp_users)
- Les comptes **agents/élus/admin** (staff municipal) restent sur les comptes WordPress natifs — aucun changement pour eux
- Nouveaux endpoints : `POST /citoyen/register`, `POST /citoyen/login`, `POST /citoyen/refresh`, `GET /citoyen/me`
- Les JWT portent désormais un claim `type` (`agent` ou `citoyen`) : un token citoyen ne peut jamais authentifier un utilisateur WordPress, même en cas de collision d'ID numérique (correctif de sécurité important sur le middleware)
- `/mes-demandes` et `/demandes/public-submit` utilisent l'identité citoyenne du JWT plutôt que la session WordPress
- Le front-office (`[grc_mes_demandes]`) gère désormais lui-même la session citoyenne côté navigateur (token stocké localement), avec onglets Connexion / Inscription / Suivi invité
- Le formulaire de signalement masque automatiquement les champs invité si un citoyen est connecté

## 0.3.2 — Correctif chargement des scripts front-office

- Les fichiers `frontend.js`/`frontend.css` ne se chargeaient pas de façon fiable sur les pages contenant `[grc_signalement_form]` ou `[grc_mes_demandes]` : la détection reposait sur `has_shortcode()` appliqué à `post_content`, qui peut échouer selon la façon dont le contenu est stocké ou construit.
- Ces assets sont désormais chargés systématiquement sur tout le front-office (hors administration), éliminant cette classe de bug.

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
