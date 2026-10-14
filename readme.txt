=== Dox Functions ===
Contributors: doxstudio
Tags: code snippets, php, functions, doxstudio
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later

Administra funciones de PHP y scripts del sitio (píxeles, Analytics) con un panel visual con la marca Dox Studio.

== Descripción ==
Dox Functions reemplaza el archivo `functions.php` con un administrador de snippets seguro:

* Dos tipos de snippet: **PHP** (hooks, filtros, ajustes) y **HTML, JS o CSS** (el píxel de Meta, Google Analytics, una verificación de dominio), que se pega tal cual, sin envolverlo en PHP.
* Los snippets HTML eligen dónde salen: **cabecera** (`wp_head`), **inicio del body** (`wp_body_open`) o **pie de página** (`wp_footer`). Solo se imprimen en la web pública, nunca en el escritorio ni en la pantalla de acceso, y el modo seguro también los para.
* Activa o desactiva snippets con un clic (sin recargar la página).
* Define ámbito: todo el sitio, solo admin, solo frontend o **ejecutar una vez** (para migraciones y arreglos puntuales: corre en la siguiente carga y se desactiva solo).
* Ojo con "solo frontend": significa "todo lo que no es el escritorio", así que también corre en la API REST, XML-RPC, el cron de WordPress y la pantalla de acceso (`wp-login.php`). "Solo admin" corre únicamente en el escritorio con sesión iniciada (no en admin-ajax ni admin-post).
* Editor con resaltado de sintaxis PHP, guardado con Ctrl/Cmd+S y botón para validar la sintaxis sin guardar.
* Si un snippet falla, se desactiva automáticamente para evitar la pantalla blanca, y el error queda **visible** en la lista y en el editor (además de un aviso en el admin).
* Exporta e importa tus funciones en JSON para reutilizarlas entre sitios. Las funciones importadas llegan siempre desactivadas.
* Modo seguro de emergencia: agrega `?dox_safe_mode=1` a cualquier URL admin.

== Instalación ==
1. Sube la carpeta `dox-functions` a `/wp-content/plugins/`.
2. Actívalo desde el menú Plugins.
3. Ve a **Dox Plugins → Dox Functions**.

== Modo seguro ==
Si un snippet rompe el sitio:
* Visita `/wp-admin/?dox_safe_mode=1` (o añade `?dox_safe_mode=1` a cualquier URL del admin) para **detener** la ejecución de todos los snippets. La página carga sin ellos y pide confirmar con el botón "Activar Modo Seguro".
* Mientras el modo seguro esté activo verás un aviso en todo el panel de administración.
* Para **reactivar** la ejecución, entra en **Dox Plugins → Dox Functions** y pulsa "Desactivar Modo Seguro" (la reactivación está protegida con nonce y no se hace por URL).

Si el snippet rompe también la pantalla de acceso (`wp-login.php`) y no puedes entrar al admin, añade esta línea a `wp-config.php` (por FTP o el administrador de archivos del hosting), encima de `/* That's all, stop editing! */`:

`define( 'DOX_FUNCTIONS_SAFE_MODE', true );`

Con ella no se ejecuta ningún snippet, sin necesidad de iniciar sesión. Entra, arregla o desactiva el snippet culpable y luego borra la línea. Mientras esté puesta, el panel lo avisa y el botón "Desactivar Modo Seguro" no aparece.

== Errores y auto-desactivación ==
Cuando un snippet lanza un error al cargarse, el plugin lo desactiva automáticamente, registra el mensaje y te lo muestra en la lista de funciones y en el editor.

Ten en cuenta la limitación de cualquier gestor de snippets: solo se capturan los errores que ocurren **al cargar** el snippet. Si tu snippet registra un hook (por ejemplo `add_action( 'init', ... )`) y el error ocurre después, dentro de ese hook, la auto-desactivación no puede verlo: para eso está el modo seguro.

== Exportar e importar ==
Desde la lista de funciones puedes exportar todas tus funciones a un archivo JSON e importarlas en otro sitio. Por seguridad, las funciones importadas llegan **siempre desactivadas**: revísalas y actívalas una a una.

== Multisitio ==
Como los snippets se ejecutan con `eval()`, en una red multisitio solo los **Super Admins** de red pueden crear, editar, activar o ejecutar snippets, igual que WordPress reserva la edición de código a los Super Admins. Además, si el sitio define `DISALLOW_FILE_MODS` o `DISALLOW_FILE_EDIT`, la gestión de snippets queda deshabilitada. Cada sitio de la red tiene su propia lista de funciones (la tabla se crea automáticamente la primera vez que se usa en cada sitio).

== Desinstalación ==
Al desinstalar el plugin, tus funciones **se conservan** en la base de datos (son código de tu sitio; una desinstalación accidental no debe destruirlas). Solo se limpian las opciones internas. Si quieres un borrado completo, define en `wp-config.php` antes de desinstalar:

`define( 'DOX_FUNCTIONS_REMOVE_DATA', true );`

== Changelog ==

