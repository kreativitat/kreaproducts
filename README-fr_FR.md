<!-- Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com> -->

# KreaProducts pour Dolibarr ERP/CRM

KreaProducts est un module avancé de gestion des produits pour [Dolibarr ERP/CRM](https://www.dolibarr.org). Il enrichit le module Produits avec les recettes et fiches techniques, la nutrition, les allergènes, les nomenclatures et la MRP, les inventaires traçables, les mouvements de stock à date de valeur ainsi que l'actualisation automatique des coûts et des prix de vente. Il est conçu pour l'hôtellerie-restauration, le commerce de détail et la production alimentaire qui exigent des données cohérentes, une traçabilité fiable et un _food cost_ précis.

KreaProducts propose également des suggestions facultatives de nutrition et d'allergènes assistées par IA via OpenAI, Anthropic, OpenRouter ou une instance Ollama privée. Les suggestions restent modifiables, les déclarations d'allergènes reposent uniquement sur les éléments du produit et aucune donnée n'est enregistrée sans confirmation explicite de l'utilisateur.

## Nouveautés principales

- Un espace Nutrition et allergènes cohérent avec un sélecteur commun pour les données saisies, les données calculées ou les produits non alimentaires.
- Le détail nutritionnel calculé par composant avec quantité, poids, contribution nutritionnelle, totaux de la recette et valeurs normalisées pour 100 g.
- Des actions communes de modification et d'enregistrement, ainsi qu'une fenêtre dédiée à la copie de la nutrition et des allergènes vers un autre produit.
- Des suggestions IA soumises à validation, avec réponses structurées, identifiants chiffrés pour les fournisseurs hébergés et protections réseau pour Ollama.
- La description du produit, les ingrédients et la préparation en Markdown, avec conversion automatique de l'ancien HTML lors du chargement.
- La modification native de la nature du produit, cohérente avec les champs Type et Poids.
- La validation sécurisée par déclencheurs d'une facture fournisseur ou de toutes les factures brouillon d'un fournisseur via l'API.
- Une meilleure gestion des dates de facture client, de la tolérance des dates futures et de la reconstruction des inventaires corrigés.

## Fonctionnalités

### Nutrition et allergènes

- Saisie manuelle ou calcul automatique de la nutrition et des allergènes.
- Détail de chaque composant et valeurs moyennes pour 100 g.
- Propagation entre produits parents et composants, y compris les nomenclatures MRP.
- Gestion des allergènes présents et des traces selon leur proportion dans le poids total.
- Prise en charge des produits non alimentaires sans suppression des données déjà enregistrées.
- Suggestions IA contrôlées et explicitement confirmées avant enregistrement.

### Produits, recettes et coûts

- Arborescence complète des associations, nomenclatures et sous-produits.
- Vue inverse pour identifier les recettes et produits qui utilisent un composant.
- Recalcul automatique en cascade du coût des produits finis.
- Synchronisation facultative du prix de vente à partir du coût et du taux de marge configuré.
- Démantèlement automatique des conditionnements achetés en unités exploitables, avec mouvements de stock et traçabilité.

### Stock et inventaires

- Datation des entrées fournisseur selon la date de la facture ou de la réception.
- Mouvements client conservés à la date et à l'heure faisant autorité sur la facture.
- Inventaires à date de valeur avec corrections auditées et reconstruction cohérente des mouvements ultérieurs.
- API de validation des factures fournisseur avec contrôle de l'entité, de l'entrepôt et des autorisations Dolibarr.

## Prérequis

- Dolibarr 19 ou version ultérieure.
- PHP 7.3 ou version ultérieure.
- MySQL ou MariaDB.
- Modules requis : Produits, Stock, Fournisseurs, Nomenclatures, MRP et Cron.
- Module facultatif : Lots/séries (`productbatch`).

## Licence et assistance

- Licence GPL-3.0-or-later.
- Site : https://www.kreativitat.com
- Démonstration : https://dolibarr.kreativitat.com
- Assistance : mail@kreativitat.com
