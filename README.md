# PickSports

PickSports is a Laravel 12 application for sports data, predictions, betting analysis, and subscriptions. The main web application uses Inertia 2, Vue 3, TypeScript, Tailwind CSS 4, and Vite. A separate Vue frontend workspace lives in `frontend/`.

## Start here

| Task | Guide |
| --- | --- |
| Run the application locally | [Local development](docs/local-development.md) |
| Connect to production or call its API | [Production access](docs/operations/production-access.md) |
| Connect to the database | [Database access](docs/operations/database-access.md) |
| Find application and operational references | [Documentation index](docs/README.md) |
| Contribute code | [Engineering conventions](docs/engineering.md) |
| Work with a coding assistant | [Project instructions](AGENTS.md) |

The application requires PHP **8.5.x**, Composer, and Node.js/npm compatible with the locked frontend dependencies. CI uses Node.js 24. Use `composer install` and `npm ci` to install locked versions. See the local guide before running migrations or workers.

Production connection details and credentials come from authorized infrastructure access. Environment files, private keys, tokens, and database dumps do not belong in documentation or source control.
