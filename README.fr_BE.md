<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts pour Dolibarr ERP/CRM

KreaProducts est un module avancé de gestion des produits pour le [Dolibarr ERP/CRM](https://www.dolibarr.org). Il étend le module Produits avec la nutrition, les allergènes, les BOM/fiches techniques, l’inventaire et des automatisations des coûts et du stock - pensé pour la restauration et le commerce de détail qui exigent cohérence, traçabilité et un _food cost_ toujours exact.

## Fonctionnalités

### Nutrition et allergènes

- Table nutritionnelle avec calcul, validation et mise à jour automatique.
- Propagation des nutriments entre produits parent/enfant, y compris BOM (MRP) lorsqu’il est actif.
- Gestion des allergènes avec propagation par pourcentage du poids total et marquage des traces.
- Prise en charge des produits non alimentaires (exclus du calcul).

### Structure des produits et BOM

- Arborescence complète des produits (associations + BOM/MRP, lorsqu’actif), avec navigation hiérarchique.
- Vue détaillée de la composition du produit (composants, quantités et sous-produits), avec totalisation du **prix de revient**.
- Identification claire des relations entre produits et fiches techniques, y compris les **emballages source** le cas échéant.
- Vue inverse (_où c’est utilisé_) : liste des kits/fiches techniques et menus où l’article est utilisé comme composant, permettant d’évaluer l’impact des changements de coût, des substitutions et de la normalisation des matières premières.
- BOM de démontage avec origine et relations visibles sur la fiche technique.
- Recalcul automatique des coûts en cascade basé sur les composants/fiches techniques.
- Prise en charge des BOM imbriquées (lignes de BOM référant une autre BOM), avec propagation correcte des coûts, de la nutrition et des allergènes.
- Multi-sociétés : BOM partagées (entity=0) disponibles dans toutes les entités, avec priorité pour la BOM de l’entité courante lorsqu’elle existe.
- Le démontage s’active par produit via le champ extra `kreap_dismantle`.

### Dates correctes de stock et d’inventaire (date de facture et date de valeur)

Par défaut, Dolibarr enregistre de nombreux mouvements **à la date à laquelle le document est saisi/validé dans le système** - ce qui peut ne pas correspondre à la réalité opérationnelle. Dans les environnements avec des achats fréquents, cet écart crée des déviations et du bruit dans l’analyse de stock.

KreaProducts corrige cette limitation avec deux automatisations essentielles :

- **Entrée de stock à la date de facture (fournisseurs) :** les produits sont enregistrés en stock avec la **date de facture/date d’entrée**, au lieu de la date à laquelle le document est saisi dans Dolibarr. Cela élimine les écarts lorsque la facture est saisie plusieurs jours après.
- **Inventaire à date de valeur (rétroactif) :** l’ajustement d’inventaire est appliqué en fonction de la **date d’inventaire (date de valeur)**, et non de la date de validation. Cela permet de saisir un inventaire avec une date de valeur antérieure (par exemple, d’il y a une semaine) et de garantir la cohérence des corrections et des rapports - ce que le module standard ne garantit pas.
- **Recalcul basé sur l’inventaire physique :** le stock est recalculé à partir de la **quantité comptée** (qty_stock) lorsqu’elle est disponible, avec qty_view en secours, ce qui évite les écarts sur les mouvements rétrodatés.

### Gestion intelligente des emballages et du coût unitaire (démontage automatique)

En restauration, il est courant d’acheter le même article dans différents emballages - mais pour le _food cost_ c’est le **coût unitaire réel** (ex. EUR/L, EUR/kg, EUR/un) qui compte.

Exemple typique : **huile**. Elle peut être achetée en **bidons de 10 L, 5 L, 1 L** ou **caisses 12x1 L**. Si ces emballages entrent dans le système comme des "produits différents", des incohérences de stock et de coût unitaire apparaissent rapidement.

KreaProducts résout ce problème via le module **BOM de Dolibarr (Listes de Matériaux / Fiche de Matériaux - FM)** :

- On configure une FM pour l’emballage (ex. _bidon 10 L_), en définissant la conversion vers le produit unitaire (ex. _10x 1 L_).
- À partir de là, chaque fois qu’un achat de ces emballages est enregistré, le système procède au **démontage automatique** vers le produit unitaire, **sans intervention de l’utilisateur**.

Ce processus :

- crée les **mouvements de stock** correspondants,
- maintient le **coût proportionnel** et la traçabilité (origine -> destination),
- et garantit que le produit unitaire est prêt pour les recettes, l’inventaire et les calculs de marge.

### Mise à jour automatique des coûts et du _food cost_ (en cascade)

KreaProducts automatise également la mise à jour du **prix de revient** et du **food cost** des produits finis, sur la base de leurs fiches techniques (BOM/FM).

En pratique :

- si un composant (ex. **huile**) voit son prix d’achat mis à jour,
- tous les produits qui utilisent ce composant (ex. **frites**) voient leur **coût recalculé automatiquement**,
- garantissant que le _food cost_ et les marges reflètent toujours la réalité, sans ajustements manuels.

Cette fonctionnalité est particulièrement pertinente dans les opérations avec de nombreuses recettes et des achats fréquents, où de petites variations de coût doivent se refléter immédiatement dans les produits finis.

### Productivité et listes

- Liste de produits simplifiée avec option de masquer des éléments.
- Simulateur de prix (Métriques et Marges) avec markup de test.
- Liste des mouvements de stock par produit avec **stock total**.

## Exigences

- Dolibarr >= 19
- PHP >= 7.0
- Modules requis : Produits, Stock, Fournisseurs, BOM/MRP
- Optionnel : Lots (productbatch)

## Installation

1. Copier le module dans `custom/kreaproducts`.
2. Activer dans Configuration -> Modules/Applications -> KreaProducts.
3. Ajuster les options sur la page de configuration.
4. Si nécessaire, importer les scripts dans `sql/`.

## Configuration (principales constantes)

| Constante | Description |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Classe d’unités pour le poids. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Afficher la table nutritionnelle dans l’onglet de la fiche technique. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Afficher le sélecteur et le bouton pour copier les valeurs moyennes par 100 g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Afficher le sélecteur et le bouton pour copier les allergènes vers un autre produit. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propager automatiquement le prix de revient (recalcul en cascade). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Pourcentage du poids total pour considérer les allergènes comme présents. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Pourcentage du poids total pour marquer les allergènes comme traces. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Utiliser la date de facture pour les mouvements de stock. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Heure appliquée aux mouvements de factures fournisseurs. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Heure par défaut lors de la création d’un inventaire. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Catégorie racine pour la sélection de l’inventaire. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | Type de BOM utilisé pour le démontage. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Entrepôt pour les mouvements de démontage. |
| `KREAPRODUCTS_SIM_ENABLE` | Activer le simulateur de prix. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Markup par défaut du simulateur. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Remplacer la liste standard des produits. |
| `KREAPRODUCTS_DEBUG_LOG` | Activer les logs de debug du KreaProducts. |

Note : les seuils d’allergènes sont des pourcentages du poids total de la recette du produit final.

## Autorisations

- Nutrition : lecture, écriture, suppression.
- Allergènes : lecture, écriture, suppression.
- Inventaire : voir les valeurs attendues.

## Licence

- GPL-3.0-or-later (voir LICENSE et COPYING).
- Licence propriétaire disponible pour un usage commercial ou un code fermé ; contactez mail@kreativitat.com.

## Support et contributions

- GitHub : https://github.com/kreativitat
- Website : https://www.kreativitat.com
- Demo : https://dolibarr.kreativitat.com

## Avertissement légal

Les données de nutrition et d’allergènes sont saisies par l’utilisateur ou dérivées de ses entrées et ne sont pas vérifiées. Elles sont fournies uniquement à titre informatif et ne constituent pas un avis médical, diététique ou réglementaire. L’utilisateur est seul responsable de l’exactitude, de l’étiquetage et du respect des lois applicables. Ce module est fourni "tel quel", sans garantie d’aucune sorte, expresse ou implicite, y compris les garanties de qualité marchande et d’adéquation à un usage particulier. Dans la mesure maximale permise par la loi, les auteurs et distributeurs ne sont pas responsables des dommages directs ou indirects résultant de l’utilisation des données ou du logiciel.

## Captures d’écran

![KreaProducts - Écran 1](img/screenshot_1.png)

![KreaProducts - Écran 2](img/screenshot_2.png)
