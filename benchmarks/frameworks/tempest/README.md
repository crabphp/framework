# Tempest: excluded under the required PHP version

The current app skeleton is `tempest/app` v3.1.0. The current framework is `tempest/framework` v3.19.2, whose published Composer requirement is `php: ^8.5`. The task requires PHP 8.4 for PHP contenders. Composer on PHP 8.4.26 refuses that version; the retained attempt log records the conflict. No timing is published for an older version or an ignored PHP platform requirement.

Tempest documents FrankenPHP worker support starting with 3.14.0. That would be the intended deployment mode, with its `WorkerModeApplication`, two workers, persistent MySQL configuration, typed request validation, and generated discovery/config caches, if the runtime constraint were changed.

The compatibility probe used the published app skeleton and `composer require tempest/framework:3.19.2 --dry-run --no-scripts --update-no-dev --no-interaction --ignore-platform-req="ext-*"`. Ignoring extension checks here only isolates the PHP version conflict; it does not ignore PHP or install/run an unsupported application.
