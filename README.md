# PrymeOperator

Plugin de WordPress que une un gestor de archivos del servidor (sin elFinder), el organizador de la Biblioteca de Medios y una capa de operaciones auditadas y reversibles.

## Estado: fase 1 (núcleo seguro)

- **Jail de rutas** (`src/Security/Jail.php`): toda ruta se normaliza y se valida con `realpath()` contra la raíz permitida; bloquea `../`, bytes nulos y enlaces simbólicos que salen de la raíz.
- **Guard** (`src/Security/Guard.php`): el modo Servidor es solo para administradores (super admin en multisitio) y respeta `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT`.
- **Crypto** (`src/Security/Crypto.php`): cifrado autenticado con libsodium para tokens de la nube.
- **Auditoría** (`src/Operator/Audit.php`): quién, qué, cuándo y desde qué IP.
- **Explorador del servidor de solo lectura** con vista previa de texto, en *PrymeOperator* en el menú del admin.
- **API REST** en `/wp-json/prymeoperator/v1/` (`server/list`, `server/file`, `audit`).

## Configuración

La raíz por defecto es la instalación de WordPress (`ABSPATH`). Para cambiarla:

```php
define( 'PRYMEOPERATOR_ROOT', '/ruta/permitida' );
```

o con el filtro `prymeoperator_server_root`.

## Pruebas

```bash
php tests/run.php
```

Requiere PHP 8.1+ con las extensiones `sodium` y `mbstring`.
