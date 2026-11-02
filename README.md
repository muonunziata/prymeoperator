# PrymeOperator

Gestor de archivos profesional para WordPress, sin elFinder: explorador del servidor, editor de código con red de seguridad, papelera, versiones y registro de auditoría. Pensado para superar a File Manager Advanced en seguridad y en experiencia de uso.

## Funciones (v0.2.2)

**Archivos**
- Árbol de carpetas, vista de lista y de cuadrícula con miniaturas, migas de pan, ordenar por columnas.
- Selección múltiple (Ctrl/Shift), menú contextual, arrastrar y soltar para mover y para subir desde el escritorio.
- Atajos: Ctrl+C/X/V, Supr, F2, Intro, Retroceso, Ctrl+A, Ctrl+F, F5, flechas.
- Subida por partes (archivos grandes sin tocar `php.ini`) con panel de progreso.
- Crear, renombrar, duplicar, copiar, mover, comprimir y descomprimir ZIP, descargar (carpetas como ZIP), permisos (chmod) visuales, propiedades con tamaño de carpeta.
- Búsqueda por nombre y dentro del contenido de archivos de texto.
- Vista previa de imágenes, PDF, vídeo y audio con navegación anterior/siguiente.

**Biblioteca (carpetas virtuales)**
- Barra de carpetas en Medios (cuadrícula y lista), Páginas, Entradas y cualquier otro tipo de contenido que actives en Ajustes.
- Arrastrar filas o miniaturas a una carpeta; varias a la vez marcándolas (o con «Selección múltiple» en la cuadrícula). Arrastrar carpetas para anidarlas.
- Subcarpetas, renombrar en línea, buscar carpeta, contadores, vista «Sin carpeta» y barra plegable que acompaña al scroll.
- Lo que se sube con una carpeta abierta en Medios entra en esa carpeta.
- Borrar una carpeta nunca borra contenido: sus elementos quedan sin carpeta y sus subcarpetas suben un nivel.
- Editores y administradores organizan las carpetas; los autores archivan sus propios elementos. Todo queda en el registro de actividad.
- Importación en un clic de las carpetas de MediaFlow Pro.

**Copias de seguridad**
- Base de datos y/o archivos (wp-content o todo WordPress) en un ZIP, por pasos cortos para que los sitios grandes no se corten.
- Descargar, borrar y restaurar la base de datos desde la pestaña Copias de seguridad. Antes de restaurar se hace una copia; cada tabla se reconstruye aparte y se cambia de golpe; el plugin sigue activo y no se cierra tu sesión.
- Copias automáticas diarias o semanales, de noche, conservando las que elijas.

**En el sitio**
- Bloque «Carpeta» y shortcode `[prymeoperator_folder id="12" view="grid|list" columns="3" subfolders="yes"]`: muestra una carpeta de Medios como galería o como lista de descargas. Los adjuntos de entradas privadas o en borrador no se muestran.

**WP-CLI**
- `wp prymeoperator backup create|list|restore|delete`, `wp prymeoperator trash list|restore|empty`, `wp prymeoperator folders list|import-mediaflow`, `wp prymeoperator audit`.

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
- Arregla el hueco que dejan las barras de carpetas flotantes en Entradas, Páginas y Medios, y hace que la barra acompañe al scroll (filtro `prymeoperator_fix_list_layout` para desactivarlo).
- Interfaz en inglés y español (es_ES y variantes de Latinoamérica).

## Configuración

La raíz por defecto es la instalación de WordPress (`ABSPATH`). Para fijarla desde `wp-config.php`:

```php
define( 'PRYMEOPERATOR_ROOT', '/ruta/permitida' );
```

Filtros: `prymeoperator_server_root`, `prymeoperator_admin_capability`, `prymeoperator_vault_dir`, `prymeoperator_library_can_organise`, `prymeoperator_backup_exclude`, `prymeoperator_fix_list_layout`.

La papelera, las versiones y las subidas temporales viven en `wp-content/prymeoperator-private/`, protegida contra acceso web.

## Desarrollo

```bash
php tests/run.php          # pruebas unitarias (Jail, Operations, Crypto, Explorer, Tree, Papelera, Versiones, Vault, FileScanner)
python3 bin/i18n/build.py  # regenerar traducciones desde bin/i18n/es.py
```

Requiere PHP 8.1+ con `sodium`, `mbstring` y `zip`. WordPress 6.4+.
