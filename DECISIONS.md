# Architectural Decisions & Critical Review

Carnet de décisions d'architecture et points de vigilance soulevés en cours
de route. Chaque entrée précise le contexte, la décision prise, et le statut.

---

## 🧭 ÉTAT ACTUEL (fin de session 29 mai 2026) — lire en premier

**V0.1 développée, testée, taguée `v0.1.0`, sur GitHub avec build CI validé.**

- **Code** : étapes 1→8 du prompt terminées. PHPCS zéro violation, PHPUnit vert
  (15 tests + test de concurrence numérotation 720 nº). Plugin fonctionnel
  bout-en-bout, vérifié manuellement jusqu'à l'email.
- **Dépôt GitHub privé** : `mathisderoy-droid/factur-x-for-woocommerce`.
  Branche `main`, tag `v0.1.0`. Auth via `gh` (compte mathisderoy-droid).
- **Build du zip WP.org** : GitHub Actions (`.github/workflows/build.yml`)
  lance `bin/build.sh` sur Linux (Strauss scope les deps sous
  `Mathis\FacturX\Vendor\`). Déclencheur : push d'un tag `vX.Y.Z` OU
  `gh workflow run build.yml --ref main`. Le zip sort en artefact.
  **Build validé** (structure + autoloading scopé OK). Zip v0.1.0 dans Downloads.
  Strauss NE TOURNE PAS sur Windows → toujours builder via CI/Linux.
  ⚠️ TOUJOURS tester le ZIP packagé lui-même (pas seulement le dev) : un bug de
  scoping (cf. YAML zugferd, 1er juin) n'apparaît QUE dans le zip scopé.
- **QA TERMINÉE — 13/13 scénarios validés** (voir tests/QA-checklist.md) :
  multi-taux, remise/coupon, commande à 0 € ET facture B2C → tous **Fully Valid
  FNFE-MPE** ; SIRET/TVA invalides → blocage checkout OK ; désactiver/réactiver
  et désinstallation OK. **5 bugs/défauts corrigés par la QA** (3 conformité TVA,
  1 UX VIES, 1 uninstall) + **ajout d'une fonctionnalité** : champ « Prochain
  numéro de facture » éditable (reprise de série / migration, sécurité anti-retour).
  PHPUnit 23 verts, PHPCS 0. Reste pour être EN LIGNE : screenshots + soumission WP.org.

**Reste pour être EN LIGNE** : soumettre le zip (3,8 Mo) → review ~2-3 sem.
Compte WP.org créé : identifiant **mathisdd** (= champ Contributors du readme ;
c'est l'identifiant perso de Mathis pour tout). Screenshots à ajouter via SVN
après approbation (ils ne bloquent pas le démarrage de la revue).

**Environnement (chemins)** :
- PHP CLI : `C:\Users\mathis.deroy\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`
- wp-cli : `...\AppData\Local\Programs\Local\resources\extraResources\bin\wp-cli\wp-cli.phar` (DB injoignable hors Site Shell → utiliser le Site Shell de Local)
- composer : `C:\ProgramData\ComposerSetup\bin\composer.bat`
- gh : `C:\Program Files\GitHub CLI\gh.exe`
- PHPCS : `php vendor/bin/phpcs` (doit rester à zéro). PHPUnit : `php vendor/bin/phpunit`.
- mysqli direct (pour scripts hors WP) : 127.0.0.1:10006 root/root/local.
- **Zscaler à couper** pour tout appel INSEE/VIES.

**Prochaines grosses tâches (tasks)** : #15 publication WP.org, #14 refacto
Core/Adapter+DTO en V0.5.

---

## 1er juin 2026 — Bug CRITIQUE de packaging : YAML zugferd non scopés

Test du ZIP packagé sur le site (pas le dev) → la génération de facture échoue :
« Expected metadata for class Mathis\FacturX\Vendor\horstoeko\zugferd\…\
CrossIndustryInvoiceType to be defined in …/CrossIndustryInvoiceType.yml ».

Cause : horstoeko/zugferd embarque 267 fichiers `*.yml` (lus à l'exécution par
jms/serializer pour mapper classe → XML). Strauss réécrit les classes PHP mais
PAS le contenu des `.yml` → après scoping, le code cherche la classe préfixée
`Mathis\FacturX\Vendor\horstoeko\zugferd\…` alors que le YAML dit encore
`horstoeko\zugferd\…`. Résultat : **le plugin packagé ne génère AUCUNE facture**
(invisible en dev, qui utilise le vendor/ non scopé). Le 1er zip soumis aurait
été totalement cassé — attrapé par le test du zip réel (d'où la règle ci-dessus).

Correctif : script `bin/fix-zugferd-yaml-namespace.py` (idempotent, + mode
`--check`) qui préfixe la chaîne dans les 267 YAML, branché dans `bin/build.sh`
juste après Strauss (write puis check qui fait échouer le build si un ref reste).
`bin/` est exclu du zip → seul l'EFFET (YAML corrigés) est livré, pas le script.
Vérifié sur les 267 fichiers réels (write → 267 réécrits ; check → 0 restant).

---

## 30 mai 2026 — Packaging : zip réduit de 21 Mo à 3,8 Mo

Le 1er build complet du zip pesait **21 Mo** (52 Mo décompressé) — trop lourd
(la limite d'upload WP.org tourne autour de 10 Mo, et c'est peu pro). Cause :
TCPDF embarque ~25 Mo de polices (DejaVu, FreeFont, CID CJK) dont on n'utilise
AUCUNE — le plugin n'emploie que `helvetica` (rendue en `pdfahelvetica`, sa
variante embarquable en mode PDF/A). S'ajoutaient les `tests/` des dépendances.

Décision : `bin/build.sh` élague désormais, après Strauss :
  - les polices TCPDF, sauf les core base-14 + toutes les `pdfa*` (704 Ko au
    lieu de 25 Mo) ;
  - les dossiers `tests/` / `docs/` / `examples/` des dépendances.
Résultat mesuré localement : **zip 3,81 Mo**. Aucune incidence sur la sortie
(la police réellement utilisée est conservée). À re-valider sur FNFE-MPE après
le build CI, par sécurité.

---

## 30 mai 2026 — Fonctionnalité : « Prochain numéro de facture » éditable

Ajout (demandé par Mathis) d'un champ dans Réglages → Facturation pour voir ET
fixer le prochain numéro de facture. Besoin réel : un commerçant qui adopte le
plugin en cours d'année et a déjà émis des factures (autre outil) doit pouvoir
reprendre sa série (ex. « prochain = 248 ») au lieu de repartir à 1.

Champ « virtuel » : il n'est PAS stocké comme option propre. Son handler de
sauvegarde (filtre `woocommerce_admin_settings_sanitize_option_*`) écrit le vrai
compteur (par année) puis retourne `null` → WC ne persiste rien pour ce champ.
La valeur affichée est toujours recalculée (compteur courant + 1).

Sécurité « jamais en arrière » à deux niveaux :
  1. Garde-fou applicatif : refuse un numéro ≤ dernier émis, message clair
     (`is_acceptable_next_number()`, testé unitairement).
  2. Écriture SQL atomique conditionnelle (`... WHERE CAST(option_value) <= n`)
     qui n'abaisse JAMAIS le compteur — protège même contre la course où une
     commande consomme un numéro pile pendant la sauvegarde des réglages.
Cohérent avec la numérotation atomique existante (LAST_INSERT_ID). Testé en réel
(refus du recul + acceptation + génération conforme). PHPUnit 23 verts, PHPCS 0.

---

## 30 mai 2026 — QA : désinstallation revue (préserver l'archive de factures)

Scénario 13 (désinstallation). Revue de code de `uninstall.php` (on ne fait PAS
un vrai « Supprimer » depuis l'admin : le dossier du plugin **est** le dépôt
Git, WordPress le supprimerait). Deux problèmes trouvés :

1. **Aucune meta n'était supprimée.** Le code cherchait `LIKE 'mathisfx\_%'`
   alors que TOUTES les meta du plugin sont préfixées `_mathisfx_` (underscore
   devant, convention WC). Les requêtes ne matchaient donc rien.
2. **Le compteur de numéros était effacé.** `mathisfx_invoice_counter` était
   dans la liste des options supprimées → après désinstallation/réinstallation,
   la numérotation repartait à 0 et **réémettait des numéros déjà utilisés**
   (interdit pour une facturation légale séquentielle).

Décision (validée avec Mathis via AskUserQuestion) : **préserver l'archive**.
La désinstallation efface UNIQUEMENT les réglages (identité vendeur, clé INSEE,
format, apparence) + les caches (transients INSEE/VIES). Elle CONSERVE :
les PDF (documents légaux, ~10 ans de conservation en France), les posts CPT
`mathisfx_invoice` + leurs meta (piste d'audit), les meta B2B des commandes, et
le compteur (pour que la numérotation ne collisionne jamais après réinstall).
Alternative « nettoyage complet » écartée (risque de doublons de numéros).

`uninstall.php` réécrit en ce sens (PHPCS 0, php -l OK). Au passage : nettoyage
des transients ajouté (ils n'étaient pas purgés).

---

## 29 mai 2026 — QA : bug « commande à 0 € » (coupon 100 %) corrigé

Scénario 8 : coupon 100 % → commande à 0 €. La facture était **bien générée**
mais **invalide** : XSD KO (`SpecifiedTradePaymentTerms` inattendu),
BR-CO-18 (« au moins un groupe de ventilation TVA ») et BR-E-01 KO.

Cause : dans `apply_tax_breakdown_and_summation` (XmlBuilder) et son jumeau
`get_tax_breakdown` (PdfRenderer), le garde-fou `if ($net <= 0) return;` du
cumul jetait toute ligne à net nul. Coupon 100 % → TOUTES les lignes à 0 →
aucun bucket → aucun `ApplicableTradeTax` → XML structurellement invalide.
Bug latent plus large : une ligne « cadeau gratuit » (net 0) dans une commande
payante aurait aussi cassé BR-E-01 (ligne catégorie E affichée, sans groupe E).

Décision : retirer le garde-fou. Les lignes **produit comptent toujours** dans
la ventilation (elles sont toujours affichées par apply_lines) ; seule la
**livraison à 0** reste ignorée (cohérence affichage/XML). Résultat : 0 € →
un groupe « Exonéré » (base 0, taxe 0) → **Fully Valid FNFE-MPE** (vérifié).
Correctif appliqué à l'identique dans PdfRenderer.

Question produit ouverte (non bloquante) : faut-il *générer* une facture pour
une commande à 0 €, ou l'ignorer ? La sortie est désormais valide dans les deux
cas → à trancher avec Mathis (option simple : ne pas générer si total ≤ 0).

---

## 29 mai 2026 — QA : 2 bugs de conformité TVA corrigés (commit 890f99b)

Passe QA sur une commande B2B multi-taux (20 % + 5,5 % + exonéré). Deux bugs
réels, invisibles tant qu'on ne testait que du 0 % :

1. **Taux 5,51 % au lieu de 5,50 %.** On déduisait le taux via `tax/net*100` ;
   WC arrondit chaque ligne à 2 décimales d'abord, donc la déduction dérive.
   Corrigé : on lit le taux EXACT stocké par WC
   (`WC_Order_Item_Tax::get_rate_percent`, mappé par rate_id par ligne), avec
   la déduction en fallback. Helpers `get_rate_map()` + `line_rate()` ajoutés
   dans XmlBuilder ET PdfRenderer.

2. **BR-CO-10 échec.** `LineTotalAmount` document = `round(Σ nets bruts)` =
   160.73, alors que la règle exige `Σ round(net par ligne)` = 160.72.
   Corrigé : arrondir chaque ligne avant de sommer les buckets. Les autres
   règles de sommation (BR-CO-13/14/15) restent cohérentes (160.72 + 19.28
   = 180.00).

Résultat : facture multi-taux **Fully Valid FNFE-MPE** (3 buckets E/S20/S5.5).
Le validateur officiel est la source de vérité — le Schematron de horstoeko
est plus permissif et n'avait rien signalé. Toujours valider sur FNFE-MPE.

**Dette V0.1 → V0.5** : la logique de taux/sommation est dupliquée entre
XmlBuilder et PdfRenderer (les deux lisent le WC_Order). La couche DTO de la
V0.5 (task #14) doit unifier ça en un seul résolveur.

---

## 27 mai 2026 — Revue critique fin d'étape 3

Revue critique du prompt initial après avoir livré les étapes 1 à 3. Sept
points soulevés et arbitrés avec Mathis.

### 1bis. Strauss ne tourne PAS sur Windows — scoping déplacé en build Linux (29 mai 2026)

**Constat.** Strauss 0.27.2 a été testé sur le poste Windows de Mathis
(via Git Bash ET via PowerShell natif) : il génère un `vendor-prefixed/`
vide (uniquement l'autoloader, aucun fichier package copié). Bug de
résolution de chemins relatifs spécifique à Windows (chemins `../` à 9
niveaux dans les warnings). Avec `delete_vendor_packages: true`, il a même
supprimé les sources de `vendor/` sans les recopier → état cassé,
récupéré par `composer install`. WSL absent (poste VINCI, install soumise
à l'IT).

**Décision.** Le scoping devient une étape de **build de release sur Linux**,
pas une étape de dev. Concrètement :
  - La config Strauss reste dans `composer.json` (`extra.strauss`), correcte.
  - `bin/build.sh` exécute composer install --no-dev + Strauss +
    updateCallSites + zip, dans un dossier `build/` séparé (la source
    commitée n'est jamais modifiée). À lancer sur Linux/CI au moment de
    packager pour WordPress.org.
  - Le fichier principal charge `vendor-prefixed/autoload.php` EN PREMIER
    s'il existe (build scopé), sinon `vendor/autoload.php` (dev non scopé).
    Aucun effet en dev, bascule automatique dans le zip livré.

**Conséquences.**
  - Dev + tests V0.1 sur Windows : 100 % fonctionnels avec `vendor/` non scopé.
  - `bin/build.sh` est écrit mais NON testé sur Windows (impossible) — à
    valider lors du premier run Linux, avant soumission WP.org : régénérer
    une facture depuis le zip buildé et la repasser au validateur FNFE-MPE.
  - Risque résiduel si on shippait non scopé : mismatch de version quand un
    autre plugin embarque les mêmes libs (TCPDF surtout). Pas un fatal
    systématique (Composer autoload = premier chargé gagne), mais à éviter.
    Le build scopé règle ça.

**Statut.** 🔄 Config + build script prêts. Exécution + validation du zip
scopé reportées à la phase de soumission WP.org (sur Linux/CI).

---

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

## 28 mai 2026 — Apprentissages Schematron EN 16931 (étape 5C)

Quatre itérations FNFE-MPE pour arriver à "Fully Valid: YES". Notes
pour ne plus avoir à les redécouvrir.

### BR-S-05 — VAT category 'S' uniquement avec un taux strictement > 0
Si une ligne a un taux 0%, on NE PEUT PAS déclarer category "S"
(Standard rated). Fix : helper `vat_category_for_rate()` qui renvoie
'S' si rate>0, 'E' (Exempt) sinon.

### BR-E-10 — VAT category 'E' impose une raison d'exemption
Une ligne ou un breakdown avec category 'E' DOIT porter soit BT-120
(text) soit BT-121 (code). Sans ça, échec.

### Subtilité ligne vs document pour ExemptionReason
L'élément `<ram:ExemptionReason>` est valable UNIQUEMENT au niveau
document (`ApplicableHeaderTradeSettlement/ApplicableTradeTax`),
pas au niveau ligne (`SpecifiedLineTradeSettlement/ApplicableTradeTax`).
Le validateur dit "marked as not used in the given context" si on le
met au niveau ligne. Fix : passer `exemption_reason_for()` uniquement
à `addDocumentTax()`, pas à `addDocumentPositionTax()`.

### BR-CO-25 — Montant dû positif impose terme de paiement
Si Amount due > 0 (toujours le cas pour nous), il faut soit Payment
Due Date (BT-9) soit Payment Terms description (BT-20). Default
"Paiement à réception de la facture." filterable.

### PEPPOL-EN16931-R008 — pas d'éléments vides
horstoeko émet `<ApplicableHeaderTradeDelivery/>` vide par défaut.
Le remplir avec au minimum BT-72 (date de livraison effective) via
`setDocumentSupplyChainEvent()` résout. On utilise la date de
complétion de la commande WC.

### Conclusion stratégique
**FNFE-MPE est la source de vérité, pas horstoeko's Schematron.**
horstoeko a un Schematron incomplet (n'avait flaggé aucune de ces 5
règles). À chaque évolution future du plugin, valider à nouveau via
FNFE-MPE en bout de chaîne.

---

## 28 mai 2026 — Limite chronique VIES France (MS_MAX_CONCURRENT_REQ)

**Contexte.** La validation TVA live au checkout affiche souvent
"service temporairement indisponible". Diagnostic effectué : le endpoint
de statut de la Commission EU marque pourtant FR comme "Available", mais
les deux endpoints VIES (SOAP `/services/checkVatService` ET REST
`/rest-api/check-vat-number`) renvoient `MS_MAX_CONCURRENT_REQ` pour un
numéro FR valide (testé avec FR66825215296 = Pennylane).

**Conclusion.** Ce n'est pas un bug du plugin. Le registre TVA français
derrière VIES a un plafond de requêtes *simultanées* très bas et est
fréquemment saturé. C'est un problème documenté et chronique de VIES FR.
Basculer SOAP -> REST ne change rien (même backend).

**Décision V0.1.**
- On garde l'endpoint SOAP (fonctionne, le fault est backend-level).
- On ajoute UN retry automatique (~1,2s) sur `MS_MAX_CONCURRENT_REQ` —
  le slot concurrent se libère souvent entre les deux tentatives. Délai
  filtrable via `mathisfx_vies_retry_delay_us`.
- La gestion gracieuse reste : jamais de blocage du checkout, la
  validation de format locale (FR + 11) suffit à laisser passer.

**Piste V0.5 Pro.** File d'attente / backoff exponentiel côté serveur,
cache plus agressif, ou proxy VIES hébergé (même logique que l'idée
proxy INSEE) pour lisser les pics de concurrence.

**Statut.** ✅ Retry simple implémenté. Limitation externe assumée.

---

## 28 mai 2026 — Périmètre personnalisation V0.1 vs V0.5

Décision produit prise avec Mathis suite au premier rendu PDF visuel.
Le prompt initial gardait "personnalisation visuelle de l'invoice
template" pour V0.5+. À la réflexion on a élargi le V0.1 gratuit
pour ne pas avoir l'air pauvres face à WP Overnight (~800k installs,
upload logo natif).

**V0.1 (gratuit, sur WordPress.org)** :
- Tous les champs Settings existants (raison sociale, SIRET, TVA,
  adresse, mentions légales, préfixe numérotation, etc.)
- **Upload de logo** via la Media Library WP
- **Choix d'une couleur principale** (qui remplace le bleu `#2271b1`
  hardcodé actuellement dans le template)
