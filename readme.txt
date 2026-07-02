=== Dox Functions ===
Contributors: doxstudio
Tags: code snippets, php, functions, doxstudio
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Administra snippets de PHP personalizados con un panel visual con la marca Dox Studio.

== Descripción ==
Dox Functions reemplaza el archivo `functions.php` con un administrador de snippets seguro:

* Activa o desactiva snippets con un clic (sin recargar la página).
* Define ámbito: todo el sitio, solo admin, solo frontend o **ejecutar una vez** (para migraciones y arreglos puntuales: corre en la siguiente carga y se desactiva solo).
* Editor con resaltado de sintaxis PHP, guardado con Ctrl/Cmd+S y botón para validar la sintaxis sin guardar.
* Si un snippet falla, se desactiva automáticamente para evitar la pantalla blanca, y el error queda **visible** en la lista y en el editor (además de un aviso en el admin).
* Exporta e importa tus funciones en JSON para reutilizarlas entre sitios. Las funciones importadas llegan siempre desactivadas.
* Modo seguro de emergencia: agrega `?dox_safe_mode=1` a cualquier URL admin.

== Instalación ==
1. Sube la carpeta `dox-functions` a `/wp-content/plugins/`.
2. Actívalo desde el menú Plugins.
3. Ve a **Herramientas → Dox Functions**.

== Modo seguro ==
Si un snippet rompe el sitio:
* Visita `/wp-admin/?dox_safe_mode=1` (o añade `?dox_safe_mode=1` a cualquier URL del admin) para **detener** la ejecución de todos los snippets.
* Mientras el modo seguro esté activo verás un aviso en todo el panel de administración.
* Para **reactivar** la ejecución, entra en **Herramientas → Dox Functions** y pulsa "Desactivar Modo Seguro" (la reactivación está protegida con nonce y no se hace por URL).

== Errores y auto-desactivación ==
Cuando un snippet lanza un error al cargarse, el plugin lo desactiva automáticamente, registra el mensaje y te lo muestra en la lista de funciones y en el editor.

Ten en cuenta la limitación de cualquier gestor de snippets: solo se capturan los errores que ocurren **al cargar** el snippet. Si tu snippet registra un hook (por ejemplo `add_action( 'init', ... )`) y el error ocurre después, dentro de ese hook, la auto-desactivación no puede verlo — para eso está el modo seguro.

== Exportar e importar ==
Desde la lista de funciones puedes exportar todas tus funciones a un archivo JSON e importarlas en otro sitio. Por seguridad, las funciones importadas llegan **siempre desactivadas**: revísalas y actívalas una a una.

== Multisitio ==
Como los snippets se ejecutan con `eval()`, en una red multisitio solo los **Super Admins** de red pueden crear, editar, activar o ejecutar snippets — igual que WordPress reserva la edición de código a los Super Admins. Además, si el sitio define `DISALLOW_FILE_MODS` o `DISALLOW_FILE_EDIT`, la gestión de snippets queda deshabilitada. Cada sitio de la red tiene su propia lista de funciones (la tabla se crea automáticamente la primera vez que se usa en cada sitio).

== Desinstalación ==
Al desinstalar el plugin, tus funciones **se conservan** en la base de datos (son código de tu sitio; una desinstalación accidental no debe destruirlas). Solo se limpian las opciones internas. Si quieres un borrado completo, define en `wp-config.php` antes de desinstalar:

`define( 'DOX_FUNCTIONS_REMOVE_DATA', true );`

== Changelog ==

= 1.1.0 =
* Nuevo: el último error de cada snippet queda registrado y visible (lista, editor y aviso en el admin) cuando se auto-desactiva.
* Nuevo: aviso global en todo el admin mientras el modo seguro está activo.
* Nuevo: exportar/importar funciones en JSON (las importadas llegan desactivadas).
* Nuevo: ámbito "Ejecutar una vez" — corre en la siguiente carga y se desactiva solo (con bloqueo atómico frente a peticiones simultáneas).
* Nuevo: botón "Validar sintaxis" en el editor (sin guardar), guardado con Ctrl/Cmd+S, y duplicar funciones desde la lista.
* Arreglado: si la validación de sintaxis fallaba al guardar, se perdía el código escrito; ahora el formulario se repuebla con lo enviado.
* Arreglado: editar una función ya borrada fingía guardar; ahora se guarda como función nueva (o se avisa de que no existe).
* Arreglado: el número de línea de los errores de sintaxis salía desplazado.
* Arreglado: la lista mostraba el ámbito sin traducir.
* Arreglado: en multisitio la tabla no se creaba para los subsitios; ahora se crea automáticamente (con versión de esquema para futuras actualizaciones, ya que los updates no ejecutan el hook de activación).
* Mejorado: activar/desactivar desde la lista sin recargar la página (con fallback sin JavaScript).
* Mejorado: aviso al salir del editor con cambios sin guardar.
* Mejorado: accesibilidad (interruptores con `role="switch"` y foco visible, etiquetas asociadas a campos, contraste conforme a WCAG AA) y diseño adaptable en pantallas pequeñas.
* Mejorado: la fecha de "Actualizado" respeta el formato configurado en WordPress y ya no cambia con un simple activar/desactivar.
* Mejorado: el snippet se ejecuta aislado del runner (no puede tocar `$this` por accidente) y se eliminaron scripts encolados redundantes y la dependencia de jQuery.
* Nuevo: `uninstall.php` — al desinstalar se conservan las funciones (borrado completo opcional con `DOX_FUNCTIONS_REMOVE_DATA`).

= 1.0.2 =
* Seguridad: en multisitio, la creación/edición/activación/ejecución de snippets ahora exige `is_super_admin()` en vez de `manage_options` (un admin de subsitio ya no puede lograr ejecución de código en toda la red). Se respetan `DISALLOW_FILE_MODS` y `DISALLOW_FILE_EDIT`.

= 1.0.1 =
* Seguridad: la desactivación del modo seguro pasó a un handler con nonce + comprobación de capacidad.

= 1.0.0 =
* Primera versión: gestor visual de snippets PHP con activación por clic, ámbitos, editor con resaltado, auto-desactivación de snippets con error y modo seguro de emergencia.
