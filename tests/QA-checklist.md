# QA V0.1 — 13 scénarios manuels (section 8.4 du prompt)

Statut : ⬜ à faire · 🔄 en cours · ✅ OK · ❌ KO

## Prérequis
- ✅ TVA WooCommerce activée + taux FR : Standard 20 %, Réduit 5,5 % (onglet « Reduced rate rates »)
- ✅ Produits de test : Test TVA 20 (100 €), Test TVA 5.5 (50 €), Test exonéré (30 €, état « Aucun »)
- ✅ Coupons créés : TEST10 (–10 %) et FREE100 (–100 %)
- ✅ Zscaler coupé pour INSEE/VIES

## Scénarios
1. ✅ Commande B2C simple (sans champs B2B) → facture générée, bloc acheteur = nom + adresse (sans SIRET/TVA),
      **Fully Valid FNFE-MPE**. (1ʳᵉ validation d'une facture B2C — forme de document sans identifiant acheteur.)
2. ✅ Commande B2B SIRET valide (82521529600013 Pennylane) → raison sociale auto, SIRET/TVA dans le XML
3. ✅ Commande B2B SIRET invalide (Luhn KO) → **bloquée** au checkout (wc_add_notice serveur) + retour live « SIRET invalide ».
4. ✅ Commande B2B TVA intra → VIES (gestion gracieuse « indisponible » + format validé)
5. ✅ Commande B2B TVA intra invalide (FR123) → **bloquée** au checkout (message format).
      A révélé un défaut UX : le retour live affichait « Erreur inconnue ». Corrigé : rejet local du format FR dans
      ViesValidator::lookup() (message clair + plus d'appel VIES inutile) + message sur réponse VIES « non valide ».
      Test de non-régression ajouté (16 tests verts).
6. ✅✅ **Multi-taux (20 % + 5,5 % + exonéré) → 3 buckets distincts, totaux corrects, Fully Valid FNFE-MPE.**
      A révélé et corrigé 2 bugs : taux 5,51→5,50 (lecture taux exact WC) + BR-CO-10 (arrondi par ligne). Commit 890f99b.
7. ✅ Commande avec remise/coupon (TEST10 –10 %) → total remisé correct, Fully Valid FNFE-MPE.
      Remise absorbée dans les prix unitaires (pas de ligne « remise » explicite — limite V0.1 assumée, documentée).
8. ✅ Commande à 0 € (coupon FREE100 –100 %) → facture bien générée + Fully Valid FNFE-MPE après correctif.
      A révélé un bug : le garde-fou `$net <= 0` jetait TOUTES les lignes → aucun groupe TVA émis (XSD + BR-CO-18 + BR-E-01 KO).
      Corrigé : les lignes produit comptent toujours dans la ventilation (seule la livraison à 0 est ignorée).
9. ✅ Numérotation séquentielle (test de concurrence 720 numéros sans trou + génération séquentielle observée)
10. ✅ Téléchargement depuis l'admin (utilisé de façon répétée)
11. ✅ Email commande → Factur-X en pièce jointe (vérifié dans Mailpit)
12. ⬜ Désactivation/réactivation du plugin — à faire
13. ⬜ Désinstallation (options/meta supprimés, PDF conservés) — à faire

## Reste à valider à la prochaine session
Scénarios 12, 13 (cycle de vie du plugin) ; le cœur — TVA multi-taux + remises + 0 € + B2C — est prouvé conforme.
Décision en attente : faut-il générer une facture pour une commande à 0 € ou l'ignorer ? (sortie déjà valide dans les deux cas).
Puis : screenshots (4) + soumission WordPress.org.
