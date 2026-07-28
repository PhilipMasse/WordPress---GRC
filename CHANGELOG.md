# Changelog

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
