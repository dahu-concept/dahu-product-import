# Dahu Product Import

Modifier les textes des produits WooCommerce dans un tableur et les réimporter,
avec une simulation qui montre chaque changement avant toute écriture, et un
retour arrière.

Sous licence MIT. Nécessite WordPress 6.0, PHP 7.4, et WooCommerce 7.0 pour les
colonnes `sku` et `attributes`.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## Le déroulé

```bash
wp product-import check                              # ce que ce site lit et écrit
wp product-import export                             # site -> tableur
#   ... modifier le fichier ...
wp product-import import --file=products.csv         # simulation : le rapport, aucune écriture
wp product-import import --file=products.csv --execute
wp product-import rollback --file=backup-….jsonl --execute
```

## Ce qu'elle ne fera pas

* Jamais **créer** un produit — une ligne qui nomme un identifiant inconnu est signalée et sautée.
* Jamais en **supprimer** un.
* Jamais toucher aux prix, aux stocks, aux poids, aux dimensions, aux
  catégories, aux étiquettes, aux variations, aux attributs globaux, aux
  commandes ni aux clients.
* Jamais **tronquer** une valeur pour la faire entrer : une valeur au-delà d'un
  maximum déclaré fait rejeter la ligne.

## Les colonnes

| Colonne | Écrit dans | Remarques |
|---|---|---|
| `product_id` | — | associe la ligne à un produit |
| `slug` | — | confirme que l'identifiant désigne toujours ce produit |
| `sku` | `_sku` | une cellule vide n'efface jamais une référence |
| `type`, `category` | — | pour information, jamais écrites |
| `title` | `post_title` | obligatoire ; un produit ne peut pas perdre son titre |
| `description` | `post_content` | texte riche |
| `short_description` | `post_excerpt` | texte simple, ou HTML si la cellule en contient |
| `attributes` | attributs locaux | visibles et hors variation seulement |

Plus ce que votre thème déclare.

## Déclarer vos propres champs

```php
add_filter( 'dahu_product_import_fields', function ( $fields ) {
	$fields['subtitle'] = array(
		'target' => '_my_theme_subtitle',
		'nature' => 'text',
	);
	$fields['claim'] = array(
		'target'     => '_my_theme_claim',
		'nature'     => 'text',
		'max_length' => 55,
	);
	$fields['leaflet'] = array(
		'target' => '_my_theme_leaflet',
		'nature' => 'url',
	);
	$fields['related'] = array(
		'target' => '_my_theme_related',
		'nature' => 'product_id',
	);
	return $fields;
} );
```

Un champ déclaré se comporte exactement comme un champ intégré, dans l'export,
la simulation, l'import et le retour arrière.

| Clé | |
|---|---|
| `target` | `post:<champ>` pour une colonne de la table des articles, ou une clé de méta |
| `nature` | comment la valeur est nettoyée, et ce qui compte comme valide |
| `max_length` | en caractères ; une valeur plus longue **fait rejeter la ligne** |
| `required` | une cellule vide fait rejeter la ligne |

### Les natures

| Nature | Nettoyage |
|---|---|
| `text` | texte simple, retours à la ligne repliés |
| `textarea` | texte simple, retours à la ligne gardés |
| `title` | texte simple passé par `sanitize_text_field()` |
| `html` | `wp_kses_post()` |
| `excerpt` | texte simple multiligne, ou HTML gardé tel quel |
| `url` | une URL http ou https, ou rien |
| `product_id` | l'identifiant d'un produit de ce site |

`text` et `textarea` n'utilisent **pas** `sanitize_text_field()` : cette
fonction transforme un `<` isolé en entité, ce qui abîme une valeur technique
légitime comme `<50dB`, et avec un `>` plus loin sur la ligne elle supprime tout
ce qui se trouve entre les deux. L'échappement appartient à l'affichage.

## Les autres points d'accroche

| Accroche | Type | À quoi elle sert |
|---|---|---|
| `dahu_product_import_fields` | filtre | Déclarer des colonnes |
| `dahu_product_import_excluded_product_types` | filtre | Types de produits à laisser à un autre outil ; leurs lignes sont signalées comme rejetées, pas sautées en silence |
| `dahu_product_import_plan_row` | action | Ajouter une écriture qui découle de la ligne plutôt que d'une colonne à elle. Reçoit `&$item, $row, $id, $wanted` ; ajoutez à `$item['changes']` pour le rapport et à `$item['values']` pour l'écriture. Ne doit pas toucher la base : elle s'exécute aussi pendant la simulation |

## L'aller-retour

Exporter puis réimporter sans rien modifier n'écrit **rien**. C'est la propriété
sur laquelle repose la conception, et elle est vérifiée par le banc d'essai au
lieu d'être supposée.

La tenir est la raison pour laquelle une cellule est considérée comme inchangée
dans trois cas : la cellule brute correspond au site, la cellule nettoyée
correspond au site, ou nettoyer la valeur du site donne la cellule nettoyée.
C'est le troisième qui compte — sans lui, le premier import après un export
réécrit une grande partie du catalogue avec des versions blanchies de ce qui s'y
trouvait déjà, et y enterre les vrais changements.

## Quand un champ est réécrit sans arrêt

```bash
wp product-import trace --ids=42 --file=products.csv
```

En lecture seule. La commande suit la valeur du site, l'export, l'encodage CSV
de ce serveur, le fichier tel que votre tableur l'a enregistré, la coupe des
espaces et le nettoyage, et nomme la première étape qui change la valeur, avec
son décalage en octets et son hexadécimal. C'est en général une espace
insécable ou un BOM.

## Les données

Rien dans la base n'appartient à cette extension : elle écrit dans des champs et
des métas qui appartiennent à WooCommerce ou à votre thème. La désinstaller ne
supprime rien.

Les rapports, les sauvegardes et les journaux vivent dans un dossier de travail
qui doit se trouver hors de toute racine web — l'extension refuse un dossier qui
s'y trouverait. Une sauvegarde contient le texte précédent de chaque produit
touché, sous un nom devinable ; servie en HTTP, c'est une copie gratuite de
votre travail éditorial, prise sans laisser de trace.

## Licence

MIT. Voir `LICENSE`.
