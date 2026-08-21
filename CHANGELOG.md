# Changelog

## 0.44.2 — Reconfiguration de la 2FA agents (profil) + réinitialisation par un administrateur

- **Depuis son propre profil WordPress** (Utilisateurs → Mon profil) : chaque agent peut désormais changer de méthode (TOTP ↔ email) ou régénérer un secret TOTP (ex : changement de téléphone), avec confirmation par code avant application — accessible via un bouton "Changer de méthode" / "Configurer maintenant"
- **Réinitialisation par un administrateur** : nouveau lien "Réinitialiser sa 2FA" sur la liste des utilisateurs (Utilisateurs → Tous les utilisateurs), pour débloquer un agent ayant perdu l'accès à son second facteur (téléphone perdu/cassé) — la 2FA de cet agent redevient "non configurée", à reconfigurer entièrement à sa prochaine connexion
- Correctif de conception : le mécanisme initialement prévu pour afficher un message d'erreur en cas de code invalide (`user_profile_update_errors`) se déclenche trop tôt dans le cycle de sauvegarde du profil pour être fiable depuis ce contexte — remplacé par une notice admin classique via transient

## 0.44.1 — Correctif diagnostic : le bouton "Code envoyé" confirmait même en cas d'échec réel

- Le bouton "Recevoir un code par email" (configuration 2FA agent) affichait "Code envoyé" quelle que soit la réponse du serveur, y compris en cas d'échec — la réponse HTTP n'était jamais vérifiée côté JavaScript
- `wp_mail()` peut échouer silencieusement (configuration serveur/SMTP) sans que WordPress ne le signale par défaut — le résultat réel est désormais vérifié et reflété dans la réponse (le bouton affiche "Échec de l'envoi — réessayer" si l'email n'a pas pu être envoyé)
- Ajout d'une consignation des échecs d'envoi (`wp_mail_failed`) dans les logs du serveur, pour diagnostiquer la cause exacte si le problème persiste
- Mention des indésirables/spam ajoutée au message de confirmation

## 0.44.0 — Double authentification obligatoire pour les agents

Nouvelle classe `GRC_Agent_2FA`, entièrement distincte de la 2FA citoyenne
(JWT, facultative) : celle-ci s'intègre au flux de connexion natif
WordPress (cookie + nonce) et est **imposée** à tout utilisateur disposant
de capacités GRC (rôles `grc_agent`, `grc_responsable`, `grc_elu`, et
administrateur — voir `GRC_Roles`).

- **TOTP** (application d'authentification, QR code) **ou email** (code à
  usage unique, 5 minutes), au choix de l'agent
- Première connexion : configuration obligatoire avant de pouvoir continuer
  — impossible d'accéder à l'administration sans avoir choisi et validé une
  méthode
- Connexions suivantes : simple saisie du code correspondant à la méthode
  déjà configurée
- Techniquement : interception via le filtre `authenticate` (après
  vérification du mot de passe par WordPress, mais *avant* l'établissement
  de la session — `wp_set_auth_cookie()` n'est appelé qu'après validation du
  second facteur), écran dédié `wp-login.php?action=grc_2fa`
- Secret TOTP temporaire conservé le temps de la configuration (transient,
  15 min) pour qu'une erreur de saisie ne rende pas le QR code déjà scanné
  invalide
- Réutilise l'infrastructure existante : `GRC_TOTP` (déjà générique),
  `GRC_Encryption` pour le secret stocké, même gabarit d'email que la 2FA
  citoyenne

⚠️ Fonctionnalité critique pour l'accès à l'administration — **à tester
manuellement en conditions réelles avant tout déploiement en production**
(non couverte par les tests automatisés, qui ne peuvent pas simuler le
flux de connexion WordPress complet). Prévoir un accès de secours
(ex : accès direct à la base de données) en cas de blocage pendant les
premiers tests.

## 0.43.17 — Accessibilité RGAA : audit très étendu (contrastes calculés, tableaux, rôles ARIA)

Recherche méthodique, au-delà des vérifications manuelles habituelles :

