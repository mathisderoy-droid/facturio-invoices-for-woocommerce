# Architectural Decisions & Critical Review

Carnet de décisions d'architecture et points de vigilance soulevés en cours
de route. Chaque entrée précise le contexte, la décision prise, et le statut.

---

## 27 mai 2026 — Revue critique fin d'étape 3

Revue critique du prompt initial après avoir livré les étapes 1 à 3. Sept
points soulevés et arbitrés avec Mathis.

### 1. Risque de conflits `vendor/` avec d'autres plugins

**Contexte.** Le prompt prévoit d'embarquer `vendor/` directement dans le
zip de release. TCPDF est utilisé par énormément d'autres plugins
(Yoast Premium, WP Mail SMTP Pro, plusieurs plugins de facturation, etc.).
Si un de ces plugins charge sa propre version de TCPDF avant nous,
notre version ne sera pas chargée (PHP refuse de re-déclarer une classe),
et notre code plantera avec des erreurs `Cannot redeclare class TCPDF` ou
des comportements inattendus dus à un mismatch de version.

**Décision.** Avant la soumission à WordPress.org, faire passer toutes les
dépendances Composer dans **Strauss** (https://github.com/BrianHenryIE/strauss),
qui les renomme automatiquement vers le namespace `Mathis\FacturX\Vendor\`.
Aucune collision possible après ça.

**Statut.** ⏸ Reporté à l'étape 8.
**Échéance impérative.** Avant tout `git tag v0.1.0` et soumission WP.org.

---

### 2. Choix de la lib XML Factur-X — incohérence à trancher

**Contexte.** Le prompt impose `atgp/factur-x` (section 7). Le doc de
validation (`Validation-Factur-X-WooCommerce.md`) recommande
`horstoeko/zugferd`. Les deux fonctionnent pour le profil EN 16931 de
V0.1, mais :

- `atgp/factur-x` : focalisée FR, mature, MIT, 758k+ installs Packagist.
  Couvre EN 16931 mais pas le profil EXTENDED.
- `horstoeko/zugferd` : couvre tous les profils MINIMUM → EXTENDED.
  API plus riche. Aussi MIT, actif.

**Le piège.** La V0.5 Pro nécessitera le profil EXTENDED-CTC-FR pour le
routage vers les Plateformes Agréées. Si on démarre sur `atgp/factur-x`,
on devra réécrire la couche XML à l'étape V0.5. Si on démarre sur
`horstoeko/zugferd`, pas de migration.

**Décision (27 mai 2026).** Switch sur `horstoeko/zugferd v1.0.122`.
Raison principale : `atgp/factur-x` ne fournit aucune API de construction
du XML CII (seulement embed + validation), il aurait fallu écrire ~500
lignes de DOMDocument à la main. `horstoeko/zugferd` fournit un builder
fluent qui couvre les profils MINIMUM → EXTENDED, ce qui couvre aussi
nos besoins V0.5 (EXTENDED-CTC-FR pour routage PA) sans migration.

**Effet de bord.** Le dependency tree passe de 6 packages (atgp + TCPDF
+ FPDI + FPDF + pdfparser + polyfill) à 26 packages (ajout de
symfony/validator, jms/serializer, doctrine/{deprecations,instantiator,
lexer}, phpstan/phpdoc-parser, et 8 polyfills Symfony). Ces packages
sont très utilisés par d'autres plugins WP — augmente significativement
le risque de collision (cf. point #1) et confirme l'urgence de Strauss
avant soumission WP.org.

**Statut.** ✅ Fait le 27 mai 2026 (commit à venir).

---

### 3. Aucun test automatisé pour la numérotation atomique

**Contexte.** `InvoiceNumbering::get_next_invoice_number()` (étape 3)
repose sur un verrou MySQL InnoDB et la mécanique `LAST_INSERT_ID()` de
session pour garantir l'absence de doublon sous charge. C'est correct
théoriquement, mais la seule façon de le **vérifier** sous charge réelle
est un test de concurrence (50 requêtes en parallèle, vérifier que les
50 numéros sont uniques et continus).

Le prompt ne prévoit que des tests manuels et le Plugin Check Plugin.
Pour du code aussi sensible légalement (la loi française interdit les
doublons et les trous), c'est insuffisant.

**Décision.** Ajouter PHPUnit + WP-Brain-Monkey + un script de stress
test concurrentiel. ~2 h de boulot.

**Statut.** ⏸ Reporté à l'étape 8.
**Échéance idéale.** Étape 8, avant `git tag v0.1.0`.

---

### 4. WP_DEBUG / WP_DEBUG_LOG non activés

**Contexte.** Le prompt (section 8.3) demande d'activer le mode debug
WordPress. On l'avait sauté. Conséquence : quand le shell Site Shell a
émis "critical error" à l'étape 3, on n'avait aucun moyen de voir la
vraie erreur PHP sans aller fouiller dans les logs.

**Décision.** Activer immédiatement dans `wp-config.php` :

```php
WP_DEBUG = true;
WP_DEBUG_LOG = true;        // Erreurs dans wp-content/debug.log
WP_DEBUG_DISPLAY = false;   // Pas d'affichage inline (risque AJAX/JSON)
SCRIPT_DEBUG = true;        // Charge les CSS/JS non-minifiés
```

**Statut.** ✅ Fait le 27 mai 2026.

**Conséquence pratique.** Pour voir les erreurs : ouvrir
`C:\Users\mathis.deroy\Local Sites\factur-x-dev\app\public\wp-content\debug.log`
(le fichier est créé automatiquement à la première erreur).

---

### 5. Validation SIRET (Luhn) et TVA (format) absente dans Settings

**Contexte.** À l'étape 2, les sanitizers serveur retirent les espaces
et mettent en majuscules, mais ne valident pas la structure logique :

- Un SIRET de 14 chiffres a une clé de contrôle Luhn (modulo 10) — on
  peut vérifier localement sans appel API que les 14 chiffres saisis
  sont mathématiquement cohérents.
- Un numéro TVA FR a un format précis : `FR` + 2 chiffres de clé + 9
  chiffres SIREN, avec une formule de calcul de la clé.

Côté UX, on appellera de toute façon les API INSEE/VIES à l'étape 4,
qui rejetteront les saisies invalides. Mais une validation locale
préalable évite un appel réseau pour rien et améliore l'UX.

**Décision.** Glisser cette validation dans l'étape 4, dans
`class-siret-validator.php` et `class-vies-validator.php`, avec des
méthodes statiques `is_valid_siret_format($siret)` et
`is_valid_french_vat_format($vat)` appelées avant l'API.

**Statut.** ⏸ Intégré au scope étape 4.

---

### 6. Clé API INSEE Sirene à demander

**Contexte.** Le prompt mentionne "clé API à demander gratuitement sur
api.insee.fr". En vrai, ça demande : créer un compte, valider l'email,
créer une "application" dans le portail INSEE, générer une clé.
Plusieurs heures à plusieurs jours selon la rapidité de validation INSEE.

**Décision.** Mathis lance cette démarche **avant** qu'on attaque
l'étape 4, en parallèle du dev.

URL d'inscription : https://portail-api.insee.fr/

**Statut.** 🔄 À faire par Mathis avant étape 4.

---

### 7. Timeline : 5 semaines pour publication WP.org

**Contexte.** On est le 27 mai 2026. Cible publication WP.org : fin
juin 2026 pour ranker avant la vague de panique réception obligatoire
(1er septembre 2026). Soit ~5 semaines.

À notre rythme (3 étapes en quelques heures), on est dans les temps,
**mais les étapes 4 et 5 sont les deux plus lourdes du projet** :
- Étape 4 : 4 classes (checkout fields, SIRET validator, VIES validator,
  order meta) + JS + appels API + sanitization.
- Étape 5 : 4 classes (PDF renderer, XML builder, invoice generator,
  template) + intégration Factur-X + validation conformité.

**Décision.** Maintenir la cadence. Ne pas dériver hors du périmètre V0.1
strict (section 4 du prompt). Toutes les features "nice to have" partent
dans `BACKLOG.md` pour V0.5+.

**Statut.** 🔄 Suivi en continu.

---

## 27 mai 2026 — Idée Pro à creuser : proxy INSEE hébergé

**Contexte.** En V0.1 chaque marchand installant le plugin doit créer
sa propre clé INSEE Sirene (limite 30 req/min/app, T&Cs INSEE imposent
une app par entité, on ne peut pas partager notre clé entre 1000 users).
C'est une friction d'onboarding réelle : 10-30 min de démarche, parfois
1-2 jours d'attente de validation INSEE.

**Idée monétisation V0.5 Pro.** Héberger nous-mêmes un proxy léger
(VPS à 5-10 €/mois) avec NOTRE clé INSEE maître, validée par INSEE
pour usage centralisé d'éditeur de plugin. Le marchand paye le plan
Pro (149 €/an), reçoit une clé interne de notre proxy, colle ça dans
le plugin, et la validation SIRET marche immédiatement sans démarche
INSEE.

**Argument business.** Beaucoup de marchands/agences paieraient les
149 € rien que pour zapper la paperasse INSEE. C'est exactement le
type de friction qu'on convertit en upsell. À évaluer côté coût
(VPS + bande passante + monitoring + support) vs revenu marginal.

**Risque.** INSEE pourrait considérer qu'on revend leur API. À clarifier
avec eux avant de lancer (programme "éditeur agréé" ou similaire).

**Statut.** 💡 Idée à approfondir avant le lancement V0.5 Pro.

---

## 28 mai 2026 — Apprentissages de l'étape 4B

Trois enseignements importants à conserver pour la suite :

### A. INSEE API : auth NON-standard sur le plan "Simple"

Diagnostic empirique : l'application "Simple" du nouveau portail
`portail-api.insee.fr` accepte UNIQUEMENT le header
`X-INSEE-Api-Key-Integration: <key>` sur l'endpoint
`https://api.insee.fr/api-sirene/3.11/siret/{siret}`. Bearer JWT et
`X-INSEE-Api-Key` (sans `-Integration`) retournent tous les deux 401.
L'ancien endpoint `entreprises/sirene/V3.11/` est deprecated (renvoie
un message "url deprecated").