- Un seul template visuel (le `default.php` actuel)

**V0.5 (Pro, 149-599 €/an via Freemius)** :
- 2-3 templates additionnels (Minimal, Moderne, Sobre)
- Couleurs secondaires et tertiaires
- Choix de la police
- Référence interne / champs additionnels sur facture
- Conditions de paiement détaillées (échéance, escompte, pénalités)

**V1.0** :
- Édition de template via Gutenberg blocks
- Multilingue (templates par langue)
- Multi-currency

**Raison de la stratégie** : on vend la conformité Factur-X (réforme
française 2026), pas la beauté. Mais on ne peut pas se permettre
d'avoir un PDF sans logo en V0.1, ça va se voir dès les premiers
screenshots WP.org. Logo + couleur = floor minimum. Le reste
justifie le passage Pro.

**Échéance d'implémentation** : juste après l'étape 5C (PDF/A-3
hybride). Le wiring logo + color dans le template n'a pas de
dépendance sur la conformité PDF/A, donc ajout en parallèle.

**Statut.** 🔄 En attente de l'étape 5C, puis implémenté.

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

## 29 mai 2026 — CHECKPOINT (reprise cet après-midi)

**Fait (commité, working tree propre, dernier commit `9ea30bc`) :**
Étapes 1 à 7 + logo/couleur + i18n (.pot) + readme.txt WP.org + config
Strauss/build script + PHPCS WordPress Coding Standards (zéro violation).
Le plugin est fonctionnellement complet et propre.