- **Contrastes recalculés mathématiquement** (formule de luminance relative WCAG) sur toutes les paires couleur/fond du CSS, plutôt qu'à l'œil : trouvé et corrigé le jour "dernières places" du calendrier RDV (`#8a6817` sur fond clair, 4,47:1, sous le seuil) et l'étoile de notation sélectionnée (`#DEA128`, la couleur or de la charte, seulement 2,27:1 en tant que composant d'interface) → `#B8860B` (3,25:1)
- **3 tableaux** (mes signalements/démarches/rendez-vous, vue liste) : en-têtes `<th>` sans `scope="col"`, corrigé ; colonnes d'action sans libellé dotées d'un texte masqué visuellement ("Avis"/"Actions")
- **`role="grid"`** sur le calendrier de rendez-vous : structure ARIA incomplète (pas de `role="row"`/`gridcell` associés, potentiellement déroutant pour un lecteur d'écran) — remplacé par `role="group"`, plus sûr. Les jours cliquables sont en revanche déjà de vrais `<button>` avec `aria-label`/`aria-pressed` complets, aucun souci d'accessibilité clavier là
- Vérification exhaustive : tous les attributs `aria-*` et `role=` du site recensés et confirmés valides (aucune faute de frappe) ; aucun `id` HTML dupliqué ; aucun groupe de cases à cocher/boutons radio nécessitant `<fieldset>`/`<legend>`

## 0.43.16 — Accessibilité RGAA : recherche élargie, 3 champs sans label corrigés dont un mal ciblé

- Champ de réponse du fil de messages d'une démarche (texte + pièces jointes) : aucun `<label>`, seul un `placeholder` — ajoutés (visuellement masqués, le contexte visuel restant inchangé)
- Champ commentaire de satisfaction : même correctif
- **Champ téléphone des démarches (indicatif + numéro)** : le `<label>` principal ciblait en réalité le `<div>` conteneur du champ, pas un vrai champ de formulaire — association totalement invalide, invisible pour un lecteur d'écran malgré une apparence correcte. Corrigé avec `role="group"` + `aria-labelledby` sur le conteneur, et un label dédié pour chacun des deux sous-champs (indicatif, numéro)
- Recherche élargie effectuée (comparaison systématique de tous les `id` de `<div>` avec toutes les cibles `label for=`, éléments cliquables personnalisés, icônes, texte de lien) : aucun autre cas trouvé sur le site à ce stade

## 0.43.15 — Accessibilité RGAA : bannières d'état non annoncées

- 5 bannières affichées/masquées dynamiquement selon l'état de connexion ("Connecté en tant que...", "Vous devez être connecté(e)...") n'étaient jamais annoncées aux lecteurs d'écran lors de leur apparition — `role="status"` + `aria-live="polite"` ajoutés, à l'identique du motif déjà utilisé pour la bannière de doublons de signalement

## 0.43.14 — Accessibilité RGAA : lien pièce jointe ouvrant un nouvel onglet non signalé

- Les liens de téléchargement des pièces jointes (`target="_blank"`) n'indiquaient nulle part qu'ils ouvrent un nouvel onglet (critère RGAA 6.2) — ajout d'un texte visuellement masqué "(nouvelle fenêtre)", perceptible uniquement par les technologies d'assistance
- `rel="noopener"` ajouté au passage (bonne pratique de sécurité pour tout lien `target="_blank"`)
- Nouvelle classe utilitaire réutilisable `.grc-visually-hidden`
- Vérifié : aucun texte de lien générique ("cliquez ici", "en savoir plus"...) sur le site

## 0.43.13 — Correctif : "Erreur lors de la recherche" en suivi invité (portée JavaScript)

- Le correctif précédent (v0.43.12) appelait `renderMessageAttachments()` depuis `renderDemandesList()`, mais cette fonction était déclarée dans une portée JavaScript imbriquée différente (à l'intérieur du gestionnaire `DOMContentLoaded`), la rendant invisible depuis `renderDemandesList()` — provoquant une erreur silencieusement rattrapée par le bloc `catch` du suivi invité, affichée comme "Erreur lors de la recherche."
- Déplacée au niveau supérieur (portée globale du fichier), accessible depuis tous ses points d'appel

## 0.43.12 — Correctif : pièces jointes invisibles/inaccessibles en suivi invité

- En mode "Suivi invité" (numéro de suivi + email, sans connexion), le nombre de pièces jointes s'affichait en simple texte ("1 pièce(s) jointe(s)") sans aucun moyen de les consulter — corrigé : les pièces jointes apparaissent désormais comme de vrais liens de téléchargement, comme pour un citoyen connecté
- Le serveur autorisait déjà ce cas (email transmis en paramètre, vérifié contre l'email du demandeur), seul le rendu côté client ne l'exploitait pas
- Contraste de couleur corrigé au passage sur ce même libellé (`#777`, 4,4:1, sous le seuil requis) → `#595959`

## 0.43.11 — Accessibilité RGAA : attribut autocomplete manquant (14 champs)

- Critère RGAA 11.13 : les champs email, mot de passe, prénom, nom et téléphone n'indiquaient jamais leur finalité via `autocomplete`, empêchant un remplissage assisté correct (gestionnaires de mots de passe, technologies d'assistance à la saisie). Ajouté sur les 14 champs concernés : connexion, mot de passe oublié/réinitialisation, inscription, suivi invité, email du formulaire de démarche, et les 6 champs du panneau "Mon profil" (identité, coordonnées, changement de mot de passe)
- Aucun `tabindex` positif détecté sur le site (vérifié, rien à corriger)

## 0.43.10 — Accessibilité RGAA : contrastes de couleur insuffisants

- Libellés des jours de la semaine du calendrier de rendez-vous (`#888` sur fond blanc, ratio 3,7:1) : remontés à `#595959` (~7:1)
- Étoiles non sélectionnées du widget de notation de satisfaction (`#ccc`, ratio 1,6:1) : remontées à `#949494` (~3:1, seuil applicable aux composants d'interface)
- Les jours du calendrier sans disponibilité ou complets restent volontairement à faible contraste : ce sont des états non sélectionnables (équivalent à un contrôle désactivé), exemptés par les critères de contraste RGAA/WCAG — le jour complet dispose en complément d'un barré, indice non basé sur la couleur

## 0.43.9 — Deux correctifs : avis citoyen invisible en admin, erreur "headers already sent" sur la recherche

- **Avis citoyen (note + commentaire de satisfaction)** : jamais affiché côté admin après clôture d'un signalement — seules les statistiques agrégées étaient exploitées. Ajout d'une section dédiée sur la fiche détail d'un signalement, si une évaluation existe.
- **Recherche admin (GRC Citoyenne → Recherche)** : en cas de correspondance exacte (numéro GRC-/DEM-/RDV- ou email), la page tentait une redirection (`wp_safe_redirect`) *après* avoir déjà affiché le formulaire de recherche — provoquant un "headers already sent" dès qu'un octet avait été envoyé plus tôt dans la page (par exemple par le thème actif), avec pour conséquence une redirection silencieusement échouée. Corrigé en traitant la redirection éventuelle avant toute sortie HTML.

## 0.43.8 — Accessibilité RGAA : état de sélection non exposé (créneaux, durée, onglets)

- Boutons de créneau horaire et de durée du formulaire de rendez-vous : la sélection n'était indiquée que visuellement (classe CSS), sans `aria-pressed` — corrigé, à l'identique du motif déjà utilisé sur les jours du calendrier
- Onglets Connexion / Créer un compte / Suivi invité : aucune sémantique ARIA Tabs (`role="tablist"`, `role="tab"`, `aria-selected`, `role="tabpanel"`) — entièrement ajoutée, l'état sélectionné bascule désormais correctement au clic

## 0.43.7 — Accessibilité RGAA : annonce des messages, boutons du calendrier, hiérarchie des titres

- **Correctif centralisé** : la fonction JS partagée `showMessage()` (utilisée par pratiquement tous les formulaires du site — connexion, inscription, RDV, fil de messages, profil...) ne rendait annonçable par un lecteur d'écran que les messages du formulaire 2FA. Ajout de `role="status"` + `aria-live="polite"` directement dans cette fonction commune : tous les messages de succès/erreur du site sont désormais annoncés automatiquement, sans avoir à corriger chaque gabarit individuellement
- Boutons de navigation du calendrier de rendez-vous (‹ ›) : ajout d'un nom accessible ("Mois précédent"/"Mois suivant"), ils n'avaient que des caractères de ponctuation comme contenu
- Page "Mes demandes" (démarches, signalements, rendez-vous) : les trois titres de section ("Mes demandes", "Mes démarches", "Mes rendez-vous") étaient en `<h3>` alors qu'ils sont les tout premiers titres du contenu de la page, juste après le titre de page — remontés en `<h2>` pour ne pas sauter de niveau

## 0.43.6 — Accessibilité RGAA : étiquettes de champs de la barre citoyenne + widgets composites RDV

- Barre citoyenne globale ("Mon profil") : les 6 champs (prénom, nom, email, téléphone, mot de passe actuel, nouveau mot de passe) avaient un `<label>` sans attribut `for`, sans aucune association programmatique avec leur champ — un lecteur d'écran n'annonçait donc pas leur nom. Corrigé.
- Formulaire de rendez-vous : les trois widgets composites personnalisés (choix de durée, calendrier, grille de créneaux) utilisaient un `<label>` sans cible valide (pas de champ natif à associer) — remplacé par un regroupement `role="group"` + `aria-labelledby`, sémantiquement correct pour ce type de contenu

## 0.43.5 — Correctif : bannière citoyenne absente jusqu'au rechargement de page

- Bug : après une connexion, inscription ou validation 2FA réussie, la bannière citoyenne en haut de page (nom, navigation, "Mon profil", "Se déconnecter") n'apparaissait qu'après avoir rechargé manuellement la page — elle ne s'affichait jusqu'ici qu'une seule fois, au chargement initial de la page (avant que la connexion n'ait eu lieu)
- Corrigé : la bannière s'affiche désormais immédiatement dès que la session démarre, sans attendre de rechargement
- Garde ajoutée pour éviter toute bannière dupliquée si la fonction est appelée plusieurs fois

## 0.43.4 — Nouvel endpoint public /services (préparation module Rendez-vous mobile)

- Ajout de `GET /services` : liste des services actifs (id, nom), utilisée par l'application Android pour construire le sélecteur de service du formulaire de prise de rendez-vous — même situation que `/categories` (v0.43.2) : jusqu'ici uniquement rendu côté serveur dans les pages du site web

## 0.43.3 — Latitude/longitude/adresse dans la réponse API des signalements

- `GET /mes-demandes` inclut désormais `latitude`, `longitude` et `adresse_lieu` pour chaque signalement — nécessaire pour que l'application mobile puisse afficher une carte sur la fiche détaillée d'un signalement (jusqu'ici absent de la réponse, alors que ces données sont bien enregistrées en base à la création)

## 0.43.2 — Nouvel endpoint public /categories (préparation module Signalements mobile)

- Ajout de `GET /categories` : liste des catégories de signalement actives (id, nom, parent_id, service_id), utilisée par l'application Android pour construire le sélecteur de catégorie du formulaire de signalement
- Jusqu'ici, les catégories n'étaient disponibles que rendues côté serveur dans la page du site web — aucune route REST n'existait pour un client externe comme l'application mobile
- Route publique (pas d'authentification requise), cohérent avec le reste du formulaire de signalement

## 0.43.1 — JPG/PNG acceptés pour les pièces jointes de démarche

- Les démarches administratives acceptent désormais aussi les images JPG et PNG en pièce jointe, en plus du PDF et du Word (.docx) — à la création d'un dossier et dans les réponses du fil de messages
- Détection du contenu réel du fichier (pas seulement l'extension) déjà en place pour ces formats, réutilisée telle quelle
- Sélecteurs de fichiers du site web mis à jour (formulaire de démarche + réponse au fil de messages)

## 0.43.0 — Plusieurs photos sur un signalement

- Le formulaire de signalement ([grc_signalement_form]) n'acceptait qu'une seule photo — le serveur gérait déjà l'envoi multiple (utilisé côté démarches), seule l'interface citoyenne limitait à un fichier
- Champ photo passé en sélection multiple, toutes les photos sélectionnées sont désormais envoyées

## 0.42.2 — Correctif critique : lien de réinitialisation de mot de passe toujours invalide

- **Bug** : le lien de réinitialisation de mot de passe était signalé "invalide ou expiré" même en cliquant dessus immédiatement après l'avoir demandé. Cause : l'expiration était stockée en heure UTC (`gmdate()`) mais comparée à l'heure locale du site (`current_time('mysql')`) — sur le fuseau Europe/Paris, l'heure locale est toujours en avance sur l'UTC, rendant le lien invalide dès sa création.
- **Même bug corrigé sur deux autres mécanismes** découverts au même endroit :
  - Rafraîchissement de session agent (jetons valables 90 jours)
  - Rafraîchissement de session citoyen (jetons valables 90 jours)
  - Ces deux cas n'étaient pas visibles en usage normal (l'écart n'est que de quelques heures sur une fenêtre de 90 jours), mais relevaient de la même erreur et ont été corrigés par cohérence.
- **Correctif supplémentaire** : le délai avant refus automatique d'une demande de rendez-vous non traitée (cron quotidien) présentait un décalage similaire, retardant légèrement le refus automatique par rapport au délai réellement configuré — corrigé en alignant le calcul sur la même base horaire que l'enregistrement du rendez-vous.

## 0.42.1 — Accessibilité RGAA : balayage des contrastes + hiérarchie des titres

### Contrastes
- Badge "Clôturé" (front) : gris #777 sur blanc était à 4.48, sous le seuil requis de 4.5 pour du texte de petite taille — assombri à #666 (5.74)
- Badges admin "En cours"/"Assigné" (fonction distincte, dupliquée par rapport au CSS déjà corrigé) : texte blanc sur fond doré à 2.27 — corrigé avec le même texte foncé que côté front
- Couleurs des graphiques et légendes de la page Statistiques (doré) : 2.27 contre le fond blanc, sous le seuil non-texte de 3:1 (WCAG 1.4.11) — remplacées par une nuance plus foncée déjà utilisée ailleurs dans le plugin

### Hiérarchie des titres
- Le panneau "Mon profil" passait directement à un `<h4>` ("Double authentification") sans titre parent — ajout d'un `<h3>Mon profil</h3>` en tête du panneau pour une hiérarchie correcte

## 0.42.0 — Accessibilité RGAA : navigation clavier de la bannière/panneau profil

- Bouton "Mon profil" : `aria-expanded`/`aria-controls` synchronisés avec l'état ouvert/fermé, annoncés correctement aux lecteurs d'écran
- Le focus se déplace automatiquement dans le panneau à l'ouverture, et revient sur le bouton "Mon profil" à la fermeture
- **Touche Échap** pour fermer le panneau profil, avec restitution du focus — cohérent avec le comportement standard attendu de ce type de composant
- Lien de navigation actif marqué `aria-current="page"` (en plus de la classe CSS) pour une identification fiable par les lecteurs d'écran
- Nav de la barre citoyenne explicitement libellée (`aria-label`) pour la distinguer d'une éventuelle navigation du thème
- QR code de configuration TOTP marqué décoratif (`aria-hidden`) : un équivalent texte (la clé à saisir manuellement) est déjà fourni juste à côté

## 0.41.2 — Accessibilité RGAA : piège de focus sur la pop-up 2FA

- La pop-up d'incitation à la double authentification (ajoutée en 0.41.1) piège désormais le focus clavier tant qu'elle est ouverte (Tab/Shift+Tab ne permettent plus d'en sortir accidentellement), avec restauration du focus sur l'élément d'origine à la fermeture — conforme WCAG 2.4.3 / 2.1.2
- Le focus se déplace automatiquement dans la pop-up à l'ouverture, et vers le bon champ du panneau profil lors du clic sur "Activer maintenant"
- Vérification des associations label/champ et des contrastes sur l'ensemble des nouveaux formulaires (mot de passe oublié, réinitialisation, code 2FA) : déjà conformes

## 0.41.1 — Pop-up d'incitation à la double authentification

- Une pop-up s'affiche désormais pour tout citoyen connecté n'ayant pas encore activé la double authentification, l'invitant à le faire
- **Non bloquante** : "Activer maintenant" ouvre directement la section 2FA du profil, "Plus tard" (ou clic en dehors, ou touche Échap) la referme et reporte le rappel de 7 jours (mémorisé dans le navigateur, pas de sollicitation à chaque page)
- Ne s'affiche jamais une fois la double authentification activée

## 0.41.0 — Mot de passe oublié et double authentification (citoyens)

### Mot de passe oublié
- Nouveau lien "Mot de passe oublié ?" sur le formulaire de connexion citoyen
- Email avec lien de réinitialisation valable 1 heure, à usage unique (token à 32 octets, haché en base)
- Réponse volontairement identique que le compte existe ou non, pour ne pas permettre de déduire quels emails sont enregistrés
- Toute session existante est révoquée à la réinitialisation, par précaution

### Double authentification (citoyens)
- Le citoyen choisit sa méthode depuis "Mon profil" : **par email** (code à usage unique envoyé à la connexion) ou **par application d'authentification** (TOTP — Google Authenticator, Authy, FreeOTP...)
- Implémentation TOTP conforme RFC 6238, **validée contre le vecteur de test officiel**, sans dépendance externe (`GRC_TOTP`, 8 tests dédiés)
- QR code généré **entièrement côté client** (bibliothèque chargée en CDN) — le secret TOTP ne transite jamais vers un service tiers
- Connexion en deux temps si la 2FA est active : mot de passe puis code de vérification, via un token temporaire de 5 minutes non utilisable pour l'API

### Suite de tests
- 37 tests désormais (contre 29), avec la couverture complète de `GRC_TOTP`

## 0.40.0 — Export / Import de configuration

- Nouvel écran **GRC Citoyenne → Export / Import**
- **Export** : génère un fichier JSON contenant services, catégories de signalement, types de démarches (avec leurs champs), modèles de messages, et réglages généraux (délais, session, matrice de notifications, fournisseur de captcha choisi)
- **Import** : charge un fichier JSON exporté depuis un autre environnement — les éléments existants (identifiés par nom ou slug) sont mis à jour, les nouveaux sont créés, rien n'est jamais supprimé automatiquement
- Les catégories avec sous-catégories sont gérées correctement (résolution du parent par nom, en deux passes)
- **Volontairement exclus** de l'export/import : toutes les données citoyennes (demandes, démarches, RDV, citoyens), les clés de chiffrement/JWT, les identifiants SMTP et les clés secrètes des fournisseurs de captcha — ces éléments restent propres à chaque environnement
- Cas d'usage typique : configurer les catégories/types de démarches/modèles sur `test3.berrelesalpes.fr`, exporter, puis importer directement en production sans ressaisie manuelle

## 0.39.0 — Tests automatisés (PHPUnit)

- Suite de **29 tests unitaires** (54 assertions) couvrant les points les plus sensibles du plugin :
  - `GRC_Encryption` : aller-retour chiffrement/déchiffrement, non-déterminisme du chiffrement, déterminisme du hash de recherche, robustesse face aux données corrompues
  - `GRC_JWT` : émission/vérification, expiration, rejet d'un token falsifié ou signé avec un autre secret
  - `GRC_Captcha` : génération, vérification, rejet d'un token falsifié ou malformé
  - `GRC_Citoyen_Helper` : formatage et extraction du numéro citoyen
- Tests unitaires en isolation (`tests/bootstrap.php` fournit des substituts minimalistes aux quelques fonctions WordPress nécessaires) — aucune base de données requise, exécutables avec un simple `phpunit` local
- `composer.json` (dépendance de dev `phpunit/phpunit`) + `phpunit.xml` + `tests/README.md` documentant la portée et les limites de la suite
- Nouveau workflow **GitHub Actions** (`.github/workflows/tests.yml`) : exécute automatiquement le lint PHP et la suite de tests sur PHP 8.1/8.2/8.3 à chaque push et pull request

## 0.38.0 — Détection des signalements similaires à proximité

- Nouvel endpoint `GET /demandes/proches` : détecte les signalements non résolus dans un rayon de 100 mètres autour d'un point (formule de Haversine calculée en SQL)
- Dès que le citoyen positionne son repère sur la mini-carte du formulaire de signalement, un encart s'affiche automatiquement si des signalements similaires existent déjà à proximité (titre, statut, distance, date) — **informatif, non bloquant** : le citoyen peut tout de même envoyer son signalement si ce n'est pas le même problème
- Objectif : réduire les doublons (ex : plusieurs signalements du même nid-de-poule) sans jamais empêcher un citoyen de signaler

## 0.37.0 — Recherche globale unifiée

- Nouvel écran **GRC Citoyenne → Recherche** : un seul champ pour retrouver un signalement, une démarche, un rendez-vous ou un citoyen, sans naviguer entre plusieurs écrans
- **Correspondance exacte** (numéro de suivi `GRC-...`, de dossier `DEM-...`, de rendez-vous `RDV-...`, numéro citoyen `CIT-...`, ou email exact) → redirection directe vers la fiche concernée
- **Recherche texte libre** : titre de signalement, type de démarche, motif ou service de rendez-vous — résultats groupés par catégorie avec liens directs
- La recherche par nom reste volontairement indisponible (noms chiffrés en base) ; la note l'explique directement dans l'écran

## 0.36.0 — Matrice en onglet dédié (vraie grille) + création rapide des modèles par défaut

### Matrice des notifications
- Déplacée dans son propre onglet **Réglages → Matrice des notifications** (retirée de l'onglet Email)
- Restructurée en **vraie matrice** : les événements (Signalement créé, statut modifié, RDV validé...) sont en lignes, les destinataires (Citoyen / Agents) en colonnes — une case à cocher par intersection valide, "—" pour les combinaisons qui n'existent pas

### Modèles par défaut en un clic
- **GRC Citoyenne → Modèles de messages** affiche désormais un encart listant les emails automatiques sans modèle personnalisé associé, avec un bouton **"Créer tous les modèles par défaut manquants"**
- Chaque modèle créé reprend le texte par défaut actuellement intégré au plugin (avec balises), prêt à être personnalisé plutôt que de partir d'une page blanche
- Un modèle déjà associé à un type n'est jamais écrasé

## 0.35.1 — Balises prénom/nom de l'agent

- Nouvelles balises `{agent_prenom}` et `{agent_nom}` : résolues avec le prénom/nom de l'agent WordPress connecté qui déclenche l'action (utile pour signer un message, ex : "Cordialement, {agent_prenom}")
- Disponibles à la fois pour l'insertion manuelle d'un modèle (réponse sur un signalement ou une démarche) et pour les emails automatiques déclenchés par un agent (changement de statut, validation/refus de RDV)
- Vides pour les emails générés sans intervention d'un agent (accusé de réception à la création, rappel automatique) — comportement normal, pas une erreur

## 0.35.0 — Modèles personnalisables pour les emails automatiques

- Dans **GRC Citoyenne → Modèles de messages**, un modèle peut désormais être associé à un **email automatique précis** (accusé de réception signalement, accusé de réception démarche, changement de statut, validation/refus de rendez-vous, rappel...) via un nouveau champ "Utiliser comme contenu d'un email automatique"
- Un seul modèle peut être associé à un type d'email donné (sélectionner un nouveau modèle pour ce type retire automatiquement l'association de l'ancien)
- Nouveau champ **Sujet** sur les modèles, utilisé pour l'objet de l'email quand le modèle est associé à une notification automatique
- Les balises (`{numero}`, `{prenom}`, `{nom}`, `{statut}`, `{service}`, `{date}`, `{recap}`...) sont résolues avec les vraies données au moment de l'envoi
- 8 emails automatiques concernés : accusé de réception (signalement, démarche, RDV), changement de statut (signalement, démarche), validation/refus/rappel de RDV
- Sans modèle associé à un type donné, le texte par défaut intégré au plugin continue d'être utilisé — aucune rupture

## 0.34.0 — Balises de fusion, récapitulatifs, notifications bidirectionnelles et matrice d'activation

### Balises dans les modèles de messages
- `{numero}`, `{titre}`, `{prenom}`, `{nom}`, `{statut}`, `{service}`, `{date}` sont désormais remplacées automatiquement par les vraies données du dossier au moment de l'insertion d'un modèle (résolution côté serveur, avant injection dans le sélecteur)
- Aide contextuelle listant les balises disponibles directement dans **GRC Citoyenne → Modèles de messages**

### Récapitulatifs dans les accusés de réception
- **Signalements** : l'email de confirmation inclut désormais objet, catégorie, service, lieu, date et un extrait de la description
- **Démarches** : nouvel accusé de réception (n'existait pas jusqu'ici !) avec récapitulatif construit à partir des vrais libellés de champs du formulaire
- **Rendez-vous** : récapitulatif enrichi (service, motif)

### Notifications de réponse complétées dans les deux sens
- Signalements : un agent qui répond notifie désormais le citoyen (gap comblé)
- Démarches : changement de statut (notamment "Complément requis") notifie désormais le citoyen (gap comblé)

### Matrice d'activation des notifications
- Nouveau tableau dans **Réglages → Email → Matrice des notifications** : 15 types de notifications, chacun activable/désactivable indépendamment (toutes actives par défaut)

## 0.33.0 — Modèles de messages types pour les agents

- Nouvel écran **GRC Citoyenne → Modèles de messages** : créez des réponses pré-rédigées (accusé de réception, demande de complément, information...), avec un contexte (signalements, démarches, ou les deux) et un ordre d'affichage
- Sélecteur d'insertion rapide ajouté directement dans les formulaires de réponse des signalements et des démarches : un menu déroulant "Insérer un modèle de message..." remplit instantanément la zone de texte, modifiable ensuite avant envoi
- Nouvelle table `wp_grc_modeles_messages`

## 0.32.0 — Envoi d'emails fiabilisé (SMTP) + notifications aux agents

### Le problème résolu
Aucun email ne partait jamais vers les citoyens, alors que le code appelait bien `wp_mail()` aux bons endroits. Cause la plus probable : WordPress utilise par défaut la fonction `mail()` du serveur, souvent bloquée ou mal configurée sur l'hébergement mutualisé — sans qu'aucune erreur ne remonte.

### Configuration SMTP (Réglages GRC → Email)
- Nouvel onglet dédié : serveur, port, chiffrement (TLS/SSL/aucun), identifiant, mot de passe (**chiffré au repos**, jamais réaffiché en clair), adresse et nom d'expéditeur
- **Bouton "Envoyer un email de test"** avec retour immédiat (succès ou message d'erreur), pour vérifier la configuration sans attendre un vrai signalement
- Se branche via le hook `phpmailer_init` : n'affecte que l'envoi, aucun changement de comportement si non activé

### Notifications aux agents (nouveau)
- Réglage **"Email(s) de notification générale"** (Réglages → Email), notifiés pour tout nouveau signalement, démarche ou rendez-vous — complété automatiquement par l'adresse de contact du service concerné si elle existe (**GRC Citoyenne → Services**)
- **Agent assigné** à une demande : email automatique à son adresse WordPress
- **Nouveau message citoyen** sur un dossier de démarche : notifie les agents

### Notifications citoyennes complétées
- **Réponse d'un agent** sur un dossier de démarche : le citoyen est désormais notifié par email (symétrique à la notification agent déjà en place)
- **Nouveau message d'un agent** sur un signalement (hors notes internes) : le citoyen est désormais notifié par email — ce cas n'était pas couvert jusqu'ici

## 0.31.0 — Captcha anti-robot sur les démarches en mode invité

- Les démarches (`[grc_demarche_form]`) restent accessibles sans compte, contrairement aux signalements et rendez-vous — elles étaient donc une cible pour les robots
- Application du **même système de captcha configuré dans Réglages** (interne, Cloudflare Turnstile, Google reCAPTCHA v2 ou hCaptcha) que pour l'inscription citoyenne, uniquement pour la soumission en mode invité (les citoyens connectés n'y sont pas soumis)
- Ajout d'un champ honeypot invisible en complément
- Contrôle appliqué côté serveur, avec réutilisation de la logique de vérification déjà en place pour l'inscription (aucune duplication)

## 0.30.1 — Accessibilité RGAA (deuxième vague) : alternative texte aux cartes

- Les trois cartes du plugin (statistiques admin, fiche détail d'un signalement, mini-carte de géolocalisation citoyenne) sont intrinsèquement peu accessibles aux lecteurs d'écran (contenu visuel dynamique). Ajout de `role="img"` + `aria-label` descriptif sur chacune.
- **Carte des statistiques** : ajout d'une **liste équivalente au format texte** (tableau dépliable) juste en dessous, avec numéro de suivi, titre, statut et coordonnées de chaque signalement géolocalisé — véritable alternative fonctionnelle, pas seulement décorative
- **Mini-carte de géolocalisation** (formulaire de signalement) : rappel explicite que le champ adresse en texte reste le moyen pleinement accessible de préciser un lieu, la carte n'étant qu'un outil d'ajustement complémentaire

## 0.30.0 — Accessibilité RGAA (première vague)

Début de la mise en conformité RGAA du portail citoyen (obligation légale pour un site de collectivité publique).

### Navigation clavier
- **Calendrier de rendez-vous** : les jours étaient des `<span>` cliquables uniquement à la souris — remplacés par des `<button>` réellement focusables et activables au clavier, avec `aria-label` décrivant la date et la disponibilité en texte (pas seulement par la couleur)
- Liens d'évitement ("Aller au formulaire") ajoutés en tête des 4 formulaires citoyens, visibles au focus clavier

### Lecteurs d'écran
- Notation par étoiles : `role="radiogroup"`, `aria-label` par étoile, `aria-checked` synchronisé
- Boutons icône seule (bascule Liste/Cartes, téléchargement PDF) : `aria-label` explicites
- Messages dynamiques (erreurs de formulaire, statut de géolocalisation, question du captcha) : `role="status"` + `aria-live="polite"` pour être annoncés automatiquement

### Contrastes de couleur (WCAG AA)
- Audit systématique des couleurs de la charte graphique ; correction du texte blanc sur fond doré (#DEA128), qui ne passait qu'à un ratio de 2.27 contre 4.5 requis — corrigé sur les badges de statut (front et admin)

### À poursuivre
Alternative textuelle pour les cartes Leaflet, vérification complète des autres contrastes, navigation clavier du panneau profil, structure des titres.

## 0.29.0 — Compte citoyen obligatoire pour signalements et rendez-vous

- Le **mode invité est supprimé** pour les signalements (`[grc_signalement_form]`) et les rendez-vous (`[grc_rdv_form]`) : un compte citoyen connecté est désormais requis
- Contrôle appliqué **côté serveur** (`POST /demandes/public-submit` et `POST /rdv` renvoient une erreur 401 si non authentifié) — impossible à contourner en modifiant le formulaire
- Côté citoyen non connecté : le formulaire est masqué et remplacé par un message clair avec lien direct vers la connexion/inscription (utilise la page "Mes demandes" configurée dans Réglages)
- Les démarches (`[grc_demarche_form]`) conservent le mode invité, non concernées par cette demande

## 0.28.2 — Correctif : adresse non récupérée à la géolocalisation initiale

- La recherche automatique d'adresse n'était déclenchée qu'après un ajustement du repère (glisser-déposer ou clic), pas lors de la géolocalisation initiale à l'ouverture de la page. Corrigé : l'adresse se recherche désormais dès la première position détectée, sans action du citoyen.

## 0.28.1 — Géolocalisation automatique à l'ouverture + repère sans aucune dépendance d'icône

- Le repère sur la mini-carte du signalement est désormais une **pastille dessinée en CSS pur** (identique visuellement à celle de la carte admin) au lieu d'une icône image — élimine toute possibilité de décalage lié au chargement d'assets externes, centrage pixel-parfait garanti
- **Géolocalisation déclenchée automatiquement** à l'ouverture du formulaire de signalement (le navigateur demande l'autorisation comme d'habitude) : la carte, le repère et l'adresse se remplissent sans action du citoyen. Le bouton devient "Actualiser ma position" pour les cas où l'autorisation a été refusée ou pour recalculer la position

## 0.28.0 — Correctif décalage visuel du repère + adresse automatique

### Correctif décalage visuel
- Cause identifiée : les images de l'icône de repère par défaut de Leaflet ne se chargeaient pas depuis le CDN (chemins relatifs cassés), ce qui décalait visuellement la pointe du repère affiché par rapport aux coordonnées réellement enregistrées — alors que les coordonnées elles-mêmes étaient toujours correctes. La carte admin (qui utilise un simple cercle sans icône) n'était pas concernée, d'où l'écart apparent entre les deux vues.
- Correctif : chargement explicite des images d'icône depuis le CDN.

### Adresse automatique
- Nouvel endpoint `GET /geocode/reverse` : géocodage inversé (coordonnées → adresse) via Nominatim/OpenStreetMap, proxifié côté serveur (respect de la politique d'usage Nominatim : User-Agent identifiant, limitation de débit)
- Le champ "Adresse / lieu concerné" du formulaire de signalement se remplit désormais **automatiquement** après géolocalisation ou ajustement du repère (avec anti-rebond pour éviter les appels excessifs pendant un glisser-déposer) — le citoyen peut toujours corriger manuellement si besoin

## 0.27.1 — Précision du repère GPS lors du signalement

- La mini-carte de géolocalisation du formulaire de signalement passe en zoom 18 (plus précis qu'avant) et permet désormais de **cliquer n'importe où sur la carte** pour repositionner le repère, en plus du glisser-déposer — plus intuitif et précis sur une petite carte que le glisser-déposer seul
- Affichage en direct des coordonnées retenues (latitude/longitude à 6 décimales) sous la carte, pour vérification avant envoi du signalement

## 0.27.0 — Renommage "Demandes" → "Signalements" + carte visible en fiche admin

### Renommage
- Le libellé "Demandes" devient **"Signalements"** dans tout l'affichage admin (menu, titres, statistiques) — les URLs, tables et code internes restent inchangés pour ne rien casser

### Carte dans la fiche admin
- La fiche détaillée d'un signalement géolocalisé (**GRC Citoyenne → Signalements**) affiche désormais une **vraie carte** (Leaflet + OpenStreetMap) directement dans l'interface, avec les coordonnées GPS et un lien "Ouvrir en plein écran" vers OpenStreetMap — indépendant du PDF, ne nécessite pas l'extension GD côté serveur

## 0.26.1 — Réglages en onglets + correctif PDF (PHP 8.2+) + bouton PDF dans la liste

- **Correctif** : `utf8_decode()` (utilisé pour la génération PDF) est déprécié depuis PHP 8.2 et déclenchait un avertissement à chaque téléchargement. Remplacé par `mb_convert_encoding()`, l'équivalent recommandé — plus aucun avertissement.
- **Réglages GRC** réorganisés en 5 onglets (Rendez-vous, Sécurité des sessions, Anti-robot, Pages du portail citoyen, Journal d'audit) pour plus de clarté — un seul formulaire, navigation instantanée sans rechargement de page, aucune perte de saisie en changeant d'onglet
- Bouton PDF (icône 📄 seule, sans texte) ajouté directement dans la liste des demandes, en plus de la vue détail

## 0.26.0 — Choix du fournisseur anti-robot (interne, Turnstile, reCAPTCHA, hCaptcha)

- **GRC Citoyenne → Réglages → Anti-robot à l'inscription** : sélecteur de fournisseur remplaçant le réglage Turnstile-only précédent
- Quatre options : **Interne** (captcha mathématique auto-hébergé, aucun tiers — par défaut), **Cloudflare Turnstile**, **Google reCAPTCHA v2**, **hCaptcha**
- Les clés des trois fournisseurs tiers peuvent être renseignées à l'avance ; seul le fournisseur sélectionné dans le menu déroulant est actif
- Rendu du bon widget côté citoyen et vérification côté serveur généralisés à tous les fournisseurs (même mécanique d'appel `siteverify`, propre à chacun)
- Note RGPD affichée directement dans les réglages, rappelant que les trois fournisseurs tiers impliquent une communication du navigateur du citoyen avec un service externe

## 0.25.1 — Cloudflare Turnstile (protection anti-robot renforcée, optionnelle)

- Nouveau réglage **GRC Citoyenne → Réglages → Anti-robot à l'inscription** : clés Cloudflare Turnstile (site + secrète), facultatives
- Si configurées, l'inscription citoyenne utilise **Turnstile** à la place du captcha mathématique auto-hébergé : gratuit, quasi invisible pour l'utilisateur, et beaucoup plus robuste face à des robots ciblés (le captcha mathématique en texte brut, bien qu'utile contre les robots génériques avec le honeypot, reste trivialement contournable par un robot inspectant directement l'API)
- Sans clés configurées, le comportement précédent (captcha maths + honeypot) reste actif automatiquement — aucune rupture
- Vérification côté serveur du jeton Turnstile auprès de Cloudflare avant toute création de compte
- Note RGPD affichée dans les réglages : Turnstile étant un service tiers (Cloudflare, États-Unis), son activation implique une communication du navigateur du citoyen avec Cloudflare, à mentionner dans la politique de confidentialité du site

## 0.25.0 — Export PDF des signalements (avec carte) + captcha à l'inscription

### Export PDF des signalements
- Bibliothèque **FPDF** vendorée (fichier autonome, licence libre, ~120 Ko avec les métriques de polices) dans `includes/lib/` — aucune dépendance Composer requise
- Nouvelle classe `GRC_Static_Map` : génère une carte du lieu du signalement en assemblant des tuiles OpenStreetMap (grille 3×3, zoom 17) avec un marqueur positionné exactement sur les coordonnées GPS — pas de service de carte statique tiers non-officiel, mise en cache sur disque (`wp-content/uploads/grc-maps/`) pour éviter les appels répétés
- Nouvelle classe `GRC_PDF_Signalement` : génère un PDF complet (informations générales, description, citoyen concerné avec numéro unique, lieu + carte si géolocalisé, historique des échanges non-internes)
- Bouton **"📄 Télécharger le PDF"** sur la vue détail d'une demande en administration

### Captcha à l'inscription citoyenne
- Nouvelle classe `GRC_Captcha` : captcha mathématique simple auto-hébergé (aucune donnée transmise à un service tiers, contrairement à Google reCAPTCHA — choix plus cohérent avec une démarche RGPD pour un site institutionnel), défi à usage unique stocké 5 minutes en transient WordPress
- Nouvel endpoint public `GET /captcha`
- Formulaire d'inscription (`[grc_mes_demandes]`, onglet "Créer un compte") enrichi d'un champ honeypot invisible (piège à robots) et de la question captcha ; un nouveau défi est généré automatiquement après tout échec

## 0.24.0 — Navigation dans la bannière citoyenne + icône seule pour la bascule vue

### Bascule Cartes/Liste
- Les boutons "☰ Liste" / "🔲 Cartes" n'affichent désormais que l'icône, plus le texte — l'info-bulle (survol) précise toujours l'action

### Navigation dans la bannière citoyenne
- Nouveau réglage **GRC Citoyenne → Réglages → Pages du portail citoyen** : associez chaque shortcode (`[grc_signalement_form]`, `[grc_mes_demandes]`, `[grc_demarche_form]`, `[grc_rdv_form]`) à sa page WordPress
- La bannière affichée en haut de toutes les pages pour un citoyen connecté affiche désormais des liens de navigation directs vers ces pages ("Signaler un problème", "Mes demandes", "Faire une démarche", "Prendre rendez-vous"), en plus de "Mon profil" et "Se déconnecter"
- Seuls les liens vers des pages effectivement configurées apparaissent ; la page courante est mise en évidence
- Version responsive (mobile) : la navigation passe sur une seconde ligne

## 0.23.0 — Vue Cartes/Liste + filtres par statut dans "Mes demandes"

- Nouveau bouton **☰ Liste / 🔲 Cartes** sur chacune des trois sections (Demandes, Démarches, Rendez-vous) de `[grc_mes_demandes]` : la vue liste affiche un tableau compact, plus économe en espace que les cartes
- Le choix de vue est **mémorisé** (navigateur du citoyen) et partagé entre les trois sections
- **Filtre par statut** sur chaque section (ex : n'afficher que les demandes "En cours"), appliqué instantanément côté client sans rechargement
- Le fil d'échange des démarches et l'annulation de rendez-vous restent accessibles en mode liste

## 0.22.0 — Déconnexion automatique après inactivité (recommandation CNIL)

- Nouveau réglage **GRC Citoyenne → Réglages → Sécurité des sessions** : délai d'inactivité avant déconnexion automatique, **30 minutes par défaut**, réglable entre 5 et 60 minutes
- S'appuie sur les recommandations CNIL (guides pratiques RGPD) : verrouillage/déconnexion automatique après une période d'inactivité — 10 minutes maximum pour les postes agents traitant des données sensibles, jusqu'à 30 minutes pour des applications standards
- **Côté citoyen** : surveillance de l'activité (souris, clavier, tactile, défilement) sur les pages du portail citoyen ; alerte 1 minute avant expiration avec possibilité de prolonger la session ; déconnexion automatique (nettoyage du token local) si aucune activité n'est détectée
- **Côté administration** : même mécanisme sur les écrans GRC (Demandes, Démarches, Rendez-vous, Citoyens, Statistiques...), avec redirection vers la déconnexion WordPress à l'expiration

## 0.21.1 — Mini-carte de prévisualisation lors de la géolocalisation

- Le bouton "Utiliser ma position" sur `[grc_signalement_form]` affiche désormais une **mini-carte** (Leaflet, chargé dynamiquement au premier usage, sans alourdir les autres pages) montrant l'emplacement capturé
- Le repère est **déplaçable** : le citoyen peut ajuster précisément l'emplacement si le GPS n'est pas parfaitement exact, avant d'envoyer le signalement

## 0.21.0 — Statistiques avancées : graphiques, carte, export CSV

### Tableau de bord enrichi (GRC Citoyenne → Statistiques)
- Filtre par plage de dates (12 derniers mois par défaut)
- **4 indicateurs clés** : demandes (taux de résolution, délai moyen), démarches (taux de validation), rendez-vous (taux de confirmation), satisfaction moyenne
- **6 graphiques** (Chart.js, chargé uniquement sur cet écran) : évolution mensuelle créées/résolues, répartition par catégorie, répartition par statut (demandes/démarches/RDV), répartition des notes de satisfaction
- **Carte des signalements géolocalisés** (Leaflet + OpenStreetMap) : marqueurs colorés par statut, popup avec lien direct vers la demande
- **Export CSV** (demandes, démarches, rendez-vous) respectant la plage de dates filtrée, avec BOM UTF-8 pour une ouverture correcte dans Excel

### Géolocalisation du formulaire de signalement
- Nouveau bouton "Utiliser ma position actuelle" sur `[grc_signalement_form]` (géolocalisation navigateur, facultative) — nécessaire pour alimenter la carte, l'API supportait déjà les coordonnées mais rien ne les envoyait jusqu'ici

## 0.20.0 — Purge automatique du journal d'audit (conformité CNIL)

- Nouveau réglage **GRC Citoyenne → Réglages → Journal d'audit** : durée de conservation configurable (en mois), **12 mois par défaut** — dans la fourchette recommandée par la CNIL (recommandation du 8 octobre 2021 : 6 mois à 1 an pour les journaux techniques)
- Avertissement visuel si la durée est réglée au-delà de 12 mois, rappelant qu'un dépassement doit être documenté (obligation légale, contentieux en cours...) et n'est pas la règle par défaut
- **Purge automatique quotidienne** (cron) : les entrées plus anciennes que la durée configurée sont supprimées progressivement, jour après jour — pas de suppression brutale rétroactive
- La purge elle-même est journalisée (nombre d'entrées supprimées, durée de rétention appliquée), pour garder une trace de cette opération de conformité

## 0.19.5 — Correctif : colonne Citoyen vide dans le journal d'audit

- La colonne "Citoyen" ne s'affichait que si le détail enregistré contenait explicitement un `citoyen_id` — ce qui n'était pas le cas pour la majorité des logs (créations, messages, pièces jointes, connexions...), et jamais pour les actions dont l'objet audité EST un citoyen (inscription, connexion, changement de profil...).
- Résolution désormais automatique et rétroactive (fonctionne aussi sur les entrées déjà enregistrées) : recherche directe en base du `citoyen_id` associé à l'objet audité (demande/démarche/rdv), ou utilisation directe de l'ID quand l'objet audité est un citoyen.

## 0.19.4 — Journal d'audit plus détaillé et plus lisible

### Logs enrichis
- Les changements de statut (demandes, démarches) enregistrent désormais l'**ancien statut** en plus du nouveau, avec le numéro de dossier et le citoyen concerné
- L'assignation d'agent enregistre le **nom de l'agent** (pas seulement son ID)
- La validation/refus de rendez-vous enregistre le numéro de RDV, le citoyen concerné, et si le refus était automatique

### Affichage plus lisible
- Le journal (**GRC Citoyenne → Journal d'audit**) résout désormais les objets audités en **liens cliquables** vers leur fiche (numéro de suivi/dossier/RDV au lieu d'un ID technique brut)
- Nouvelle colonne **Citoyen** avec lien direct vers la fiche, quand l'action concerne un citoyen identifiable
- Les détails s'affichent en **liste lisible** ("Ancien statut : Nouveau", "Agent : Jean Dupont"...) au lieu d'un bloc JSON brut

## 0.19.3 — Journal d'audit accessible (bug critique corrigé) + revue de couverture

- **Bug critique corrigé** : le menu "Journal d'audit" existait mais provoquait une erreur fatale au clic (fichier non chargé, méthode manquante). Il est maintenant fonctionnel : **GRC Citoyenne → Journal d'audit** (réservé élus/admin).
- Écran de consultation avec filtres (type d'action, type d'objet, plage de dates) et pagination — affiche qui a fait quoi, quand, avec quel détail, pour les agents comme pour les citoyens.
- Revue complète du code confirmant que les actions sensibles sont déjà tracées : connexions/déconnexions (agent et citoyen), créations et modifications (demandes, démarches, rendez-vous, services, catégories, absences, disponibilités), changements de statut, assignations, archivages/désarchivages, envoi de messages, upload/téléchargement de documents, notation de satisfaction, réglages.

## 0.19.2 — Règle d'archivage pour les rendez-vous

- Un **rendez-vous** ne peut être archivé que s'il n'est plus "En attente" (Confirmé, Refusé ou Annulé) — cohérent avec les règles déjà en place pour les demandes et les démarches
- Bouton "Archiver" grisé tant que le rendez-vous est en attente, avec contrôle serveur équivalent

## 0.19.1 — Règles d'archivage par statut (demandes/démarches)

- Une **demande/signalement** ne peut être archivée que si son statut est **Résolu** ou **Clôturé**
- Une **démarche** ne peut être archivée que si son statut est **Validé** ou **Rejeté**
- Le bouton "Archiver" est grisé (non cliquable) dans les listes tant que la condition n'est pas remplie, avec une info-bulle expliquant pourquoi
- Contrôle également appliqué côté serveur (impossible de contourner via une requête directe)

## 0.19.0 — Archivage des demandes, démarches, rendez-vous et comptes citoyens

- Nouvelle colonne `archive` sur les quatre tables concernées (`wp_grc_demandes`, `wp_grc_demarches`, `wp_grc_rdv`, `wp_grc_citoyens`)
- **Par défaut, les éléments archivés sont masqués** dans toutes les listes admin (Demandes, Démarches, Rendez-vous, Citoyens)
- Nouveau filtre **"Vue"** sur chaque liste : Actifs (défaut) / Archivés uniquement / Tous
- Boutons **Archiver / Désarchiver** dans chaque liste, et sur la fiche citoyen (avec indicateur "(Archivé)" dans le titre)
- L'archivage n'affecte que la visibilité par défaut : aucune donnée n'est supprimée, tout reste consultable via le filtre "Tous" ou "Archivés"

## 0.18.5 — Fiche citoyen : motif et créneau horaire des rendez-vous

- Le tableau Rendez-vous de la fiche citoyen affiche désormais le **motif** et le **créneau horaire complet** (ex : 09:00 - 09:30), en plus de la date.

## 0.18.4 — Correctif largeur : la classe .card de WordPress limitait à 520px

- La classe `card` native de WordPress impose un `max-width` d'environ 520px, qui écrasait le `flex:1` appliqué à la colonne de droite — les tableaux restaient donc étroits malgré le conteneur élargi. Ajout de `max-width:none` en style inline sur les trois cartes concernées (Demandes, Démarches, Rendez-vous) pour neutraliser cette limite.

## 0.18.3 — Fiche citoyen : sections de droite plus larges

- La colonne Coordonnées/Résumé passe en largeur fixe (320px, inchangée visuellement) tandis que les sections Demandes/Signalements, Démarches et Rendez-vous occupent désormais tout l'espace restant de l'écran, quelle que soit sa largeur.

## 0.18.2 — Fiche citoyen : retour à la mise en page en deux colonnes

- Retour à la disposition précédente : Coordonnées et Résumé à gauche (colonne étroite), Demandes/Signalements, Démarches et Rendez-vous à droite (colonne large), le tout aligné côte à côte.

## 0.18.1 — Fiche citoyen : sections en pleine largeur

- Réorganisation de la fiche citoyen : les cartes Coordonnées et Résumé passent en haut de page en format compact côte à côte, tandis que Demandes/Signalements, Démarches et Rendez-vous s'affichent désormais en **pleine largeur**, empilées verticalement — plus de place pour lire les tableaux confortablement.

## 0.18.0 — Numéros de dossier (démarches/RDV) + citoyen visible partout

- **Démarches** et **rendez-vous** disposent désormais chacun d'un numéro unique lisible (`DEM-2026-XXXXXX`, `RDV-2026-XXXXXX`), à l'image du `numero_suivi` des signalements — visible côté admin (listes, détail) et côté citoyen (`[grc_mes_demandes]`)
- La liste des dossiers de démarches en admin affiche désormais le nom et le numéro citoyen (lien direct vers la fiche), en plus du numéro de dossier
- La liste des rendez-vous en admin affiche le numéro de RDV
- La fiche citoyen (**GRC Citoyenne → Citoyens**) affiche désormais le numéro de dossier/RDV dans ses tableaux Démarches et Rendez-vous

## 0.17.0 — Numéro citoyen unique + tableau de bord citoyen

### Numéro citoyen unique
- Chaque citoyen dispose désormais d'un numéro lisible (`CIT-000042`), basé sur son identifiant unique en base — aucune migration nécessaire
- Affiché partout où un citoyen apparaît en administration : listes des demandes, démarches, rendez-vous, et vues détail
- Permet de distinguer sans ambiguïté des homonymes

### Tableau de bord citoyen (nouvel écran "Citoyens")
- Liste de tous les citoyens (numéro, nom, email, type de compte, date d'inscription), avec recherche par numéro ou email exact (la recherche par nom n'est pas possible : les noms sont chiffrés en base, principe de sécurité du plugin)
- **Fiche complète par citoyen** : coordonnées, type de compte, consentement RGPD, et surtout l'historique complet de toutes ses demandes, démarches et rendez-vous, avec liens directs vers chaque dossier
- Accessible en un clic depuis n'importe quelle liste ou vue détail (demande, démarche, rendez-vous) via le nom ou le lien "Voir la fiche complète"

## 0.16.0 — Workflow de validation des rendez-vous

- Les rendez-vous ne sont plus confirmés automatiquement : ils sont créés avec le statut **"En attente"** et nécessitent une validation manuelle par un agent
- Nouveaux statuts : `en_attente`, `refuse` (en plus de `confirme` et `annule`)
- Admin (**GRC Citoyenne → Rendez-vous**) : boutons **Valider** / **Refuser** sur les rendez-vous en attente ; le refus libère automatiquement le créneau
- **Refus automatique paramétrable** : passé un délai configurable (**GRC Citoyenne → Réglages**, 48h par défaut), une demande non traitée est automatiquement refusée par un cron horaire, avec notification au citoyen
- Emails adaptés : accusé de réception "en attente de validation" à la demande, email de confirmation à la validation, email d'information (manuel ou automatique) en cas de refus
- Le citoyen peut annuler une demande aussi bien en attente que confirmée depuis `[grc_mes_demandes]`

## 0.15.3 — Correctif : comparaison stricte float/int filtrait tous les créneaux

- `round()` en PHP retourne toujours un nombre à virgule flottante (`30.0`), comparé en strict (`!==`) à la durée demandée (entier `30`) — cette comparaison échouait systématiquement même quand les valeurs étaient "égales", filtrant silencieusement **tous** les créneaux quel que soit le paramètre `duree` envoyé. C'est ce qui causait un calendrier toujours vide malgré des créneaux bien générés et un sélecteur de durée correctement détecté.
- Correction : la durée calculée est désormais explicitement castée en entier avant comparaison.

## 0.15.2 — Correctif durée : sélecteur dynamique au lieu de 30/60 fixes

- Cause du calendrier vide identifiée : le sélecteur citoyen proposait uniquement "30 min" et "1h" en dur, alors que la durée réellement configurée en admin peut être différente (15, 45 min...) — aucun des deux boutons ne correspondait, filtrant tous les créneaux
- Nouvel endpoint `GET /rdv/durees` : retourne les durées réellement configurées pour un service (issues du modèle hebdomadaire)
- Le sélecteur de durée sur `[grc_rdv_form]` se construit désormais dynamiquement à partir de ces vraies durées ; s'il n'y a qu'une seule durée configurée pour le service, le sélecteur est même masqué (inutile de choisir s'il n'y a qu'une option)

## 0.15.1 — Correctif cache calendrier + confirmation visuelle admin

- Ajout d'un paramètre anti-cache sur la requête de chargement du calendrier citoyen (`/rdv/creneaux`), ce site ayant déjà rencontré plusieurs fois des soucis de mise en cache masquant des données à jour
- L'onglet **Disponibilités** affiche désormais directement un compteur "X créneaux générés pour ce service" (et déclenche la génération à l'affichage de la page, sans attendre la première visite citoyenne), pour vérifier immédiatement que les horaires enregistrés produisent bien des créneaux

## 0.15.0 — Refonte de la gestion des disponibilités (horaires + absences)

### Le problème résolu
La génération manuelle de créneaux produisait des dizaines de lignes individuelles à gérer une par une en administration — inutilisable en pratique.

### Nouvelle approche : modèle hebdomadaire + génération automatique invisible
- Nouvel onglet **Disponibilités** (remplace "Créneaux") : un tableau de 7 lignes (Lundi à Dimanche), chacune avec heure de début, heure de fin, pause méridienne (facultative), durée de créneau et capacité — **une seule sauvegarde pour toute la semaine**
- Les créneaux individuels ne sont plus jamais visibles ni gérés à la main : ils sont **générés automatiquement en arrière-plan** (`GRC_Creneaux_Generator`) à partir de ce modèle hebdomadaire, à la demande (quand un citoyen consulte le calendrier) et via une tâche quotidienne qui maintient une fenêtre glissante de 90 jours
- Modifier les horaires d'un service supprime automatiquement les créneaux futurs **non réservés** et les régénère selon le nouveau modèle (les créneaux déjà réservés ne sont jamais touchés automatiquement)

### Gestion des absences
- Nouvelle table `wp_grc_absences` : bloque une période pour un service précis ou pour toute la mairie (congés, formation, fermeture exceptionnelle...)
- Déclarer une absence supprime automatiquement les créneaux non réservés de la période ; si des rendez-vous étaient déjà confirmés sur cette période, un avertissement les signale pour une annulation manuelle (avec information du citoyen)

### Nouvelles tables
- `wp_grc_disponibilites` : modèle horaire hebdomadaire par service et jour de semaine
- `wp_grc_absences` : périodes bloquées

## 0.14.0 — Prise de rendez-vous : calendrier coloré + choix de durée

- `[grc_rdv_form]` remplace la liste de créneaux plate par un **vrai calendrier mensuel** avec code couleur par jour :
  - 🟢 vert : places disponibles
  - 🟠 orange : dernières places (≤2 ou ≤20% de la capacité du jour)
  - ⬜ gris : complet ou aucun créneau
  - Navigation mois précédent/suivant
- Sélecteur de **durée** (30 min / 1h) au-dessus du calendrier : ne montre que les créneaux correspondants
- `GET /rdv/creneaux` retourne désormais tout le mois demandé (paramètres `mois` et `duree`), y compris les créneaux complets, nécessaire pour colorer le calendrier — les créneaux passés restent exclus
- Nouvel endpoint `GET /rdv/disponibilites` : agrégation serveur par jour (total/restantes/statut) pour un service, une durée et un mois donnés — alternative plus légère à l'agrégation actuellement faite côté client, utile pour une future app mobile
- Note ajoutée dans l'admin : générer des créneaux de 30 min et 1h sur les **mêmes plages horaires** créerait un risque de double réservation (les créneaux ne sont pas liés entre eux) ; à générer sur des plages distinctes

## 0.13.0 — Module Rendez-vous complet

### Correctif
- L'endpoint de réservation utilisait `is_user_logged_in()` (WordPress), incompatible avec les comptes citoyens indépendants introduits en v0.4.0 — corrigé pour utiliser l'authentification citoyenne JWT, avec support du mode invité comme les autres modules.

### API REST
- `POST /rdv` : réservation par citoyen connecté ou invité (email)
- `GET /mes-rdv` : liste des rendez-vous du citoyen connecté
- `POST /rdv/{id}/annuler` : annulation par le citoyen propriétaire ou un agent (libère automatiquement la place sur le créneau)

### Administration
- Nouvel écran **Rendez-vous** à deux onglets :
  - **Rendez-vous** : liste avec filtres (service, statut), annulation
  - **Créneaux** : génération récurrente (période, jours de la semaine, plage horaire, durée, capacité) et liste des créneaux à venir par service (suppression si non réservé)

### Portail citoyen
- Nouveau shortcode `[grc_rdv_form]` : sélection du service → grille de créneaux disponibles → motif → confirmation (compte connecté ou invité)
- Section "Mes rendez-vous" ajoutée à `[grc_mes_demandes]`, avec possibilité d'annuler un rendez-vous à venir

### Notifications
- Email de confirmation à la réservation
- Rappel automatique par email la veille du rendez-vous (cron quotidien)

## 0.12.1 — Réordonnancement des champs

- Boutons ▲/▼ sur chaque champ du constructeur pour changer leur ordre d'affichage dans le formulaire citoyen (l'ordre visuel est directement celui enregistré, aucune action supplémentaire nécessaire)

## 0.12.0 — Nouveaux types de champs + réorganisation des écrans admin

### Nouveaux types de champs
- **Date** : sélecteur de date natif, validé au format `AAAA-MM-JJ` côté serveur
- **Téléphone** : sélecteur de pays avec drapeau + indicatif (France `+33` par défaut, 19 pays courants disponibles), combiné en un numéro international validé côté serveur (format général type E.164)

### Réorganisation des écrans admin
- **Types de démarches** (nouveau sous-menu séparé) : liste des types (nom, slug, nombre de champs, actif) avec bouton "Ajouter un type" ; le constructeur de champs visuel se trouve désormais sur son propre écran d'édition, accessible via "Modifier"
- **Démarches** (sous-menu existant, refondu) : écran dédié aux dossiers soumis avec :
  - **Filtres** : type de démarche, statut, plage de dates
  - **Reporting** : total filtré, répartition par statut, répartition par type
  - **Pagination** (25 dossiers par page)

## 0.11.0 — Constructeur de champs visuel pour les démarches

- La configuration des champs d'un type de démarche ne nécessite plus d'écrire du JSON à la main : un constructeur visuel permet d'ajouter/supprimer des champs, choisir leur libellé, leur type (texte court, texte long, email, nombre, fichier PDF/.docx) et s'ils sont obligatoires
- La clé technique de chaque champ se génère automatiquement à partir du libellé (modifiable manuellement si besoin)
- Le JSON est généré automatiquement en arrière-plan au moment de l'enregistrement — la structure de données (`champs_json`) et l'API restent identiques, seule l'interface change
- La page **Démarches** affiche désormais chaque type sous forme de carte plutôt qu'un tableau compact, plus lisible avec le constructeur intégré

## 0.10.2 — Correctif téléchargement des pièces jointes (403)

- Un lien `<a href>` classique ne peut transporter ni l'en-tête `Authorization` (JWT citoyen) ni le nonce REST (auth cookie WordPress), ce qui provoquait un `403 grc_forbidden` systématique au clic sur un document, aussi bien en administration que côté citoyen.
- **Côté admin** : les liens de téléchargement passent désormais par `admin-post.php` (authentification cookie WordPress native, hors API REST), avec vérification de capacité et nonce dédié.
- **Côté citoyen** : le JWT peut désormais être transmis en paramètre d'URL (`?token=...`) en plus de l'en-tête `Authorization`, spécifiquement pour ce cas où un lien classique est nécessaire (ouverture dans un nouvel onglet).

## 0.10.1 — Correctif compatibilité PHP 8.5 (finfo_close déprécié)

- `finfo_close()` est dépréciée depuis PHP 8.5 (les objets `finfo` sont désormais libérés automatiquement). Sur un serveur avec l'affichage des erreurs actif, cet appel produisait un warning affiché en clair **avant** la réponse JSON de l'API, cassant son parsing côté client (`Unexpected token '<'... is not valid JSON`) — alors même que l'opération (upload de fichier) réussissait réellement en arrière-plan.
- L'appel a été retiré (sans danger sur les versions antérieures de PHP : la ressource est de toute façon libérée par le ramasse-miettes).

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
