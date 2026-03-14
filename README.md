# Caroline Trinel — WordPress

Site WordPress construit avec [Roots Bedrock](https://roots.io/bedrock/), conteneurisé avec Docker et déployé sur [Coolify](https://coolify.io/).

## Prérequis

- [Docker](https://docs.docker.com/get-docker/) & Docker Compose
- [Make](https://www.gnu.org/software/make/)

Aucune installation locale de PHP, Composer ou Node n'est nécessaire.

## Démarrage rapide

```bash
git clone <repo-url>
cd wp-caroline-trinel
make setup
```

| Service   | URL                  |
|-----------|----------------------|
| WordPress | http://localhost:8080 |
| Mailpit   | http://localhost:8025 |

## Commandes

Toutes les commandes passent par Docker via le Makefile.

```bash
make help           # Afficher toutes les commandes disponibles
```

Les principales :

```bash
make up             # Démarrer la stack
make down           # Arrêter la stack
make logs           # Suivre les logs
make shell          # Shell dans le conteneur
make composer c=""  # Exécuter Composer
make wp c=""        # Exécuter WP-CLI
make lint           # Vérifier le style (Pint)
make test           # Lancer les tests (Pest)
```

## Structure du projet

```
config/              # Configuration WordPress (Bedrock)
web/app/             # Thèmes, plugins, mu-plugins
web/wp/              # WordPress core (géré par Composer, ne pas modifier)
docker/              # Nginx, supervisord, entrypoint
tests/               # Tests (Pest)
```

## Environnement

Les variables de développement sont centralisées dans `.env.development` — c'est la source de vérité pour le dev local. Aucun fichier `.env` à créer manuellement.

Pour la production, voir `.env.example`.

## Déploiement

Le site se déploie sur Coolify via `docker-compose.yml`. La documentation technique complète (variables requises, architecture Docker, workflow de développement) se trouve dans [AGENTS.md](AGENTS.md).

## Licence

[MIT](LICENSE.md)