**À faire à la reprise, dans l'ordre :**
1. **Re-vérif fonctionnelle** (rapide) : Local relancé → régénérer une
   facture via le metabox admin → confirmer notice verte + (bonus) PDF
   toujours « Fully Valid » sur services.fnfe-mpe.org. Le gros commit
   PHPCS a reformaté tous les fichiers ; lint OK mais la génération
   n'a pas été re-testée (DB Local éteinte au moment du smoke test).
2. **PHPUnit suite complète** (task #11) : setup PHPUnit + Brain Monkey
   pour les tests isolés (validators format SIRET/TVA, format
   numérotation, calcul de taux XmlBuilder) + test de concurrence sur
   la numérotation (celui-là a besoin d'une vraie DB MySQL → via le
   Site Shell de Local). Nécessite Local + DB up.
3. **Plugin Check Plugin** : installer https://wordpress.org/plugins/plugin-check/
   dans Local, lancer sur notre plugin, corriger les warnings bloquants.
4. **Tag git `v0.1.0`** quand tout est vert.

**Rappels environnement :**
- PHP CLI de Local : `C:\Users\mathis.deroy\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`
- wp-cli : `C:\Users\mathis.deroy\AppData\Local\Programs\Local\resources\extraResources\bin\wp-cli\wp-cli.phar`
- composer : `C:\ProgramData\ComposerSetup\bin\composer.bat`
- PHPCS : `php vendor/bin/phpcs` (ruleset phpcs.xml.dist) — doit rester à zéro.
- Zscaler coupé pour tout appel réseau (INSEE/VIES/téléchargements).

---

## 29 mai 2026 — Roadmap post-V1.0 & architecture portable (verdict)

Évaluation de l'addon `Addon-CLAUDE-md-Roadmap-Extension.md` (séparation
Core/Adapter, paramétrage profil+format, i18n multi-locale, filtres WP,
extensions DE/PrestaShop/Peppol). Le fond est bon, le calendrier ne l'est
pas pour la V0.1. **Décision : on N'applique RIEN en V0.1.** Le plugin est
déjà construit (étapes 1-8) en structure plate `includes/` et PHPCS-clean ;
retoucher maintenant = coût pur sans gain fonctionnel, à risque pour la
deadline fin juin.

