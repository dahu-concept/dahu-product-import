# Dahu Product Import — résumé technique

Document interne, seul fichier de cette extension à mentionner Annad. Il relie
la version publique à sa source, explique les choix qui ne se devinent pas, et
liste ce qui reste à vérifier.

Version portée : `dahu-migrate` 2.0.2.
Version publique : 1.0.0.

---

## 1. Ce qui a été porté, et ce qui ne l'a pas été

`dahu-migrate` n'est pas une extension, c'en est **trois** dans un dossier :

| # | Outil | Sort |
|---|---|---|
| 1 | Transfert du contenu éditorial staging → prod (`exporter`, `simuler`, `importer`, `restaurer`) | **non porté** — autre produit, voir §8 |
| 2 | Import/export CSV du contenu des fiches produit (`importer-fiches`, `exporter-fiches`, `diagnostiquer-fiches`) | **c'est cette extension** |
| 3 | Déménagement intra-base de la refonte Annad (`inventaire`, `importer-medias`, `importer-images-produits`, `importer-formulaires`, `importer-kits`, `importer-categories`, `importer-pages`, `importer-reglages`, `plan`, `idmap`) | **jamais porté** — une procédure, pas un produit |

### Pourquoi le n°2

Le nom `dahu-product-import`, décidé dans `CORRESPONDANCE-ANNAD.md`, désigne
sans ambiguïté le n°2. Le n°1 serait un `dahu-content-sync` ; le n°3 n'a aucune
vie hors d'annad.fr — il lit une autre base par préfixe de table, connaît
Contact Form 7, les kits du configurateur, les widgets et le Personnaliser du
thème Annad.

C'était une décision de produit, et elle a été prise sur ce seul fondement
plutôt que devinée : trois extensions très différentes sortent des trois
lectures possibles.

### Structure

```
dahu-product-import.php                       amorce : constantes, autoload, i18n, CLI, admin
includes/
  class-…-fields.php         registre des champs ← LE point de configuration
  class-…-csv.php            lecture et écriture du tableur
  class-…-sanitize.php       nettoyage, comparaison, aperçus
  class-…-attributes.php     la colonne des attributs
  class-…-planner.php        la planification (simulation ET import)
  class-…-report.php         le rapport
  class-…-importer.php       la seule classe qui écrit en base
  class-…-exporter.php       site → tableur
  class-…-rollback.php       restauration
  class-…-diagnostics.php    traçage d'une valeur, lecture seule
  class-…-workspace.php      dossier de travail
  class-…-cli.php            les cinq commandes WP-CLI
  class-…-admin.php          écran d'information
uninstall.php
```

4 256 lignes, contre 2 825 pour les six classes source retenues
(`fiches` 1 797, `restorer` 406, `report` 217, `workspace` 210, `config` 129,
`admin` 66). L'écart vient du registre de champs — qui n'existait pas —, de la
densité de commentaires, et du découpage d'un fichier de 1 797 lignes en neuf.

---

## 2. La dé-spécialisation : un registre de champs

C'est tout le portage.

La version Annad connaissait **les champs du thème Annad**, écrits dans deux
méthodes statiques : `columns()` listait 38 colonnes
(`sous_titre`, `installation_usages`, `technologie`, `avantage_1..4_titre/_texte`,
`faq_intro`, `faq_1..6_question/_reponse`, `video_url`, `notice_url`,
`configurateur_id`) et `mapping()` associait chacune à une meta `_annad_*` et à
une « nature » de nettoyage.

Tout le reste du fichier — lecture du CSV, planification, rapport, import,
sauvegarde, export, diagnostic — ne connaissait déjà **que** ces deux méthodes.
La généralisation était donc là, et seulement là.

### Le registre

`Dahu_Product_Import_Fields::all()` rend `colonne => déclaration`, construit à
partir d'un filtre :

```php
add_filter( 'dahu_product_import_fields', function ( $fields ) {
    $fields['subtitle'] = array( 'target' => '_my_subtitle', 'nature' => 'text' );
    return $fields;
} );
```

