# Changelog

All notable changes to `paeire/laravel-rds-proxy-iam` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-10-08

### Why connections through RDS Proxy failed

Up to 1.1.0 the driver was attached with `DatabaseManager::extend()`. That had four effects:

- In 1.0.x the extension was registered in `boot()`, so any provider that opened a
  `mysql-iam-proxy` connection from its `register()`, or from an earlier `boot()`, failed with
  `Unsupported driver [mysql-iam-proxy]`.
- In 1.1.0 the extension closure opened the PDO, and signed a token, as soon as the connection was
  resolved rather than on first query. It also bypassed Laravel's `ConnectionFactory`, so
  `read`/`write` hosts were ignored. A provider that touched the database from `register()` (for
  example a secrets loader backed by the `database` cache store) opened the connection with the
  configuration that existed before its own settings were applied.
- The `region` key was ignored (only `aws_region`/`AWS_REGION` were read). Tokens were signed for
  `us-east-1` unless `AWS_REGION` happened to be exported, and RDS rejected them.
- TLS was only enabled when `ssl_ca` was set, and nothing checked for it. AWS refuses IAM
  authentication through RDS Proxy without TLS, which surfaced as a generic access-denied error.

An expiring token was never the cause: a token is generated for every new connection, and Laravel's
lost-connection reconnect opens a new one.

### Added

- Per-process IAM token cache keyed by `token_host:token_port|region|username`. A token is reused
  for up to 10 minutes, or until 60 seconds before temporary credentials expire. A failed connection
  attempt discards it.
- The AWS credential provider is memoized for the life of the process, so IMDS/STS are only queried
  again once the credentials expire.
- Constructor injection on `IamMySqlConnector` for the credential provider, the token generator, the
  clock, and the default connection name.
- `require_tls` / `DB_REQUIRE_TLS`. It defaults to `true` when the token host is an RDS Proxy
  endpoint (`*.proxy-*.rds.amazonaws.com`); a proxy connection without TLS fails before any token
  is signed. Setting it to `false` on a proxy logs a warning instead.
- `region` is accepted as an alias of `aws_region`.
- Laravel 10 support is back (`illuminate/* ^10|^11|^12`); CI runs Testbench 8, 9 and 10.

### Changed

- The driver is registered in `register()`: the connector is bound as `db.connector.mysql-iam-proxy`
  and the connection through `Connection::resolverFor()`. The driver works no matter when the
  database is first resolved, and connections go through Laravel's `ConnectionFactory`, which
  creates the PDO lazily and supports read/write splits. The connector binding uses `singletonIf`,
  so an application can bind its own connector.
- `DB_*` environment fallbacks apply only to the default connection. Other connections read only
  their own configuration and no longer inherit the default connection's host, user or token
  endpoint.
- Connection errors are re-thrown as a `PDOException` that never contains the token: the message is
  redacted, and the original exception is not chained, because its stack-trace arguments carry the
  token. Code and `errorInfo` are preserved.

### Removed

- The `PDO::MYSQL_ATTR_DEFAULT_AUTH` option. pdo_mysql does not define that constant, so the branch
  never ran. `MYSQL_ENABLE_CLEARTEXT_PLUGIN` is still exported for libmysqlclient builds.

## [1.1.0]

### Changed

- The connector and the `extend()` hook are registered from `register()` through
  `afterResolving('db')`.

## [1.0.0]

### Added

- `mysql-iam-proxy` database driver that authenticates to AWS RDS / RDS Proxy using IAM database
  authentication, with TLS (`ssl_ca` / `ssl_verify`), `connect_timeout`, `force_readonly` and
  `session_init_statements`.

[Unreleased]: https://github.com/paeire/laravel-rds-proxy-iam/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/paeire/laravel-rds-proxy-iam/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/paeire/laravel-rds-proxy-iam/compare/v1.0.2...v1.1.0
[1.0.0]: https://github.com/paeire/laravel-rds-proxy-iam/releases/tag/v1.0.0