### Pourquoi rien en V0.1
- Le gros du gain (Core/Adapter + couche DTO) est cher des deux côtés et
  re-churnerait 12 fichiers qu'on vient de finir et de nettoyer PHPCS.
- Les éléments « pas chers » de l'addon sont **soit déjà en place, soit
  spéculatifs** :
  - Filtres WP Settings : DÉJÀ présents (`mathisfx_settings_sections`,
    `mathisfx_settings_fields` dans class-settings.php depuis l'étape 2).
  - i18n strict + .pot : DÉJÀ fait (étape 8).
  - Paramètre profil/format dans les signatures : trivial à ajouter en
    V0.5, et on aura alors les vraies contraintes EXTENDED → mieux vaut ne
    pas figer une signature à l'aveugle maintenant.
- Le `.po` allemand squelette en V0.1 = busywork (traduction partielle
  affichée sur WP.org pour un marché à 2+ ans). **Rejeté.**
- Le « HttpClient maison » pour garder Core pur = over-engineering que
  l'addon lui-même dit vouloir éviter. **Rejeté pour V0.1.**

### Corrections que j'apporte au plan de l'addon (pour la V0.5)
- **Découpage à 3 niveaux, pas 2.** L'addon met SiretValidator (INSEE) et
  ViesValidator dans `Core/`. Or ils sont franco-français. Le vrai
  découpage : `Core/FacturX` (XML CII + PDF/A-3, générique) /
  `Core/France` (SIRET, VIES, taux TVA FR) / `Adapter/WC`. La promesse
  « ~0 % de réécriture moteur pour PrestaShop » est survendue : seul
  `Core/FacturX` est vraiment transversal.
- **Dette technique réelle à régler au passage en V0.5 :** `XmlBuilder` et
  `PdfRenderer` dupliquent chacun leur extraction lignes/TVA depuis le
  `WC_Order`. Un DTO `InvoiceData` partagé supprimerait cette duplication —
  c'est le vrai argument pour la couche DTO (au-delà de la portabilité).

### À faire en V0.5 (refactor justifié par les features EXTENDED + routage PA)
1. Introduire la couche DTO (`InvoiceData`, `PartyData`, `LineItemData`,
   `TaxData`) ; l'adaptateur mappe `WC_Order` → DTO, le moteur consomme le DTO.
2. Restructurer `includes/` en `core/FacturX`, `core/France`, `adapter/`.
3. Paramétrer `build($dto, $profile, $format)` (EN16931/EXTENDED, FACTUR_X/…).
4. Filtre `mathisfx_available_profiles` quand il y aura >1 profil à exposer.
5. PHPUnit du moteur isolé (déjà prévu en V0.5).

Extensions marché (DE ZUGFeRD ~2 sem, PrestaShop 2-3 mois, Peppol module
V3.0) : confirmées comme cibles 2027-2028, **après** validation de la
traction V0.1/V0.5. Ne rien architecturer spécifiquement pour elles tant
que la couche DTO + le découpage 3-niveaux de la V0.5 ne sont pas en place
(ils suffisent à les accueillir).

**Note :** l'addon n'est PAS recopié dans CLAUDE.md (il s'auto-décrit comme
guidage V0.1 actif, ce qui contredit ce verdict). Ce résumé fait foi.

**Statut.** 🧭 Vision enregistrée. Aucune action V0.1. Plan V0.5 cadré.

---

## 29 mai 2026 — Build scopé validé sur CI (jalon majeur)

Le dernier risque technique du projet est levé. Dépôt GitHub privé créé
(`mathisderoy-droid/factur-x-for-woocommerce`) avec un workflow GitHub
Actions (`.github/workflows/build.yml`) qui exécute `bin/build.sh` sur
ubuntu-latest (PHP 8.1) à chaque tag `v*` ou en manuel.

Premier build : échec sur Strauss (`Your github oauth token contains
invalid characters`) — Strauss instancie Composer qui lit le token CI
injecté. Corrigé dans build.sh (`composer config --unset github-oauth` +
`COMPOSER_AUTH='{}'` juste avant Strauss). Second build : **succès**.

Le zip produit a été inspecté et validé :
- Fichiers de dev exclus (CLAUDE.md, DECISIONS.md, tests, bin, .github,
  composer.*, strauss.phar, *.dist, .gitignore).
- `vendor/` ne garde que l'autoloader de nos classes ; toutes les libs
  supprimées et recopiées scopées dans `vendor-prefixed/`.
- Call sites réécrits dans `includes/` : `use Mathis\FacturX\Vendor\
  horstoeko\...` et `use Mathis_FacturX_Vendor_TCPDF as TCPDF;`.
- Fichier principal charge `vendor-prefixed/autoload.php` en premier.
- Smoke test PHP : toutes les classes scopées + les nôtres se résolvent.

Conclusion : `bin/build.sh` + le workflow CI produisent un paquet
conforme, conflit-proof, prêt pour WordPress.org. Pour livrer une
nouvelle version : `git tag vX.Y.Z && git push origin vX.Y.Z` → le zip
sort tout seul dans les artefacts de l'Action. Zip v0.1.0 archivé dans
Downloads.

**Reste pour la mise en ligne (task #15)** : QA des 13 scénarios, 4
screenshots, compte WordPress.org + soumission (review ~2-3 sem).

---

## Légende des statuts

- ✅ **Fait** — décision implémentée
- 🔄 **En cours / À faire** — action en cours, échéance proche
- ⏸ **Reporté** — décision prise, exécution programmée plus tard
- ❌ **Abandonné** — décision annulée (raison documentée)
- 💡 **Idée** — piste à creuser, pas encore décidée
