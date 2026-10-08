# laravel-rds-proxy-iam

Connect Laravel to **AWS RDS / RDS Proxy** using **IAM database authentication**. The package
adds a `mysql-iam-proxy` database driver that signs in with a short-lived IAM auth token instead
of a static password.

- No static database credentials in your app.
- Works with RDS Proxy and RDS directly (MySQL / Aurora MySQL).
- Order-independent registration — the driver is ready no matter when your app first resolves
  the database.
- Tokens and AWS credentials are cached per process and renewed before they expire.
- TLS is enforced for RDS Proxy endpoints, plus connection timeout, read-only hardening, and session
  bootstrap statements.

## Requirements

- PHP `^8.2`
- Laravel `10`, `11` or `12`
- `aws/aws-sdk-php` `^3.300` (installed automatically)

## Installation

```bash
composer require paeire/laravel-rds-proxy-iam
```

The service provider is auto-discovered — no manual registration required.

## Configuration

Add a connection using the `mysql-iam-proxy` driver in `config/database.php`:

```php
'connections' => [
    'mysql' => [
        'driver' => 'mysql-iam-proxy',

        'host' => env('DB_HOST'),
        'port' => env('DB_PORT', 3306),      // local port (tunnel / proxy)
        'database' => env('DB_DATABASE'),
        'username' => env('DB_USERNAME'),

        // Host/port used to sign the IAM token. Defaults to host/port above.
        'token_host' => env('DB_TOKEN_HOST', env('DB_HOST')),
        'token_port' => env('DB_TOKEN_PORT', 3306),
        'aws_region' => env('AWS_REGION', 'us-east-1'),   // `region` is accepted too
        'ssl_ca' => env('DB_SSL_CA'),                     // required for RDS Proxy endpoints

        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],
],
```

No `password` is set — it is replaced at connect time by an IAM token.

### AWS credentials

The IAM token is signed with the default AWS credential provider chain
(`Aws\Credentials\CredentialProvider::defaultProvider()`), so it works with environment
variables, an EC2/ECS/EKS instance role, or any standard AWS credential source. No AWS keys
are stored by this package. The provider is kept for the life of the process, so temporary
credentials (instance role, IMDS, STS) are only fetched again once they expire.

### AWS-side setup (once)

1. Enable **IAM database authentication** on the RDS instance / cluster (and on the RDS Proxy
   if used).
2. Create a database user that authenticates with the AWS plugin, for example:
   `CREATE USER 'iam_user'@'%' IDENTIFIED WITH AWSAuthenticationPlugin AS 'RDS';`
3. Grant the IAM principal permission to connect with an `rds-db:connect` policy scoped to that
   database user.

## How it works (load order)

Registration is **order-independent**. The connector is bound in the container as
`db.connector.mysql-iam-proxy` (which Laravel's `ConnectionFactory` resolves automatically),
and the connection itself is registered through `Illuminate\Database\Connection::resolverFor()`.
Because both live outside the resolved `db` manager, the driver is ready the moment the service
provider's `register()` runs — you do **not** need to reorder providers, even if another
provider resolves the database very early.

The PDO connection is created **lazily** on first query and goes through Laravel's own
`ConnectionFactory`, so read/write splits, `sticky`, and reconnects behave as with the stock
`mysql` driver. Both the read and the write PDO authenticate with IAM.

### Token lifetime

A token is only checked when a connection is opened; an established connection keeps working
after the token expires. Tokens are signed for 15 minutes and cached per process, keyed by
`token_host:token_port|region|username`, for at most 10 minutes — or until 60 seconds before the
signing credentials expire, if that comes first. A reconnect (for example after RDS Proxy drops an
idle client) reuses the cached token or signs a new one. A failed connection attempt discards the
cached token.

The connector is bound as a singleton, so the cache lives for the whole process: long-running
queue workers benefit most, while PHP-FPM rebuilds it on every request.

### Custom credentials

The connector accepts its collaborators through the constructor. Bind your own instance to use a
specific credential source:

```php
use Aws\Credentials\CredentialProvider;
use Paeire\RdsProxyIam\IamMySqlConnector;

$this->app->singleton('db.connector.mysql-iam-proxy', fn ($app) => new IamMySqlConnector(
    credentials: CredentialProvider::instanceProfile(),
    defaultConnection: fn () => $app['config']->get('database.default'),
));
```

## Options

Each option can be set on the Laravel connection array. The `DB_*` environment fallbacks only
apply to the **default** connection (`database.default`); any other connection reads its own array
and never inherits the default connection's host, user, or token endpoint. `AWS_REGION` applies to
every connection.

| Option | Env fallback | Default | Description |
| --- | --- | --- | --- |
| `host` | `DB_HOST` | — (required) | Host Laravel connects to (tunnel/proxy). |
| `port` | `DB_PORT` | `3306` | Port Laravel connects to. |
| `database` | `DB_DATABASE` | — | Default database. |
| `username` | `DB_USERNAME` | — (required) | IAM-enabled database user. |
| `token_host` | `DB_TOKEN_HOST` | `host` | Host used to sign the IAM token. |
| `token_port` | `DB_TOKEN_PORT` | `port` | Port used to sign the IAM token. |
| `aws_region` (or `region`) | `AWS_REGION` | `us-east-1` | Region for token signing. |
| `ssl_ca` | `DB_SSL_CA` | — | Path to a CA bundle for TLS. |
| `ssl_verify` | `DB_SSL_VERIFY` | `true` | Verify the server certificate. |
| `require_tls` | `DB_REQUIRE_TLS` | `true` for RDS Proxy endpoints | Refuse to connect without TLS. Set to `false` on a proxy only to bypass the check (a warning is logged). |
| `connect_timeout` | `DB_CONNECT_TIMEOUT` | `5` | PDO connect timeout (seconds). |
| `force_readonly` | `DB_FORCE_READONLY` | `false` | Force a read-only, safe-updates session. |
| `session_init_statements` | `DB_SESSION_INIT_STATEMENTS` | — | `;`-separated string or array of SQL to run on connect. |
| `enable_cleartext_plugin` | — | `true` | Export `MYSQL_ENABLE_CLEARTEXT_PLUGIN=1` for libmysqlclient builds of pdo_mysql. mysqlnd handles the cleartext auth switch without it. |

## Security notes

- The IAM token is never written to logs. Connection errors are re-thrown without it, including
  in stack-trace arguments when `zend.exception_ignore_args` is off.
- TLS is enabled by setting `ssl_ca` to the [Amazon RDS CA bundle](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html);
  keep `ssl_verify` enabled in production. AWS requires TLS for IAM authentication through RDS
  Proxy, so a `*.proxy-*.rds.amazonaws.com` token host without TLS fails fast with a clear error.
- The MySQL **cleartext auth plugin** is enabled because IAM authentication sends the token as a
  cleartext password over the (TLS-encrypted) connection. This is required by RDS IAM auth and
  is safe as long as TLS is used.

## Testing

```bash
composer install
composer test        # phpunit
composer analyse     # phpstan
composer format:test # pint --test
```

## Contributing

Issues and pull requests are welcome at
<https://github.com/paeire/laravel-rds-proxy-iam>.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
