# laravel-rds-proxy-iam

Librería para conectar Laravel a AWS RDS Proxy usando IAM DB Auth (sin credenciales estáticas).

## Instalación

```bash
composer require paeire/laravel-rds-proxy-iam
```

## Configuración base

En `config/database.php`:

```php
'connections' => [
    'mysql' => [
        'driver' => 'mysql-iam-proxy',
        'host' => env('DB_HOST'),
        'port' => env('DB_PORT', 3306), // puerto local (túnel/proxy)
        'database' => env('DB_DATABASE'),
        'username' => env('DB_USERNAME'),

        // host/port usados para firmar el token IAM
        'token_host' => env('DB_TOKEN_HOST', env('DB_HOST')),
        'token_port' => env('DB_TOKEN_PORT', 3306),
        'aws_region' => env('AWS_REGION', 'us-east-1'),

        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],
],
```

## Orden de carga (importante)

El provider ahora registra el driver durante `register()` y engancha `beforeResolving/afterResolving` para cargarse lo antes posible.

Si tu aplicación resuelve DB muy temprano (en otro provider), registra este provider antes:

- Laravel 11: en `bootstrap/providers.php`, coloca `Paeire\RdsProxyIam\IamServiceProvider::class` al inicio.
- Laravel 10 o menor: en `config/app.php`, colócalo antes de providers que usen DB en `register()`.

## Seguridad y utilidades incluidas

- Ya no se registran `password` ni token IAM en logs.
- Validación estricta de configuración requerida.
- Soporte TLS con `ssl_ca` y verificación (`ssl_verify`, default `true`).
- `connect_timeout` (default `5`).
- `force_readonly` para reforzar sesión de solo lectura.
- `session_init_statements` para ejecutar SQL al abrir conexión.

## Opciones soportadas

Se pueden definir en la conexión de Laravel y, como fallback, por env:

- `host` / `DB_HOST`
- `port` / `DB_PORT`
- `database` / `DB_DATABASE`
- `username` / `DB_USERNAME`
- `token_host` / `DB_TOKEN_HOST`
- `token_port` / `DB_TOKEN_PORT`
- `aws_region` / `AWS_REGION`
- `ssl_ca` / `DB_SSL_CA`
- `ssl_verify` / `DB_SSL_VERIFY` (`true` por defecto)
- `connect_timeout` / `DB_CONNECT_TIMEOUT` (`5` por defecto)
- `force_readonly` / `DB_FORCE_READONLY`
- `session_init_statements` / `DB_SESSION_INIT_STATEMENTS` (string con `;` o array)
- `enable_cleartext_plugin` (`true` por defecto)
