# laravel-rds-proxy-iam

Connect Laravel to **AWS RDS / RDS Proxy** using **IAM database authentication**. The package
adds a `mysql-iam-proxy` database driver that generates a short-lived IAM auth token on every
connection instead of relying on a static password.

- No static database credentials in your app.
- Works with RDS Proxy and RDS directly (MySQL / Aurora MySQL).
- Order-independent registration — the driver is ready no matter when your app first resolves
  the database.
- TLS, connection timeout, read-only hardening, and session bootstrap statements built in.

## Requirements

- PHP `^8.2`
- Laravel `10`, `11`, or `12`
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
        'aws_region' => env('AWS_REGION', 'us-east-1'),

        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],
],
```

No `password` is set — it is replaced at connect time by a freshly generated IAM token.

### AWS credentials

The IAM token is signed with the default AWS credential provider chain
(`Aws\Credentials\CredentialProvider::defaultProvider()`), so it works with environment
variables, an EC2/ECS/EKS instance role, or any standard AWS credential source. No AWS keys
are stored by this package.

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

The PDO connection (and therefore the IAM token) is created **lazily** on first query, keeping
the token close to its use within its ~15-minute validity window and preserving Laravel's
reconnect and read/write connection behavior.

## Options

Each option can be set on the Laravel connection array and, as a fallback, via an environment
variable.

| Option | Env fallback | Default | Description |
| --- | --- | --- | --- |
| `host` | `DB_HOST` | — (required) | Host Laravel connects to (tunnel/proxy). |
| `port` | `DB_PORT` | `3306` | Port Laravel connects to. |
| `database` | `DB_DATABASE` | — | Default database. |
| `username` | `DB_USERNAME` | — (required) | IAM-enabled database user. |
| `token_host` | `DB_TOKEN_HOST` | `host` | Host used to sign the IAM token. |
| `token_port` | `DB_TOKEN_PORT` | `port` | Port used to sign the IAM token. |
| `aws_region` | `AWS_REGION` | `us-east-1` | Region for token signing. |
| `ssl_ca` | `DB_SSL_CA` | — | Path to a CA bundle for TLS. |
| `ssl_verify` | `DB_SSL_VERIFY` | `true` | Verify the server certificate. |
| `connect_timeout` | `DB_CONNECT_TIMEOUT` | `5` | PDO connect timeout (seconds). |
| `force_readonly` | `DB_FORCE_READONLY` | `false` | Force a read-only, safe-updates session. |
| `session_init_statements` | `DB_SESSION_INIT_STATEMENTS` | — | `;`-separated string or array of SQL to run on connect. |
| `enable_cleartext_plugin` | — | `true` | Enable the MySQL cleartext auth plugin (required for IAM). |

## Security notes

- The IAM token and `password` are never written to logs.
- TLS is on by default; point `ssl_ca` at the [Amazon RDS CA bundle](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html)
  and keep `ssl_verify` enabled in production.
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
