# PrymeOperator

Gestor de archivos profesional para WordPress, sin elFinder: explorador del servidor, editor de código con red de seguridad, papelera, versiones y registro de auditoría. Pensado para superar a File Manager Advanced en seguridad y en experiencia de uso.

## Funciones (v0.2.0)

**Archivos**
- Árbol de carpetas, vista de lista y de cuadrícula con miniaturas, migas de pan, ordenar por columnas.
- Selección múltiple (Ctrl/Shift), menú contextual, arrastrar y soltar para mover y para subir desde el escritorio.
- Atajos: Ctrl+C/X/V, Supr, F2, Intro, Retroceso, Ctrl+A, Ctrl+F, F5, flechas.
- Subida por partes (archivos grandes sin tocar `php.ini`) con panel de progreso.
- Crear, renombrar, duplicar, copiar, mover, comprimir y descomprimir ZIP, descargar (carpetas como ZIP), permisos (chmod) visuales, propiedades con tamaño de carpeta.
- Búsqueda por nombre y dentro del contenido de archivos de texto.
- Vista previa de imágenes, PDF, vídeo y audio con navegación anterior/siguiente.

**Red de seguridad**
- Papelera real: borrar nunca destruye; botón «Deshacer» y restauración desde la pestaña Papelera.
- Editor CodeMirror (el que trae WordPress) con resaltado para PHP, JS, CSS, HTML, SQL, Markdown…
- Revisión de sintaxis PHP antes de guardar.
- Versiones de cada archivo editado, recuperables desde el editor.
- Restauración automática: tras guardar un PHP se carga el sitio; si da error, se vuelve a la versión anterior.

**Seguridad**
- Jaula de rutas con `realpath()`: bloquea `../`, bytes nulos, enlaces simbólicos que salen de la raíz y la carpeta privada del plugin.
- ZIP: protección contra *zip slip*, enlaces simbólicos, bombas de descompresión y tipos bloqueados.
- Tipos bloqueados comprobando todas las extensiones (`shell.php.jpg` cuenta como PHP).
- Descargas con `nosniff` y CSP *sandbox*; solo se muestran en línea tipos multimedia seguros.
- Respeta `DISALLOW_FILE_MODS` y `DISALLOW_FILE_EDIT`.

**Administración**
- Panel con espacio en disco, papelera, límites, estado del sistema y actividad reciente.
- Registro de auditoría filtrable (usuario, acción, ruta, IP).
- Acceso para otros roles: carpeta propia y permisos a elegir; nunca código, chmod ni tipos bloqueados.
- Ajustes: carpeta raíz, tema claro/oscuro/automático, vista por defecto, archivos ocultos, límite de subida, tipos bloqueados, versiones, días de papelera, aviso por correo al borrar, borrado de datos al desinstalar.
- Interfaz en inglés y español (es_ES y variantes de Latinoamérica).

## Configuración

La raíz por defecto es la instalación de WordPress (`ABSPATH`). Para fijarla desde `wp-config.php`:

```php
define( 'PRYMEOPERATOR_ROOT', '/ruta/permitida' );
```

Filtros: `prymeoperator_server_root`, `prymeoperator_admin_capability`, `prymeoperator_vault_dir`.

La papelera, las versiones y las subidas temporales viven en `wp-content/prymeoperator-private/`, protegida contra acceso web.

## Desarrollo

```bash
php tests/run.php          # pruebas unitarias (Jail, Operations, Crypto, Explorer)
python3 bin/i18n/build.py  # regenerar traducciones desde bin/i18n/es.py
```

Requiere PHP 8.1+ con `sodium`, `mbstring` y `zip`. WordPress 6.4+.
