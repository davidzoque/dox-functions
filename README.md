# Dox Functions

Administra snippets de PHP personalizados desde un panel visual con la marca Dox Studio, **sin tocar `functions.php`**. Activa o desactiva funciones con un clic.

## Características

- **Activa/desactiva** snippets con un clic (sin recargar la página)
- **Ámbitos**: todo el sitio, solo admin, solo frontend, o **"ejecutar una vez"** (corre en la siguiente carga y se desactiva solo — ideal para migraciones y arreglos puntuales)
- **Editor** con resaltado de sintaxis PHP, guardado con `Ctrl/Cmd+S` y botón para validar la sintaxis sin guardar
- **Auto-desactivación segura**: si un snippet falla al cargar, se desactiva solo (evita la pantalla blanca) y el error queda visible en la lista, el editor y un aviso del admin
- **Exportar/importar** funciones en JSON para reutilizarlas entre sitios (las importadas llegan siempre desactivadas)
- **Modo seguro de emergencia**: añade `?dox_safe_mode=1` a cualquier URL del admin para detener todos los snippets

## Seguridad

Como los snippets se ejecutan con `eval()`, en una red **multisitio** solo los **Super Admins** de red pueden crear, editar, activar o ejecutar snippets — igual que WordPress reserva la edición de código a los Super Admins. Además, si el sitio define `DISALLOW_FILE_MODS` o `DISALLOW_FILE_EDIT`, la gestión de snippets queda deshabilitada.

## Requisitos

- WordPress 5.5+
- PHP 7.4+

## Instalación

1. Descarga el ZIP de la [última release](https://github.com/davidzoque/dox-functions/releases/latest) (`dox-functions.zip`).
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**, elige el ZIP y actívalo.
3. Ve a **Herramientas → Dox Functions**.

## Modo seguro

Si un snippet rompe el sitio, visita `/wp-admin/?dox_safe_mode=1` (o añade `?dox_safe_mode=1` a cualquier URL del admin) para **detener** la ejecución de todos los snippets. Para **reactivar** la ejecución, entra en **Herramientas → Dox Functions** y pulsa "Desactivar Modo Seguro" (protegido con nonce; no se hace por URL).

## Actualizaciones automáticas

Incluye [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) apuntando a las **releases** de este repositorio (público), así que **no requiere configuración ni token**: las actualizaciones aparecen en la pantalla normal de plugins de WordPress.

## Licencia

GPL-2.0-or-later
