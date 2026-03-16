---
name: wp-webmaster
description: >
  Use this skill for any WordPress webmaster task: modifying pages or post content,
  editing theme files (theme.json, template parts, header, footer), managing navigation
  menus, installing or configuring plugins, importing media, updating site settings,
  or running complex PHP operations on the WordPress installation.
  Trigger phrases: "modifie la page", "change le contenu", "mets à jour le header",
  "ajoute un plugin", "change le menu", "modifie le footer", "theme.json",
  "template part", "wordpress", "wp", "site", "page", "plugin", "média", "option".
version: 1.0.0
---

# WordPress Webmaster

Skill pour effectuer toute intervention sur une installation WordPress via le plugin MCP `mcp-wordpress`.

---

## Règles fondamentales

> Ces règles s'appliquent à **toutes** les opérations, sans exception.

1. **Lire avant d'écrire** — toujours récupérer l'état actuel avant toute modification.
2. **Vérifier après chaque action** — confirmer le résultat avec un GET ou une lecture fichier.
3. **Ne jamais utiliser `parse_blocks()` + `serialize_blocks()`** sur du contenu Gutenberg écrit manuellement — cela corrompt les blocs.
4. **Manipuler le contenu des blocs par string** (regex, str_replace, preg_replace) — jamais via le parseur PHP de WordPress.
5. **Flusher le cache** après toute modification structurelle (template, menus, options, permaliens).
6. **Confirmer avec l'utilisateur** avant toute opération destructive (suppression, écrasement de contenu existant).

---

## Outils disponibles

Le plugin `mcp-wordpress` expose 10 abilities. Les voici avec leur usage optimal.

### `wp-rest/request`
Proxy universel REST API. Couvre **toutes les opérations CRUD** sur les ressources WordPress.
```
method: GET | POST | PUT | PATCH | DELETE
route:  /wp/v2/pages | /wp/v2/posts | /wp/v2/media | /wp/v2/settings
        /wp/v2/template-parts | /wp/v2/templates | /wp/v2/menus | /wp/v2/menu-items
params: { title, content, status, meta, ... }
```
- GET → lecture, listing, recherche
- POST → création
- PUT/PATCH → mise à jour partielle ou complète
- DELETE + `force:true` → suppression définitive

### `wp-rest/list-routes`
Découvrir tous les endpoints REST disponibles. **Utiliser en premier** sur un site inconnu.
```
namespace: "wp/v2"    → filtrer par namespace
search:    "template" → filtrer par substring
```

### `wp-cli/execute`
Commandes WP-CLI pour les opérations hors REST : cache, cron, search-replace, import, db…
```
command: "cache flush"
command: "rewrite flush"
command: "media import /path/to/file.jpg --title='Mon image'"
command: "post list --post_type=page --format=json"
command: "plugin install woocommerce --activate"
```
⚠️ Ne pas inclure le préfixe `wp`.

### `wp-php/eval`
Exécuter un script PHP complet dans le contexte WordPress. Pour les opérations complexes ou en masse.
```php
// Accès à $wpdb, tous les hooks, fonctions WP, plugins actifs
echo wp_insert_post([...]);
$wpdb->query("UPDATE ...");
```
- Code lancé dans un subprocess isolé (die/exit ne crashent pas le serveur MCP)
- Utiliser `echo` / `print` pour produire du output
- La balise `<?php` est optionnelle

### `wp-admin/manage-option`
Lire/écrire/supprimer les options WordPress.
```
action: get | update | delete
name:   blogname | blogdescription | page_on_front | show_on_front | permalink_structure
```

### `wp-admin/search`
Recherche unifiée cross-content (pages, posts, médias, termes, utilisateurs).
```
query:   "contact"
type:    post | term | post-format
subtype: page | post | category | tag
```

### `wp-admin/get-post-types` / `wp-admin/get-taxonomies`
Découvrir les post types et taxonomies enregistrés avec leur `rest_base`. Utile sur les sites avec des CPT custom.

### `wp-filesystem/read-file`
Lire un fichier du thème ou de WordPress.
```
base: theme | parent-theme | content | root
path: "theme.json" | "parts/header.html" | "style.css"
```
- `theme` → thème enfant actif (`get_stylesheet_directory()`)
- `parent-theme` → thème parent

### `wp-filesystem/write-file`
Écrire dans le thème ou wp-content. **Écriture sur `root` bloquée.**
```
base:        theme | parent-theme | content
path:        "theme.json"
content:     "..."
create_dirs: true   (défaut)
```

---

## Workflow standard

Toute intervention suit ces 4 étapes :

```
1. DÉCOUVRIR   → lire l'état actuel
2. PLANIFIER   → identifier ce qui doit changer, confirmer si destructif
3. EXÉCUTER    → appliquer la modification
4. VÉRIFIER    → confirmer que le résultat est correct
```

