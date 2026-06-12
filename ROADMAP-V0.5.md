# Roadmap — de la V0.1 (gratuite) à la V0.5 Pro (payante)

> Document de référence. Objectif : transformer Facturio (qui *génère* des
> factures Factur-X) en Facturio Pro, qui les *transmet* via une PDP et se vend
> sous licence. Chaque phase est testable et validée avant la suivante, comme
> pour la V0.1. Les durées sont indicatives (rythme « à petits pas »).

---

## 🧭 Vue d'ensemble (les 7 phases)

```
  [0] V0.1 approuvée ──▶ [1] Fondation ──▶ [2] Sandbox PDP ──▶ [3] 1er connecteur
        (WP.org)          (Core/Adapter)     (explorer l'API)     (B2Brouter)
                                                                       │
  [6] Lancement ◀── [5] Licence & site ◀── [4] Multi-PDP + cycle de vie ◀┘
   (vente Pro)        (Freemius + vitrine)    (Super PDP, Pennylane, statuts)
```

État actuel : **on est à la fin de la phase 0** (V0.1 en review WP.org). Tout le
**cadrage** des phases 2-6 est déjà fait (voir DECISIONS.md). Reste à exécuter.

---

## Phase 0 — Finaliser la V0.1 gratuite ⏳ EN COURS
**But :** être publié sur WordPress.org.
- [x] Plugin développé, testé (QA 13/13), renommé **Facturio**, re-soumis.
- [ ] **Attendre l'approbation** du reviewer (en cours).
- [ ] À l'approbation : accès SVN → ajouter **screenshots + icône + bannière** → en ligne.
**Bloquant pour la suite ?** Oui : on ne code pas la V0.5 avant l'approbation
(le code pourrait encore devoir changer si le reviewer demande autre chose).

## Phase 1 — La fondation : refactor Core/Adapter 🏗️ *(~1-2 semaines)*
**But :** réorganiser le code pour qu'il accueille licence + modules PDP proprement.
- [ ] Créer une branche `v0.5-dev` (la V0.1 reste intacte).
- [ ] Unifier le calcul TVA dupliqué (le bug corrigé 2× en QA) en **un seul endroit**
      (un DTO « facture » partagé entre XmlBuilder et PdfRenderer).
- [ ] Séparer en 3 niveaux : cœur Factur-X / règles France / adaptateur WooCommerce.
- [ ] Définir l'**interface PDP commune** (le « trou de prise » standard).
- [ ] Filet de sécurité : les **23 tests + l'inspecteur** doivent rester verts.
**Pourquoi en premier :** aucune décision business requise, et ça rend tout le
reste possible. Indépendant de toute PDP.

## Phase 2 — Explorer une sandbox PDP 🧪 *(~quelques jours, sans engagement)*
**But :** comprendre concrètement comment une facture se transmet.
- [ ] Créer un compte **sandbox gratuit** (B2Brouter — le mieux outillé : SDK,
      OpenAPI, webhooks ; ou Super PDP).
- [ ] Lire le Swagger : authentification (token/OAuth2 ?), endpoint d'émission, statuts.
- [ ] Envoyer **à la main** une Factur-X générée par Facturio dans la sandbox → voir
      le résultat. (But : valider que notre format passe tel quel.)
**Aucun code plugin ici — on apprend le terrain.**

## Phase 3 — Premier connecteur PDP 🔌 *(~1-2 semaines)*
**But :** Facturio transmet réellement une facture (en sandbox).
- [ ] Écrire l'**adaptateur B2Brouter** derrière l'interface de la phase 1.
- [ ] Réglages : champ « clé API de la PDP » (le **client apporte sa propre PDP**).
- [ ] Bouton « Transmettre » sur la commande + gestion des erreurs.
- [ ] Tester de bout en bout dans la sandbox.
**À la fin : la fonctionnalité phare existe** (avec 1 PDP).

## Phase 4 — Multi-PDP + suivi du cycle de vie 🔁 *(~2-3 semaines)*
**But :** élargir le marché + afficher les statuts officiels.
- [ ] Ajouter les adaptateurs **Super PDP** et **Pennylane** (Iopole ensuite).
- [ ] Afficher dans l'admin WooCommerce les statuts (déposée, reçue, refusée…).
- [ ] (Optionnel) Première brique **e-reporting** B2C selon la PDP.
**Chaque PDP = juste un petit adaptateur grâce à la phase 1.**

## Phase 5 — Licence & site de vente 💳 *(~2-3 semaines)*
**But :** pouvoir encaisser, et verrouiller le Pro.
- [ ] Intégrer le **SDK Freemius** (ou Lemon Squeezy) → portillon
      `can_use_premium_code()` devant les fonctions Pro.
- [ ] Champ « clé de licence » dans les réglages (fourni par le SDK).
- [ ] Monter le **site vitrine** sur le VPS (cf. PROMPT-SITE-VENTE.md) : pages +
      bouton d'achat + pages légales (CGV/RGPD).
- [ ] Définir le **prix** (après avoir le coût PDP réel — modèle par paliers).
**Décisions business à trancher ici** (prix, plateforme de paiement définitive).

## Phase 6 — Lancement de Facturio Pro 🚀
**But :** vendre.
- [ ] Test d'achat complet en mode *test* (paiement → licence → déblocage Pro → MAJ auto).
- [ ] Bascule en *live*, premier vrai achat puis remboursé.
- [ ] Communication (la base d'utilisateurs gratuits WP.org = tes premiers prospects).

---

## 🔑 Décisions déjà prises (rappel — détaillé dans DECISIONS.md)
- **Modèle** : freemium, plugin unique (gratuit + Pro verrouillé par licence).
- **Le client apporte SA PDP** → Facturio Pro vend le **connecteur** (prix fixe),
  pas la transmission. Le coût/facture n'est pas à notre charge.
- **PDP cibles** : B2Brouter (1er), Super PDP, Pennylane (+ Iopole). Toutes
  vérifiées ouvertes aux éditeurs tiers.
- **Factur-X = format (déjà OK)** ; **PDP = tiers obligatoire** (transmission +
  e-reporting + annuaire) ; Peppol = réseau, ne remplace pas la PDP.
- **Paiement/licence** : Freemius (ou Lemon Squeezy). Anti-piratage géré par eux.

## ⚠️ Points encore ouverts (à trancher le moment venu)
- **Prix** de Facturio Pro → après devis/coût PDP réel.
- **Plateforme de paiement** définitive (Freemius vs Lemon Squeezy).
- **Calendrier légal** à revérifier avant de coder (réception oblig. ~sept. 2026,
  émission PME/TPE ~sept. 2027) — marge confortable.
- **Nom de marque Facturio** : vérifier domaine `facturio.fr/.com` + dispo INPI.

## ⏱️ Estimation totale
Hors attente d'approbation WP.org : **~2 à 3 mois** de travail à petits pas pour
une V0.5 complète et vendable. Confortable vs l'échéance d'émission PME (2027).
```