Notre `SiretValidator::do_request()` essaie le header `Integration`
d'abord, puis Bearer en fallback (au cas où certains utilisateurs
auraient un plan Premium OAuth2). Ne pas inverser cet ordre.

### B. VIES : SOAP fault à détecter sur HTTP 200

Le service VIES est très fragile : il peut renvoyer un SOAP Fault
(`MS_MAX_CONCURRENT_REQ`, `MS_UNAVAILABLE`, `GLOBAL_MAX_CONCURRENT_REQ`, etc.)
**avec un HTTP 200**, pas 500. Notre `ViesValidator::detect_soap_fault()`
inspecte le body avant tout autre check, traduit les codes connus en
état "unavailable" (warning orange UX), et laisse passer le checkout
sans bloquer parce que la validation de format locale est suffisante.

Conséquence prod : ne JAMAIS bloquer une commande sur un échec VIES.
Le format local valide + le SIRET INSEE validé suffisent. VIES est un
"nice to have" d'enrichissement, pas une dépendance critique.

### C. Zscaler (corporate VPN avec SSL interception)

Mathis a un Zscaler installé sur son poste VINCI Energies. Ce type de
VPN fait du MITM sur HTTPS, ce qui casse la vérification de
certificats. À couper quand on teste les appels API tiers en local
sinon erreurs `cURL error 60`. Aucun impact sur l'environnement de
production (les serveurs des marchands ne sont pas derrière des
firewalls SSL-inspectés).

### D. À vérifier plus tard

VIES happy-path (résultat ✓ vert "TVA valide") n'a pas pu être validé
en live au moment du test parce que VIES était en rate-limit. À
retester à un autre moment de la journée pour confirmer que le parsing
des réponses correctes fonctionne bien. Format de réponse attendu :
SOAP avec `<valid>true</valid>` + `<name>...</name>` + `<address>...</address>`,
potentiellement avec préfixes de namespace (`ns2:`, `vies:`, etc.) qui
sont déjà gérés par les regex namespace-tolerantes.

---

## Légende des statuts

- ✅ **Fait** — décision implémentée
- 🔄 **En cours / À faire** — action en cours, échéance proche
- ⏸ **Reporté** — décision prise, exécution programmée plus tard
- ❌ **Abandonné** — décision annulée (raison documentée)
- 💡 **Idée** — piste à creuser, pas encore décidée
