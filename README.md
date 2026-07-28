# Gestion de la Relation Citoyenne (GRC) — Mairie de Berre-les-Alpes

Plugin WordPress de gestion de la relation citoyenne : signalements, demandes, rendez-vous, démarches administratives, avec API REST pour application mobile (Android/iOS).

## Prérequis

- WordPress 7.0+
- PHP 8.1+ (testé sur PHP 8.5)
- Extension `sodium` (incluse nativement en PHP 7.2+)
- MySQL/MariaDB

## Installation

1. Copier le dossier `gestion-relation-citoyenne` dans `wp-content/plugins/`.
2. **Avant d'activer**, ajouter dans `wp-config.php` — **impérativement avant** la ligne `require_once(ABSPATH . 'wp-settings.php');` (donc avant le commentaire "That's all, stop editing!") :

```php
define( 'GRC_ENCRYPTION_KEY', 'CLE_GENEREE_EN_BASE64' );
define( 'GRC_JWT_SECRET', 'CHAINE_ALEATOIRE_LONGUE' );
```

⚠️ **Si ces lignes sont placées après `require_once wp-settings.php`, le plugin échoue silencieusement** (aucun shortcode, aucun menu admin, aucune erreur PHP) car les plugins se chargent avant cette ligne. Le plugin détecte ce cas précis et affiche un message d'erreur explicite en haut de l'administration.

Pour générer une clé de chiffrement valide :

```php
echo base64_encode( random_bytes( 32 ) );
```

3. Activer le plugin depuis l'administration WordPress. Les tables sont créées automatiquement.

## Sécurité

- Toutes les données personnelles (nom, prénom, email, téléphone) sont chiffrées en base via `libsodium` (XChaCha20-Poly1305).
- Un hash HMAC-SHA256 est stocké en parallèle de l'email/téléphone pour permettre la recherche sans déchiffrement de masse.
- Journalisation (audit log) de tous les accès/modifications sur les fiches citoyennes.
- Purge RGPD automatique (anonymisation) configurable, 3 ans par défaut après clôture d'une demande.
- Rate limiting sur les endpoints publics de l'API REST.
- **`GRC_ENCRYPTION_KEY` et `GRC_JWT_SECRET` ne doivent jamais être committées sur GitHub.**

## API REST

Namespace : `/wp-json/grc/v1/`

| Endpoint | Méthode | Auth | Description |
|---|---|---|---|
| `/auth/login` | POST | Non | Connexion **agent/élu/admin** (comptes WordPress), retourne access_token (JWT type=agent) + refresh_token |
| `/auth/refresh` | POST | Non | Renouvelle l'access_token agent |
| `/auth/logout` | POST | JWT agent | Révoque le refresh token agent |
| `/citoyen/register` | POST | Non | Crée un compte citoyen (indépendant de wp_users) |
| `/citoyen/login` | POST | Non | Connexion citoyen, retourne access_token (JWT type=citoyen) + refresh_token |
| `/citoyen/refresh` | POST | Non | Renouvelle l'access_token citoyen |
| `/citoyen/me` | GET | JWT citoyen | Infos du citoyen connecté |
| `/demandes/public-submit` | POST | Non (JWT citoyen optionnel) | Créer un signalement (compte citoyen ou invité) |
| `/demandes/guest-lookup` | POST | Non | Suivre une demande en mode invité (numéro + email) |
| `/demandes` | GET | JWT agent | Liste des demandes (agents/élus) |
| `/demandes/{id}` | GET | JWT | Détail d'une demande |
| `/demandes/{id}/statut` | POST | JWT agent | Changer le statut d'une demande |
| `/mes-demandes` | GET | JWT citoyen | Demandes du citoyen connecté |
| `/rdv/creneaux` | GET | Non | Liste des créneaux disponibles |
| `/rdv` | POST | JWT | Réserver un rendez-vous |
| `/demandes/{id}/pieces-jointes` | POST | Selon contexte | Uploader une pièce jointe (agent, citoyen connecté ou invité avec email) |
| `/pieces-jointes/{id}` | GET | Selon contexte | Télécharger une pièce jointe (autorisation vérifiée) |
| `/demarches/types` | GET | Non | Liste des types de démarches actifs et leurs champs |
| `/demarches` | POST | Non (JWT citoyen optionnel) | Soumettre un dossier de démarche |
| `/mes-demarches` | GET | JWT citoyen | Dossiers de démarches du citoyen connecté |
| `/demarches/{id}/statut` | POST | JWT agent | Changer le statut d'un dossier |
| `/demandes/{id}/satisfaction` | POST | Selon contexte | Noter une demande résolue (1-5 + commentaire) |
| `/satisfaction/stats` | GET | JWT agent | Moyenne et répartition des notes de satisfaction |

**Important** : les comptes **agents/élus/admin** (staff municipal) utilisent les comptes WordPress natifs (`wp_users`) via `/auth/*`. Les comptes **citoyens** sont entièrement indépendants (table `wp_grc_citoyens`, mot de passe hashé séparément) via `/citoyen/*`. Les deux émettent des JWT mais avec un claim `type` différent (`agent` vs `citoyen`), qui détermine comment chaque token est traité par le middleware d'authentification — un token citoyen ne peut jamais s'authentifier comme un utilisateur WordPress.

## Portail citoyen front-office

Deux shortcodes disponibles pour les pages du site :

- `[grc_signalement_form]` — formulaire public de signalement (compte connecté ou invité)
- `[grc_mes_demandes]` — suivi des demandes (liste automatique si connecté, recherche par numéro + email sinon)

## Déploiement / Versioning

Mise à jour automatique via Plugin Update Checker, sur le même modèle que le plugin Simple Page Builder :

1. Push du code sur la branche `main` (Code tab GitHub).
2. Création d'une Release GitHub taguée (ex : `v0.2.0`) → déclenche l'auto-update sur les instances WordPress.

## État d'avancement

Voir `CHANGELOG.md` pour le détail des modules implémentés vs à venir.