---

## Playbooks par type de tâche

### Pages & contenu

**Lire une page**
```
wp-admin/search → query: "nom de la page", subtype: "page"
→ récupérer l'ID
wp-rest/request → GET /wp/v2/pages/{id}?context=edit
→ lire post_content (blocs Gutenberg en HTML commenté)
```

**Modifier le contenu d'une page**
```
1. GET /wp/v2/pages/{id}?context=edit           → lire le contenu actuel
2. Modifier le contenu (string manipulation)     → NE PAS utiliser parse_blocks
3. PUT /wp/v2/pages/{id} { content: "..." }      → enregistrer
4. GET /wp/v2/pages/{id} { _fields: "content" } → vérifier
```

**Créer une page**
```
POST /wp/v2/pages {
  title:  "Titre",
  content: "<!-- wp:paragraph -->...",
  status: "publish",
  slug:   "mon-slug"
}
→ noter l'ID retourné
```

**Supprimer une page**
```
→ Confirmer avec l'utilisateur d'abord
DELETE /wp/v2/pages/{id}?force=true
```

---

### Thème FSE — template parts (header, footer, …)

Les template parts FSE ont une **priorité DB > fichier** :
- Le fichier `parts/header.html` est le fallback source-contrôlé
- Une entrée dans `wp_template_part` (créée via l'Éditeur de site ou REST) l'emporte

**Lire l'état actuel**
```
wp-filesystem/read-file { base: "theme", path: "parts/header.html" }
→ ET vérifier s'il y a une DB override :
wp-rest/request GET /wp/v2/template-parts?per_page=100
→ chercher slug: "header" ou "footer"
```

**Modifier un template part (approche recommandée : fichier)**
```
1. wp-filesystem/read-file { base: "theme", path: "parts/header.html" }
2. Préparer le nouveau contenu (string manipulation sur le HTML de blocs)
3. wp-filesystem/write-file { base: "theme", path: "parts/header.html", content: "..." }
4. Supprimer l'éventuelle DB override :
   wp-rest/request GET /wp/v2/template-parts → chercher l'ID de la DB override
   wp-rest/request DELETE /wp/v2/template-parts/{id}?force=true
5. wp-cli/execute "cache flush"
6. wp-filesystem/read-file → relire pour vérifier
```

**Format des blocs Gutenberg**
```html
<!-- wp:group {"align":"full","style":{...},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">
  <!-- wp:paragraph -->
  <p>Contenu</p>
  <!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```
- Chaque bloc = commentaire JSON d'ouverture + HTML + commentaire de fermeture
- Ne JAMAIS passer par `parse_blocks()` / `serialize_blocks()` en PHP
- Manipuler comme du texte (str_replace, preg_replace)

---

### theme.json — couleurs, typographie, espacement

**Modifier la palette de couleurs ou les tokens de design**
```
1. wp-filesystem/read-file { base: "theme", path: "theme.json" }
2. Parser le JSON, modifier les valeurs voulues
3. wp-filesystem/write-file { base: "theme", path: "theme.json", content: json_encode(...) }
4. wp-cli/execute "cache flush"
5. wp-filesystem/read-file → relire le JSON pour vérifier
```

Structure clé de `theme.json` :
```json
{
  "settings": {
    "color": { "palette": [...] },
    "typography": { "fontSizes": [...], "fontFamilies": [...] },
    "spacing": { "spacingSizes": [...] }
  },
  "styles": { "color": {}, "typography": {} }
}
```

---

### Menus de navigation

**Lister les menus existants**
```
wp-rest/request GET /wp/v2/menus
```

**Modifier les items d'un menu**
```
1. wp-rest/request GET /wp/v2/menu-items?menus={menu_id}&per_page=100
2. Pour chaque item à modifier :
   wp-rest/request PATCH /wp/v2/menu-items/{item_id} { title, url, ... }
3. Pour ajouter un item :
   wp-rest/request POST /wp/v2/menu-items { title, url, menus: {menu_id}, menu_order: N }
4. Pour supprimer :
   wp-rest/request DELETE /wp/v2/menu-items/{item_id}?force=true
5. wp-cli/execute "cache flush"
```

⚠️ Dans un thème FSE, la navigation peut être inline dans les template parts (blocs `wp:navigation-link`). Dans ce cas, éditer le fichier template part est plus simple que les menus REST.

---

### Plugins

**Lister les plugins installés**
```
wp-rest/request GET /wp/v2/plugins
```

**Installer et activer un plugin**
```
1. wp-rest/request GET /wp/v2/plugins?search=nom  → vérifier s'il est déjà installé
2. wp-rest/request POST /wp/v2/plugins {
     slug:   "nom-du-plugin",   (slug WordPress.org)
     status: "active"
   }
→ Si l'installation REST échoue (permissions), fallback :
wp-cli/execute "plugin install nom-du-plugin --activate"
```

**Désactiver / supprimer**
```
wp-rest/request PUT /wp/v2/plugins/{plugin} { status: "inactive" }
wp-cli/execute "plugin delete nom-du-plugin"
```

---

### Médias

**Importer une image déjà présente dans le container**
```
wp-cli/execute "media import /var/www/html/web/app/uploads/mon-image.jpg --title='Titre'"
→ noter l'ID retourné
```

**Récupérer l'URL d'un média existant**
```
wp-rest/request GET /wp/v2/media?search=nom&per_page=10
→ lire source_url dans la réponse
```

**Définir l'image mise en avant d'une page**
```
wp-rest/request PUT /wp/v2/pages/{id} { featured_media: {media_id} }
```

---

### Options & réglages

**Options courantes**
```
wp-admin/manage-option get  { name: "blogname" }
wp-admin/manage-option update { name: "blogname", value: "Nouveau titre" }
```

**Réglages REST (préférés pour les options standard)**
```
wp-rest/request GET  /wp/v2/settings
wp-rest/request POST /wp/v2/settings { title: "...", description: "...", ... }
```

**Définir la page d'accueil statique**
```
wp-admin/manage-option update { name: "show_on_front",  value: "page" }
wp-admin/manage-option update { name: "page_on_front",  value: 42 }   ← ID de la page
```

**Flusher les permaliens après tout changement de structure d'URL**
```
wp-cli/execute "rewrite flush"
```

---

### Opérations complexes avec wp-php/eval

Pour toute opération qui dépasse ce qu'un seul appel REST ou WP-CLI peut faire :

```php
// Exemple : mettre à jour 50 pages en masse
$pages = get_posts(['post_type'=>'page','numberposts'=>-1,'post_status'=>'publish']);
foreach ($pages as $page) {
    $content = $page->post_content;
    // manipulation string...
    $content = str_replace('ancien texte', 'nouveau texte', $content);
    wp_update_post(['ID' => $page->ID, 'post_content' => $content]);
    echo "Updated: {$page->ID} — {$page->post_title}\n";
}
echo "Done. " . count($pages) . " pages updated.";
```

**Bonnes pratiques wp-php/eval :**
- Toujours `echo` un résumé à la fin pour confirmer ce qui a été fait
- Gérer les erreurs avec try/catch sur les opérations critiques
- Commencer par un mode "dry-run" (echo sans modifier) si l'opération est risquée

---

## Vérification systématique

Après chaque modification, toujours vérifier :

| Type de modification | Vérification |
|---|---|
| Page / post content | `GET /wp/v2/pages/{id}?_fields=content` |
| Template part fichier | `wp-filesystem/read-file` sur le chemin écrit |
| theme.json | `wp-filesystem/read-file` + vérifier la couleur/valeur modifiée |
| Option WordPress | `wp-admin/manage-option get { name: "..." }` |
| Plugin installé | `GET /wp/v2/plugins` → chercher le slug |
| Menu | `GET /wp/v2/menu-items?menus={id}` → lister les items |
| Média importé | `GET /wp/v2/media/{id}` → vérifier source_url |

---

## Guardrails — ce qu'il ne faut PAS faire

- ❌ **Ne pas utiliser `parse_blocks()` + `serialize_blocks()`** sur du contenu Gutenberg manuel → corrompt les blocs
- ❌ **Ne pas écrire dans `base:"root"`** (WordPress core) → bloqué par le plugin, et dangereux
- ❌ **Ne pas supprimer** sans confirmation utilisateur explicite
- ❌ **Ne pas flusher les permaliens** sans `wp-cli/execute "rewrite flush"` après un changement de structure
- ❌ **Ne pas ignorer les DB overrides** de template parts → toujours vérifier si une entrée `wp_template_part` existe en DB avant d'éditer le fichier
- ❌ **Ne pas faire confiance au cache** → toujours flusher après des changements structurels
- ❌ **Ne pas enchaîner POST + lecture immédiate** sans vérifier le status HTTP retourné d'abord

---

## Déroulement type d'une session

```
1. Comprendre la demande — reformuler pour confirmer
2. Explorer l'état actuel :
   - wp-admin/search ou wp-rest/request GET pour trouver les ressources concernées
   - wp-filesystem/read-file si des fichiers thème sont impliqués
3. Planifier les changements — les décrire à l'utilisateur avant d'agir
4. Exécuter — dans l'ordre logique, en vérifiant chaque étape
5. Confirmer — résumer ce qui a été modifié avec les IDs/chemins concernés
```
