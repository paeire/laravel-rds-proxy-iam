# Changelog

All notable changes to `paeire/laravel-rds-proxy-iam` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `mysql-iam-proxy` database driver that authenticates to AWS RDS / RDS Proxy using IAM
  database authentication, generating a short-lived IAM token instead of a static password.
- TLS support via `ssl_ca` / `ssl_verify`, connection `connect_timeout`, `force_readonly`
  session hardening, and arbitrary `session_init_statements`.
- Order-independent driver registration: the connector is bound as
  `db.connector.mysql-iam-proxy` and the connection is registered through
  `Connection::resolverFor()`, so the driver is available no matter when the database is
  first resolved. The PDO (and therefore the IAM token) is created lazily on first use.

[Unreleased]: https://github.com/paeire/laravel-rds-proxy-iam/commits/main