= 1.4.0 =
* Nuevo: tipo de snippet **HTML, JS o CSS**. Se pega el código tal cual (`<script>`, `<style>`, `<meta>`) y se elige si sale en la cabecera, al inicio del body o en el pie. Sirve para pasar a Dox Functions lo que hasta ahora hacía falta WPCode o el campo de cabecera del tema. No pasa por `eval()`: se imprime en la web pública y nada más.
* Nuevo: si se pega un script en un snippet PHP, el error de sintaxis dice que parece HTML y que hay que elegir ese tipo; y un snippet HTML con `<?php` dentro no se guarda (saldría como texto en la página).
* Rediseño de la pantalla con el sistema de diseño de Dox Studio: cabecera con las cifras (activas, desactivadas, con error), aviso arriba cuando alguna función se desactivó sola, filtros con contador y buscador (tecla `/`), tipo y "dónde corre" de cada función con su color, y el editor con el tipo elegido en tarjetas, las opciones de "dónde se ejecuta" explicadas una a una, barra fija con Guardar y aviso de cambios sin guardar.
* Arreglado: el código PHP se resaltaba como HTML en el editor (CodeMirror empezaba en modo HTML porque los snippets no llevan `<?php`).
* Exportar e importar llevan el tipo y la ubicación. Un JSON de versiones anteriores se importa como PHP, como siempre.
* La tabla gana las columnas `type` y `location` al actualizar; las funciones que ya había quedan como PHP sin cambiar nada.

= 1.3.0 =
* Seguridad: los snippets activos ya no se guardan en caché en `wp_options` (un transient). El plugin los lee siempre de su propia tabla, así que un fallo de otro plugin que permita escribir opciones ya no basta para ejecutar PHP. La caché antigua se borra sola al actualizar.
* Nuevo: modo seguro desde `wp-config.php` con `define( 'DOX_FUNCTIONS_SAFE_MODE', true );`. Detiene todos los snippets sin iniciar sesión, para recuperar un sitio en el que un snippet rompió la pantalla de acceso.
* Seguridad: cada acción (guardar, borrar, activar, duplicar, exportar, importar, validar, desactivar el modo seguro) lleva su propio nonce, con el id del snippet dentro cuando lo hay.
* Arreglado: importar un JSON con un campo que trae una lista en vez de texto ya no suelta el aviso "Array to string conversion"; ese campo se toma como vacío.
* Documentación: qué significa exactamente cada ámbito ("solo frontend" también corre en REST, XML-RPC, cron y la pantalla de acceso).

= 1.2.3 =
* Seguridad: `?dox_safe_mode=1` ya no guarda el modo seguro por sí solo. La página carga sin snippets y pide confirmar con el botón "Activar Modo Seguro", así un enlace puesto en otra web no puede apagarle los snippets a un administrador.

= 1.2.2 =
* Documentación: el readme seguía mandando a Herramientas → Dox Functions, y desde la 1.2.0 la pantalla está en **Dox Plugins → Dox Functions**. El plugin no cambia.

= 1.2.1 =
* Arreglado: una subida cortada (la carpeta `dox-core` a medias o un `loader.php` vacío) tumbaba el sitio entero con un error fatal. Ahora el plugin comprueba que el código compartido llegó completo antes de cargarlo; si no, la pantalla sigue siendo accesible: en el menú **Dox Plugins** si otro plugin Dox trae ese código y, si no, en Herramientas > Dox Functions.
* Mejorado: el menú **Dox Plugins** y su portada ordenan los plugins alfabéticamente, en vez de en el orden en que cargaba cada uno, y el botón de cada tarjeta dice "Abrir".

= 1.2.0 =
* Nuevo: la pantalla del plugin pasa al menú común **Dox Plugins**, junto al resto de plugins de Dox Studio, en vez de colgar de Herramientas. Cada plugin Dox lleva su copia del código compartido, así que ninguno depende de otro y solo se ejecuta la copia más nueva de las instaladas.
* La dirección antigua (Herramientas > Dox Functions) redirige sola a la pantalla nueva, por si la tenías en marcadores.

= 1.1.0 =
* Nuevo: el último error de cada snippet queda registrado y visible (lista, editor y aviso en el admin) cuando se auto-desactiva.
* Nuevo: aviso global en todo el admin mientras el modo seguro está activo.
* Nuevo: exportar/importar funciones en JSON (las importadas llegan desactivadas).
* Nuevo: ámbito "Ejecutar una vez": corre en la siguiente carga y se desactiva solo (con bloqueo atómico frente a peticiones simultáneas).
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
* Nuevo: `uninstall.php`: al desinstalar se conservan las funciones (borrado completo opcional con `DOX_FUNCTIONS_REMOVE_DATA`).

= 1.0.2 =
* Seguridad: en multisitio, la creación/edición/activación/ejecución de snippets ahora exige `is_super_admin()` en vez de `manage_options` (un admin de subsitio ya no puede lograr ejecución de código en toda la red). Se respetan `DISALLOW_FILE_MODS` y `DISALLOW_FILE_EDIT`.

= 1.0.1 =
* Seguridad: la desactivación del modo seguro pasó a un handler con nonce + comprobación de capacidad.

= 1.0.0 =
* Primera versión: gestor visual de snippets PHP con activación por clic, ámbitos, editor con resaltado, auto-desactivación de snippets con error y modo seguro de emergencia.