| Clé | |
|---|---|
| `target` | `post:<champ>` ou une clé meta |
| `nature` | `text`, `textarea`, `title`, `html`, `excerpt`, `url`, `product_id` |
| `max_length` | en caractères ; dépassement = **ligne rejetée** |
| `required` | cellule vide = ligne rejetée |

Trois champs sont **intégrés** : `title`, `description`, `short_description`.
Ils existent dans toute boutique WooCommerce, et une extension qui ne fait rien
avant d'être configurée se fait désinstaller avant d'être comprise.

### Ce que les constantes Annad sont devenues

| Annad | Public |
|---|---|
| `AVANTAGE_TEXTE_MAX = 55` appliqué par un `preg_match( '/^avantage_\d+_texte$/' )` | `max_length` par champ |
| `FAQ_MAX`, `AVANTAGE_MAX` (boucles de génération de colonnes) | les colonnes sont déclarées une par une |
| `FAQ_V2 = '_annad_faq_v2'` et sa logique de marqueur | action `dahu_product_import_plan_row` (§3) |
| garde « kit du configurateur » (type `dahu_kit` + table d'ID) | filtre `dahu_product_import_excluded_product_types` |
| nature `int` vérifiant que la cible est un produit **composite** | nature `product_id` : un produit de ce site, le type ne regarde pas l'extension |
| `Dahu_Migrate_Config::product_meta_keys()` (liste blanche de 25 metas) | inutile : rien ne voyage, c'est le registre qui dit quoi écrire |

### Une déclaration cassée est écartée, pas tolérée

Sans `target`, avec une `nature` inconnue, ou visant une colonne réservée
(`product_id`, `slug`, `sku`, `type`, `category`, `attributes`) : écartée, et
journalisée sous `WP_DEBUG_LOG`.

Tolérer une déclaration incomplète donnerait une colonne qui **paraît**
configurée, apparaît dans l'en-tête, et n'écrit nulle part — indiscernable,
vue du tableur, d'une colonne que personne n'a remplie. Et laisser un filtre
redéfinir `product_id` ferait apparier une ligne à un produit que personne
n'avait en tête.

---

## 3. L'action qui remplace le marqueur FAQ

La version Annad posait `_annad_faq_v2 = '1'` dès qu'une question était
renseignée, et ne le retirait jamais (sinon l'ancienne FAQ réapparaissait).
C'est une règle du thème, pas une colonne.

D'où `do_action_ref_array( 'dahu_product_import_plan_row', array( &$item, $row, $id, $wanted ) )`,
à la fin de la planification d'une ligne. Le thème ajoute à `$item['changes']`
pour le rapport et à `$item['values']` pour l'écriture.

Contrat explicite : **rien ne doit toucher la base** dans ce hook. Il s'exécute
aussi pendant la simulation, et c'est toute la promesse d'une simulation.

Le passage par référence impose `do_action_ref_array` : un filtre PHP ne passe
pas par référence, et un filtre qui rendrait `$item` obligerait chaque
souscripteur à le reconstruire entier.

---

## 4. Les pièges conservés avec leur justification

### 4.1 Pourquoi pas `sanitize_text_field()`

Elle convertit un « < » isolé en « &lt; » (`wp_pre_kses_less_than`), ce qui
corrompt une valeur technique légitime (« <50dB »), et supprime tout un
fragment quand un « > » suit plus loin sur la ligne. L'échappement appartient à
l'affichage. `Sanitize::text()` nettoie sans échapper.

Le banc vérifie à la fois que `<50dB` survit **et** que
`sanitize_text_field()` l'aurait abîmé — sans quoi le test ne prouverait rien.

### 4.2 Les trois façons dont une cellule ne change rien

C'est la propriété centrale (`Sanitize::unchanged()`) :

1. la cellule brute vaut la valeur du site — aller-retour sans retouche ;
2. la cellule nettoyée vaut la valeur du site ;
3. **nettoyer la valeur du site donne la cellule nettoyée** — l'écart ne porte
   que sur ce que le nettoyage normalise (`style="…;"`, `<hr/>`, un attribut
   non autorisé).

Perdre le 3 suffit à ce que le premier import suivant un export réécrive une
large part du catalogue avec la version blanchie de ce qui y était déjà, et
noie les vrais changements dedans.

### 4.3 Le SKU, quatre règles

Une cellule vide **ne vide jamais** un SKU — seule colonne qui échappe à
« cellule vide = champ vidé ». Un SKU absent du tableur est un oubli ; un SKU
effacé casse l'historique des commandes, les exports et les étiquettes.

Un doublon rejette la **ligne entière**, vérifié avant toute écriture. Et le
SKU s'écrit par l'API WooCommerce, jamais en meta directe : la table
`wc_product_meta_lookup` resterait sinon sur l'ancienne valeur — le SKU
s'afficherait correctement sur le produit et ne trouverait rien à la recherche.
Le banc vérifie `wc_get_product_id_by_sku()` après écriture, précisément pour
ça.

### 4.4 La colonne des attributs ne touche pas à trois choses

Attributs globaux (`pa_*`), attributs de variation, attributs locaux masqués.
Une ligne qui en désigne un est **signalée** et ignorée.

Le signalement est le point : ignorer en silence laisse quelqu'un rééditer la
même cellule la semaine suivante.

### 4.5 La garde du slug

L'ID correspond, mais les ID sont réattribués quand un produit est supprimé et
un autre créé, et un tableur vit des mois. Sans la garde, un fichier périmé
écraserait le produit qui a hérité de l'ID — avec un contenu parfaitement
plausible.

### 4.6 `kses_remove_filters()` pendant l'import

Les valeurs sont déjà nettoyées explicitement selon la nature de chaque champ.
Un second passage de kses les filtrerait selon le **rôle** de l'utilisateur
CLI : le même fichier s'importerait différemment pour un administrateur et pour
un éditeur, et la relecture signalerait des écarts qui seraient ce filtrage.

### 4.7 La relecture après écriture

Une extension qui filtre le contenu à l'enregistrement stocke autre chose que
ce qu'on a demandé, et le seul moyen de le savoir est de regarder. Signalé à
l'import, et conséquence voulue : les empreintes de la restauration ne
correspondront pas non plus, donc elle exigera `--force` au lieu de refuser
sans raison visible.

### 4.8 Le dossier de travail hors racine web

Imposé, pas recommandé. La sauvegarde contient le texte précédent de chaque
produit touché, sous un nom devinable. Servie en HTTP, c'est une copie gratuite
du travail éditorial d'une boutique, prise sans trace. Les répertoires parents
sont testés aussi : un staging imbriqué dans la prod serait servi par le site
parent.

---

## 5. Correctifs apportés au passage

Trois défauts de `dahu-migrate`, dont deux trouvés par le banc.

### 5.1 Une URL invalide n'était pas détectée (sérieux)

```php
$url = esc_url_raw( $raw, array( 'http', 'https' ) );
return ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) ) ? $url : '';
```

`esc_url_raw()` ne fait pas que nettoyer : sur une chaîne nue, elle **préfixe
un schéma**. `« not a url »` revient en `http://not%20a%20url`, dont l'hôte est
`not%20a%20url` — parfaitement non vide. Le contrôle d'hôte passait donc, et
une cellule contenant une phrase était enregistrée comme URL : lien mort sur la
fiche, et la simulation l'annonçait comme un changement de valeur ordinaire.

Correctif : le test porte sur les **caractères de l'hôte**. Un pour cent ou une
espace ne peuvent pas y figurer, et une phrase en contient toujours un. Le non-ASCII
passe — un domaine internationalisé est une vraie adresse.

Délibérément **pas** `filter_var( FILTER_VALIDATE_URL )` : elle rejette les
domaines internationalisés.

Et délibérément **aucune exigence de point** dans l'hôte. Un hôte d'un seul
label est inhabituel dans un tableur de boutique, et probablement une faute —
mais `localhost` et les noms d'intranet sont réels, et une URL invalide
**rejette la ligne entière**, donc aucun texte de ce produit ne serait importé.
Un lien mort est visible et se corrige ; une ligne refusée pour une raison
surprenante coûte une demi-journée. Arbitrage assumé, et consigné dans le
docblock.

### 5.2 Deux imports dans la même seconde partageaient leur sauvegarde (trouvé par le banc)

`Workspace::stamp()` est `gmdate( 'Ymd-His' )`, résolution d'une seconde, et
`open_append()` **ajoute**. Deux imports lancés dans la même seconde écrivaient
donc dans le même fichier de sauvegarde. La commande annonce ce fichier comme
celui de la restauration, et le restaurer **annule les deux imports**, en
silence, vers un état que personne n'a demandé.

Il faut un script, ou une reprise rapide, pour y arriver. Ce n'est pas assez
rare dans un outil dont tout l'intérêt est d'être réversible.

Correctif : `Workspace::reserve()` crée le premier fichier en mode `'xb'` —
qui échoue s'il existe — et incrémente un suffixe jusqu'à en trouver un libre.
La réponse vient du système de fichiers, pas d'un test qui pourrait être
devancé entre le regard et la création. Les deux fichiers d'un même import
partagent le suffixe retenu.

Le défaut existe à l'identique dans `dahu-migrate`.

### 5.3 `strip_tags()` → `wp_strip_all_tags()`

Dans la comparaison de longueur de `sanitization_losses()`. `strip_tags()`
laisse le **contenu** des balises `script` et `style` ; la comparaison était
donc faussée sur un extrait en contenant.

---

## 6. Vérification

### 6.1 Deux comparateurs, jamais un

`_harness/fnskel.php` compare le squelette de jetons d'**une** fonction nommée,
table de renommage appliquée. Dix-huit fonctions reportées :

| | |
|---|---|
| Identiques au token près | 5 / 18 |
| Différentes, écarts tous vérifiés un par un | 13 / 18 |

Les écarts, exhaustivement : le retrait de la délégation aux fonctions du thème
Annad (`annad_theme_sanitize_specs_text`, `annad_theme_parse_specs_lines` — le
repli devient la seule implémentation, identique au token près), l'enveloppement
`__()` de onze messages (ce qui transforme des concaténations en `sprintf`), les
changements de nom de classe dus au déplacement du code, des renommages de
variables, trois extractions de sous-expression dans une variable locale, un
helper `looks_like_html()` partagé, et les deux correctifs du §5.1 et §5.3.

`_harness/strings.php` — **nouveau, et le pendant PHP du comparateur de
balisage** — liste les littéraux de chaîne qui constituent un **contrat** :
clés meta, champs `post:`, noms de taxonomie et de statut, formats `printf`,
séquences d'octets. Il existe pour la même raison que son homologue JavaScript :
le comparateur de jetons imprime `STR` à la place de chaque chaîne, donc une
clé meta changée d'un caractère le traverse proprement — et le champ écrit
ensuite dans une clé que rien ne lit, sans erreur nulle part.

Résultat : **tous** les contrats WordPress et WooCommerce se retrouvent —
`_sku`, les trois cibles `post:post_title` / `post_content` / `post_excerpt`,
`product`, `product_cat`, `product_type`, les sept statuts, et les neuf
séquences d'octets (BOM, insécables, caractères de largeur nulle de l'aperçu).
Les seuls contrats absents sont les quinze clés `_annad_*` — c'est l'objet du
portage — et six clés internes renommées en anglais.

### 6.2 Banc fonctionnel

`_bench/t-product-import.php`, **179 tests, 0 échec**, idempotent sur trois
exécutions, contre un vrai WordPress 7.1.3 + WooCommerce 11.1.2 et une vraie
base. Treize sections : registre, lecture du fichier, nettoyage, attributs,
planification, lignes refusées, règles du SKU, aller-retour, import réel,
l'action d'un thème, restauration, dossier de travail, rapport.

La section 8 est celle qui compte : **exporter puis importer sans rien
modifier n'écrit rien**, y compris avec un séparateur point-virgule et avec du
contenu choisi pour éprouver le nettoyage (HTML que kses normalise, insécable,
valeur technique à chevrons).

Le banc a trouvé le défaut du §5.2, qu'aucune analyse statique ne pouvait voir.

Il a aussi coûté une leçon déjà apprise : ses sections tardives importent pour
de vrai, donc modifient les produits que les premières comparent. Première
exécution verte, seconde rouge, et les échecs ressemblaient exactement à des
régressions. D'où `pi_reset()`, qui ramène chaque produit à un état connu au
démarrage — en vidant **tous** les SKU dans une passe à part avant d'en
réécrire aucun, sinon l'ordre de la boucle décide si l'écriture lève une
exception pour doublon.

### 6.3 Harnais statiques

`test-conformite.php`, `test-i18n.php`, `test-i18n-assets.php` : tous `SUCCES`.
Zéro alerte PHP dans le journal après une exécution complète du banc.

---

## 7. Langue des textes

| Nature | Traitement |
|---|---|
| Écran d'administration, rapport, sortie WP-CLI, messages d'erreur | anglais **et** `__()` / `_n()` |
| Lignes de `write_log()` | anglais, **non** encadrées |
| Sortie de `trace` (diagnostic) | anglais, **non** encadrée |

Les deux dernières suivent la même règle, et le `trace` mérite son explication :
c'est de l'octet, de l'hexadécimal, une version de PHP et une locale, lu pendant
un incident et généralement recollé à quelqu'un d'autre — un développeur, le
support d'un hébergeur. La moitié ne se traduit pas du tout, et un rapport dont
les en-têtes suivent la langue de l'admin alors que les mesures restent en
anglais se lit plus mal qu'un rapport entièrement en anglais.

Les **noms de colonnes** ne sont pas traduisibles non plus, et c'est une
décision : une colonne dont le nom changerait avec la langue de
l'administration casserait tous les fichiers enregistrés avant le changement.

---

## 8. Le n°1, non porté : un produit à part

Le transfert staging → prod de `dahu-migrate` est un bon produit, et il n'est
pas celui-ci. Ce qu'il fait et que personne d'autre ne fait bien :

- appariement par ID confirmé par slug ou par chemin complet ;
- détection de conflit d'ID : un ID pris en prod par un autre objet n'est
  jamais réutilisé, WordPress en attribue un nouveau, et **toutes** les
  références sont réécrites (attributs de blocs, classes `wp-image-N`,
  `data-id`, image mise en avant, galerie) ;
- réécriture des adresses du staging (absolues, relatives au protocole,
  échappées en JSON, relatives à la racine) ;
- simulation obligatoire produisant un jeton, et import qui refuse de tourner
  si la prod a changé depuis ;
- détection des modifications faites en prod après la date de clonage.

Il partagerait avec cette extension le dossier de travail, le format de
sauvegarde JSONL et la restauration. Un portage éventuel devrait extraire ces
trois-là plutôt que les dupliquer — ou bien les deux extensions divergeront à
la première évolution.

Nom suggéré : `dahu-content-sync`. Pas commencé.

---

## 9. Points à vérifier sur une vraie installation

Le banc couvre la logique. Ceux-ci demandent une vraie boutique.

1. **Un gros catalogue.** Le banc en a dix produits. `export` charge tous les ID
   d'un coup (`numberposts => -1`) et construit le CSV en mémoire : à vérifier
   sur plusieurs milliers de produits, mémoire PHP comprise.
2. **Un tableur réel.** Excel, LibreOffice et Google Sheets ne sauvegardent pas
   le même CSV. Le banc écrit ses fichiers avec `fputcsv()`, donc il teste le
   format de PHP, pas celui d'Excel. L'aller-retour complet — export, ouverture
   dans le tableur, enregistrement, import — est le test qui manque, et
   `trace` existe pour le diagnostiquer.
3. **Un thème déclarant ses champs.** Le banc en déclare quatre, synthétiques.
   À faire sur annad.fr avec les 38 colonnes réelles (§10).
4. **Les produits variables.** Le banc teste un produit simple portant un
   attribut de variation. Une vraie variable, avec ses variations, mérite une
   vérification : l'extension ne doit toucher ni les variations ni leurs SKU.
5. **La restauration après une vraie bascule**, avec des produits modifiés à la
   main entre-temps — le cas que `--force` couvre.
6. **L'écran d'administration** sur les deux schémas de couleurs les plus
   sombres : la table n'a reçu aucune revue visuelle.

---

## 10. Retrouver le périmètre Annad

Les 38 colonnes du thème Annad, à déclarer dans un snippet du thème ou dans une
extension d'accompagnement :

```php
add_filter(
    'dahu_product_import_fields',
    function ( $fields ) {

        $fields['sous_titre']               = array( 'target' => '_annad_sous_titre', 'nature' => 'text' );
        $fields['installation_usages']      = array( 'target' => '_annad_installation', 'nature' => 'html' );
        $fields['technologie']              = array( 'target' => '_annad_technologie', 'nature' => 'html' );
        $fields['caracteristiques_legende'] = array( 'target' => '_annad_caracteristiques_legende', 'nature' => 'textarea' );
        $fields['faq_intro']                = array( 'target' => '_annad_faq_intro', 'nature' => 'textarea' );
        $fields['video_url']                = array( 'target' => '_annad_video_url', 'nature' => 'url' );
        $fields['notice_url']               = array( 'target' => '_annad_notice_url', 'nature' => 'url' );
        $fields['configurateur_id']         = array( 'target' => '_annad_configurateur_id', 'nature' => 'product_id' );

        for ( $i = 1; $i <= 4; $i++ ) {
            $fields[ 'avantage_' . $i . '_titre' ] = array( 'target' => '_annad_avantage_' . $i . '_titre', 'nature' => 'text' );
            $fields[ 'avantage_' . $i . '_texte' ] = array(
                'target'     => '_annad_avantage_' . $i . '_texte',
                'nature'     => 'text',
                'max_length' => 55,
            );
        }

        for ( $i = 1; $i <= 6; $i++ ) {
            $fields[ 'faq_' . $i . '_question' ] = array( 'target' => '_annad_faq_' . $i . '_question', 'nature' => 'text' );
            $fields[ 'faq_' . $i . '_reponse' ]  = array( 'target' => '_annad_faq_' . $i . '_reponse', 'nature' => 'textarea' );
        }

        return $fields;
    }
);

// Les kits du configurateur sont traités ailleurs : une ligne qui en désigne un
// est refusée, avec sa raison, au lieu d'être écrite ici ET là-bas.
add_filter(
    'dahu_product_import_excluded_product_types',
    function () {
        return array( 'dahu_kit' );
    }
);

// Le marqueur de FAQ par champs : posé dès qu'une question est renseignée,
// jamais retiré — sinon l'ancienne FAQ réapparaîtrait sur la fiche.
add_action(
    'dahu_product_import_plan_row',
    function ( &$item, $row, $id, $wanted ) {

        $has_question = false;

        for ( $i = 1; $i <= 6; $i++ ) {
            // $wanted est indexé par CIBLE (la meta), et chaque entrée porte
            // les clés column, nature, raw et value.
            if ( '' !== (string) ( $wanted[ '_annad_faq_' . $i . '_question' ]['value'] ?? '' ) ) {
                $has_question = true;
            }
        }

        if ( ! $has_question || '1' === (string) get_post_meta( $id, '_annad_faq_v2', true ) ) {
            return;
        }

        $item['changes'][] = array(
            'column' => '(faq)',
            'target' => '_annad_faq_v2',
            'action' => 'add',
            'before' => '',
            'after'  => '1',
        );

        $item['values']['meta']['_annad_faq_v2'] = '1';
    },
    10,
    4
);
```

**Différences de comportement à connaître avant une bascule :**

- Les colonnes changent de nom en anglais pour les trois champs intégrés :
  `titre` → `title`, `description_courte` → `short_description`,
  `caracteristiques` → `attributes`, `id_produit` → `product_id`. Un fichier
  Annad existant doit voir son en-tête renommé.
- La colonne `categorie` reste informative, comme avant.
- `configurateur_id` ne vérifie plus que la cible est un produit **composite** :
  elle vérifie que c'est un produit de ce site. Le type d'un produit lié ne
  regarde pas une extension d'import.
- L'avertissement « l'ancienne FAQ `_annad_faq` existe encore » n'est pas
  porté : c'est une règle du thème, et le hook ci-dessus est l'endroit où la
  remettre si elle est voulue.
